<?php

namespace App\Services\Billing;

use App\Domain\Exceptions\BillingReconciliationException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 8 / slice 4.1: the billing statement. A READ over the append-only credit ledger and the immutable sale snapshots;
 * it stores nothing and is recomputed on every call (like the readings, invariant #40), so a range re-run later shows the current net
 * with late voids and refunds as flagged adjustments.
 *
 * Layout: Account (the employee: code, name, type, from the ledger's snapshots) -> Meal type -> Item/dish, with sold, returned and net
 * quantity and amount, subtotals per meal type and per account, and a grand total. The rules (BILL-001..BILL-003):
 *
 *   Meal type of a line  = the meal's name snapshot ("Standard Meal") for a meal line AND for its add-on lines (rice sits under the meal it
 *                          was bought with); for a plain product the menu-group path snapshot ("Lunch > Drinks") when it sat in a
 *                          menu group; otherwise "Other items".
 *   Item of a line       = a meal line: the chosen dishes' name snapshots joined with " + " (a Combo is "Beef nilaga + Pinakbet"; the
 *                          selection snapshots, falling back to the line's name snapshot); an add-on line: its option label snapshot
 *                          ("Whole rice"), counted as an item of its own; a plain product: its product name snapshot.
 *   Amounts              = sale_items.net_line_amount (after every discount; the lines of a sale add up to its grand total, DISC-006,
 *                          which is the CHARGE amount). A void reverses every line of the sale; a refund reverses the refund_items it
 *                          names (the amounts the refund calculator recorded, which add up to the REVERSAL).
 *   Range                = a charge belongs by the Manila calendar date of its sale; a reversal belongs to the range of the charge it
 *                          reverses (the original period), and counts only if it was recorded at or before the as-of instant.
 *
 * Reconciliation (BILL-001): the statement's net must equal the ledger's SUM(CHARGE) - SUM(REVERSAL) for the same sales, computed by a
 * separate query; for a named cut-off it must also equal the same sum taken by the entries' stored `period_start`. A mismatch raises
 * BillingReconciliationException and nothing is shown.
 *
 * The caller runs it in a REPEATABLE READ transaction where it can, so every query here sees one snapshot.
 */
final class BillingStatementBuilder
{
    public const OTHER_ITEMS = 'Other items';

    private const CHUNK = 1000;

    /** @return array<string, mixed> */
    public function build(string $storeId, string $partyName, BillingCriteria $criteria, ?CarbonImmutable $generatedAt = null): array
    {
        $charges = $this->charges($storeId, $criteria);
        $saleIds = $charges->pluck('sale_id')->all();

        $lines = $this->saleLines($saleIds);
        $reversals = $this->reversals($saleIds, $criteria);

        $accounts = [];
        foreach ($charges as $charge) {
            $accounts[$charge->employee_id] ??= $this->newAccount($charge);
            $accounts[$charge->employee_id]['sale_ids'][$charge->sale_id] = true;
            $accounts[$charge->employee_id]['sold'] = bcadd($accounts[$charge->employee_id]['sold'], (string) $charge->amount, 2);
            foreach ($lines['bySale'][$charge->sale_id] ?? [] as $line) {
                $this->addLine($accounts[$charge->employee_id], $line, sold: true);
            }
        }

        $chargeBySale = $charges->keyBy('sale_id');
        $adjustments = [];
        foreach ($reversals as $reversal) {
            $charge = $chargeBySale[$reversal->sale_id];
            $adjustment = $this->adjustment($reversal, $charge, $lines);
            $adjustments[] = $adjustment;
            $account = &$accounts[$charge->employee_id];
            $account['returned'] = bcadd($account['returned'], $adjustment['amount'], 2);
            foreach ($adjustment['lines'] as $line) {
                $this->addLine($account, $line, sold: false);
            }
            unset($account);
        }

        $statement = $this->assemble($partyName, $criteria, $accounts, $adjustments, $generatedAt ?? CarbonImmutable::now());
        $this->reconcile($storeId, $criteria, $statement);

        return $statement;
    }

    /** @return Collection<int, object> */
    private function charges(string $storeId, BillingCriteria $criteria): Collection
    {
        return $this->chargeQuery($storeId, $criteria)
            ->leftJoin('invoices as i', 'i.sale_id', '=', 's.id')
            ->leftJoin('sale_slips as sl', 'sl.sale_id', '=', 's.id')
            ->orderBy('e.employee_code')->orderBy('s.sold_at')->orderBy('e.id')
            ->get([
                'e.id as entry_id', 'e.employee_id', 'e.employee_code', 'e.employee_name', 'e.employee_type', 'e.amount', 'e.sale_id', 's.sold_at',
                DB::raw('COALESCE(i.invoice_number, sl.slip_number) as document_reference'),
            ]);
    }

    /** The CHARGE entries in range; also the one definition of "the sales of this statement" the reconciliation re-uses. */
    private function chargeQuery(string $storeId, BillingCriteria $criteria): Builder
    {
        return DB::table('employee_credit_entries as e')
            ->join('sales as s', 's.id', '=', 'e.sale_id')
            ->where('e.store_id', $storeId)
            ->where('e.entry_type', 'CHARGE')
            ->where('e.billing_party_id', $criteria->partyId)
            ->where('e.recorded_at', '<=', $criteria->asOf->toIso8601String())
            ->whereRaw('(s.sold_at AT TIME ZONE ?)::date BETWEEN ? AND ?', [(string) config('app.timezone'), $criteria->from, $criteria->to])
            ->when($criteria->employeeType, fn ($query, $type) => $query->where('e.employee_type', $type))
            ->when($criteria->employeeId, fn ($query, $id) => $query->where('e.employee_id', $id));
    }

    /**
     * Every sale line of the charged sales, classified into (meal type, kind, item).
     *
     * @param  list<string>  $saleIds
     * @return array{bySale: array<string, list<array<string, mixed>>>, byId: array<string, array<string, mixed>>}
     */
    private function saleLines(array $saleIds): array
    {
        $bySale = [];
        $byId = [];

        foreach (array_chunk($saleIds, self::CHUNK) as $chunk) {
            $items = DB::table('sale_items')->whereIn('sale_id', $chunk)->orderBy('sale_id')->orderBy('line_number')
                ->get(['id', 'sale_id', 'line_number', 'product_name_snapshot', 'quantity', 'net_line_amount', 'menu_group_path_snapshot', 'meal_name_snapshot', 'parent_line_number']);
            $selections = DB::table('sale_item_selections')->whereIn('sale_item_id', $items->pluck('id')->all())
                ->orderBy('sale_item_id')->orderBy('sort_order')->orderBy('created_at')->orderBy('id')
                ->get(['sale_item_id', 'option_label_snapshot', 'dish_name_snapshot'])->groupBy('sale_item_id');

            foreach ($items as $item) {
                $line = $this->classify($item, $selections[$item->id] ?? collect());
                $bySale[$item->sale_id][] = $line;
                $byId[$item->id] = $line;
            }
        }

        return ['bySale' => $bySale, 'byId' => $byId];
    }

    /**
     * @param  Collection<int, object>  $selections
     * @return array<string, mixed>
     */
    private function classify(object $item, Collection $selections): array
    {
        $mealName = $item->meal_name_snapshot;
        $mealType = $mealName !== null && $mealName !== '' ? $mealName : (($item->menu_group_path_snapshot ?? '') !== '' ? $item->menu_group_path_snapshot : self::OTHER_ITEMS);

        if ($item->parent_line_number !== null) {
            $kind = 'ADDON';
            $label = $selections->pluck('option_label_snapshot')->filter()->first() ?? $item->product_name_snapshot;
        } elseif ($mealName !== null && $mealName !== '') {
            $kind = 'DISH';
            $dishes = $selections->pluck('dish_name_snapshot')->filter()->all();
            $label = $dishes === [] ? $item->product_name_snapshot : implode(' + ', $dishes);
        } else {
            $kind = 'PRODUCT';
            $label = $item->product_name_snapshot;
        }

        return [
            'sale_item_id' => $item->id,
            'meal_type' => $mealType,
            'kind' => $kind,
            'item' => $label,
            'quantity' => $this->quantity((string) $item->quantity),
            'amount' => bcadd((string) $item->net_line_amount, '0', 2),
        ];
    }

    /**
     * The REVERSAL entries of the charged sales recorded at or before the as-of instant, oldest first, with the reason and the lines they cover.
     *
     * @param  list<string>  $saleIds
     * @return list<object>
     */
    private function reversals(array $saleIds, BillingCriteria $criteria): array
    {
        $rows = [];
        foreach (array_chunk($saleIds, self::CHUNK) as $chunk) {
            foreach (DB::table('employee_credit_entries')->where('entry_type', 'REVERSAL')->whereIn('sale_id', $chunk)
                ->where('recorded_at', '<=', $criteria->asOf->toIso8601String())
                ->get(['id', 'sale_id', 'refund_id', 'void_id', 'amount', 'recorded_at']) as $row) {
                $rows[] = $row;
            }
        }
        usort($rows, fn ($a, $b) => [$a->recorded_at, $a->id] <=> [$b->recorded_at, $b->id]);

        $refundIds = array_values(array_filter(array_map(fn ($row) => $row->refund_id, $rows)));
        $voidIds = array_values(array_filter(array_map(fn ($row) => $row->void_id, $rows)));
        $refundItems = [];
        $reasons = [];
        foreach (array_chunk($refundIds, self::CHUNK) as $chunk) {
            foreach (DB::table('refund_items')->whereIn('refund_id', $chunk)->orderBy('id')->get(['refund_id', 'sale_item_id', 'quantity_returned', 'unit_refund_amount']) as $item) {
                $refundItems[$item->refund_id][] = $item;
            }
            foreach (DB::table('refunds')->whereIn('id', $chunk)->get(['id', 'reason']) as $refund) {
                $reasons['refund:'.$refund->id] = $refund->reason;
            }
        }
        foreach (array_chunk($voidIds, self::CHUNK) as $chunk) {
            foreach (DB::table('voids')->whereIn('id', $chunk)->get(['id', 'reason']) as $void) {
                $reasons['void:'.$void->id] = $void->reason;
            }
        }

        foreach ($rows as $row) {
            $row->reason = $row->refund_id !== null ? ($reasons['refund:'.$row->refund_id] ?? null) : ($reasons['void:'.$row->void_id] ?? null);
            $row->refund_items = $row->refund_id !== null ? ($refundItems[$row->refund_id] ?? []) : null;
        }

        return $rows;
    }

    /**
     * @param  array{bySale: array<string, list<array<string, mixed>>>, byId: array<string, array<string, mixed>>}  $lines
     * @return array<string, mixed>
     */
    private function adjustment(object $reversal, object $charge, array $lines): array
    {
        $covered = [];
        if ($reversal->void_id !== null) {
            foreach ($lines['bySale'][$reversal->sale_id] ?? [] as $line) {
                $covered[] = ['meal_type' => $line['meal_type'], 'kind' => $line['kind'], 'item' => $line['item'], 'quantity' => $line['quantity'], 'amount' => $line['amount']];
            }
        } else {
            foreach ($reversal->refund_items as $refundItem) {
                $line = $lines['byId'][$refundItem->sale_item_id];
                $covered[] = [
                    'meal_type' => $line['meal_type'], 'kind' => $line['kind'], 'item' => $line['item'],
                    'quantity' => $this->quantity((string) $refundItem->quantity_returned),
                    'amount' => bcadd((string) $refundItem->unit_refund_amount, '0', 2),
                ];
            }
        }

        $sum = array_reduce($covered, fn (string $carry, array $line) => bcadd($carry, $line['amount'], 2), '0.00');
        $amount = bcadd((string) $reversal->amount, '0', 2);
        if (bccomp($sum, $amount, 2) !== 0) {
            throw BillingReconciliationException::mismatch('the lines of an adjustment do not add up to its ledger entry', [
                'entry_id' => $reversal->id, 'ledger_amount' => $amount, 'lines_amount' => $sum,
            ]);
        }

        return [
            'entry_id' => $reversal->id,
            'type' => $reversal->void_id !== null ? 'VOID' : 'REFUND',
            'recorded_at' => CarbonImmutable::parse($reversal->recorded_at)->toIso8601String(),
            'sale_id' => $reversal->sale_id,
            'document_reference' => $charge->document_reference,
            'sold_on' => CarbonImmutable::parse($charge->sold_at)->setTimezone((string) config('app.timezone'))->toDateString(),
            'employee_id' => $charge->employee_id,
            'employee_code' => $charge->employee_code,
            'employee_name' => $charge->employee_name,
            'employee_type' => $charge->employee_type,
            'amount' => $amount,
            'reason' => $reversal->reason,
            'lines' => $covered,
        ];
    }

    /** @return array<string, mixed> */
    private function newAccount(object $charge): array
    {
        return [
            'employee_id' => $charge->employee_id,
            'employee_code' => $charge->employee_code,
            'employee_name' => $charge->employee_name,
            'employee_type' => $charge->employee_type,
            'sale_ids' => [],
            'sold' => '0.00',
            'returned' => '0.00',
            'types' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $account
     * @param  array<string, mixed>  $line
     */
    private function addLine(array &$account, array $line, bool $sold): void
    {
        $type = &$account['types'][$line['meal_type']];
        $type ??= ['sold' => '0.00', 'returned' => '0.00', 'items' => []];
        $key = $line['kind']."\0".$line['item'];
        $item = &$type['items'][$key];
        $item ??= ['item' => $line['item'], 'kind' => $line['kind'], 'qty_sold' => '0.000', 'sold' => '0.00', 'qty_returned' => '0.000', 'returned' => '0.00'];

        $qty = $sold ? 'qty_sold' : 'qty_returned';
        $money = $sold ? 'sold' : 'returned';
        $item[$qty] = bcadd($item[$qty], $line['quantity'], 3);
        $item[$money] = bcadd($item[$money], $line['amount'], 2);
        $type[$money] = bcadd($type[$money], $line['amount'], 2);
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $adjustments
     * @return array<string, mixed>
     */
    private function assemble(string $partyName, BillingCriteria $criteria, array $accounts, array $adjustments, CarbonImmutable $generatedAt): array
    {
        $out = [];
        $totals = ['sold' => '0.00', 'returned' => '0.00', 'items_net' => '0.00', 'sales' => 0];
        $byType = [];

        foreach ($accounts as $account) {
            $types = [];
            $names = array_keys($account['types']);
            usort($names, fn ($a, $b) => [$a === self::OTHER_ITEMS ? 1 : 0, mb_strtolower($a)] <=> [$b === self::OTHER_ITEMS ? 1 : 0, mb_strtolower($b)]);
            foreach ($names as $name) {
                $type = $account['types'][$name];
                $items = array_values($type['items']);
                usort($items, fn ($a, $b) => [['DISH' => 0, 'ADDON' => 2, 'PRODUCT' => 1][$a['kind']], mb_strtolower($a['item'])] <=> [['DISH' => 0, 'ADDON' => 2, 'PRODUCT' => 1][$b['kind']], mb_strtolower($b['item'])]);
                $types[] = [
                    'meal_type' => $name,
                    'sold_amount' => $type['sold'],
                    'returned_amount' => $type['returned'],
                    'net_amount' => bcsub($type['sold'], $type['returned'], 2),
                    'items' => array_map(fn (array $item) => [
                        'item' => $item['item'],
                        'kind' => $item['kind'],
                        'quantity_sold' => $item['qty_sold'],
                        'sold_amount' => $item['sold'],
                        'quantity_returned' => $item['qty_returned'],
                        'returned_amount' => $item['returned'],
                        'quantity_net' => bcsub($item['qty_sold'], $item['qty_returned'], 3),
                        'net_amount' => bcsub($item['sold'], $item['returned'], 2),
                    ], $items),
                ];
            }

            $net = bcsub($account['sold'], $account['returned'], 2);
            $out[] = [
                'employee_id' => $account['employee_id'],
                'employee_code' => $account['employee_code'],
                'employee_name' => $account['employee_name'],
                'employee_type' => $account['employee_type'],
                'sale_count' => count($account['sale_ids']),
                'sold_amount' => $account['sold'],
                'returned_amount' => $account['returned'],
                'net_amount' => $net,
                'meal_types' => $types,
            ];
            $totals['sold'] = bcadd($totals['sold'], $account['sold'], 2);
            $totals['returned'] = bcadd($totals['returned'], $account['returned'], 2);
            $totals['sales'] += count($account['sale_ids']);
            foreach ($types as $type) {
                $totals['items_net'] = bcadd($totals['items_net'], $type['net_amount'], 2);
            }
            $byType[$account['employee_type']] ??= ['employee_count' => 0, 'net_amount' => '0.00'];
            $byType[$account['employee_type']]['employee_count']++;
            $byType[$account['employee_type']]['net_amount'] = bcadd($byType[$account['employee_type']]['net_amount'], $net, 2);
        }
        ksort($byType);

        return [
            'version' => 1,
            'party' => ['id' => $criteria->partyId, 'name' => $partyName],
            'criteria' => $criteria->toArray(),
            'generated_at' => $generatedAt->toIso8601String(),
            'accounts' => $out,
            'adjustments' => $adjustments,
            'totals' => [
                'sold_amount' => $totals['sold'],
                'returned_amount' => $totals['returned'],
                'net_amount' => bcsub($totals['sold'], $totals['returned'], 2),
                'employee_count' => count($out),
                'sale_count' => $totals['sales'],
                'adjustment_count' => count($adjustments),
                'by_employee_type' => $byType,
            ],
            'reconciliation' => ['ledger_net' => '0.00', 'statement_net' => bcsub($totals['sold'], $totals['returned'], 2), 'items_net' => $totals['items_net'], 'matches' => false],
            'comparison' => null,
        ];
    }

    /**
     * BILL-001. Three independent figures must agree: the accounts' total, the sum of every item line, and the ledger's CHARGE minus REVERSAL
     * for the same sales read straight from employee_credit_entries; for a named cut-off, the ledger's own `period_start` grouping too.
     *
     * @param  array<string, mixed>  $statement
     */
    private function reconcile(string $storeId, BillingCriteria $criteria, array &$statement): void
    {
        $ledger = $this->ledgerNet($storeId, $criteria);
        $statementNet = $statement['totals']['net_amount'];
        $itemsNet = $statement['reconciliation']['items_net'];

        if (bccomp($ledger, $statementNet, 2) !== 0 || bccomp($itemsNet, $statementNet, 2) !== 0) {
            throw BillingReconciliationException::mismatch('the total differs from the ledger net', [
                'ledger_net' => $ledger, 'statement_net' => $statementNet, 'items_net' => $itemsNet,
            ]);
        }

        if ($criteria->cutoffMonth !== null) {
            $byPeriod = $this->ledgerNetByPeriod($storeId, $criteria);
            if (bccomp($byPeriod, $statementNet, 2) !== 0) {
                throw BillingReconciliationException::mismatch('the total differs from the ledger net of the cut-off period', [
                    'ledger_period_net' => $byPeriod, 'statement_net' => $statementNet,
                ]);
            }
        }

        $statement['reconciliation'] = ['ledger_net' => $ledger, 'statement_net' => $statementNet, 'items_net' => $itemsNet, 'matches' => true];
    }

    /** SUM(CHARGE) - SUM(REVERSAL) of the entries of the statement's sales, straight from the ledger, honouring the as-of instant. */
    private function ledgerNet(string $storeId, BillingCriteria $criteria): string
    {
        $sales = $this->chargeQuery($storeId, $criteria)->select('e.sale_id');
        $net = DB::table('employee_credit_entries as l')
            ->where('l.store_id', $storeId)
            ->where('l.recorded_at', '<=', $criteria->asOf->toIso8601String())
            ->whereIn('l.sale_id', $sales)
            ->selectRaw("COALESCE(SUM(CASE WHEN l.entry_type = 'CHARGE' THEN l.amount ELSE -l.amount END), 0) AS net")
            ->value('net');

        return bcadd((string) $net, '0', 2);
    }

    /** The same net by the entries' stored cut-off period (a named cut-off only), the figure the credit limit is checked against. */
    private function ledgerNetByPeriod(string $storeId, BillingCriteria $criteria): string
    {
        $net = DB::table('employee_credit_entries as l')
            ->where('l.store_id', $storeId)
            ->where('l.billing_party_id', $criteria->partyId)
            ->where('l.period_start', $criteria->from)
            ->where('l.recorded_at', '<=', $criteria->asOf->toIso8601String())
            ->when($criteria->employeeType, fn ($query, $type) => $query->where('l.employee_type', $type))
            ->when($criteria->employeeId, fn ($query, $id) => $query->where('l.employee_id', $id))
            ->selectRaw("COALESCE(SUM(CASE WHEN l.entry_type = 'CHARGE' THEN l.amount ELSE -l.amount END), 0) AS net")
            ->value('net');

        return bcadd((string) $net, '0', 2);
    }

    private function quantity(string $value): string
    {
        return bcadd($value, '0', 3);
    }
}
