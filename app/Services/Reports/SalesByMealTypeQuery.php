<?php

namespace App\Services\Reports;

use App\Models\FiscalDay;
use App\Services\Sales\SaleLineClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 amendment 2026-10-26 (slice 4.4), invariant EOD-001: sales by MEAL TYPE and by DISH / ITEM for one or more business days, the single
 * query behind both the end-of-day report (frozen into the Z-reading at close) and the standalone report (computed on read). It reads
 * only the sale snapshots and the refund ledger and writes nothing.
 *
 * What counts, per fiscal day set:
 *   sold      = the lines of every sale of those days that is not VOIDED (credit sales are sales; a sale voided the same day is not revenue --
 *               the Z-reading excludes it from gross_sales and shows it as void_total). Amounts are net_line_amount, so they add up to the
 *               sales' grand totals (DISC-006).
 *   returned  = the lines named by every COMPLETED refund EXECUTED in those days (refunds.fiscal_day_id), whichever day the sale was made, at
 *               the amount the refund calculator recorded (refund_items.unit_refund_amount, which adds up to refunds.refund_total).
 *   net       = sold - returned, per item and per meal type; `net_amount` of the result is the day's net sales = gross_sales - refund_total as
 *               the Z-reading defines them.
 *   voided    = the grand totals of sales voided in those days. Reported beside the section, never part of it.
 *
 * The result always reconciles to the ledger totals: should the lines ever differ from sales.grand_total or refunds.refund_total, the
 * difference appears as a visible "Unallocated" meal type instead of silently breaking the total.
 *
 * The classification (meal type, item, kind) is SaleLineClassifier's, shared with the billing statement.
 */
final class SalesByMealTypeQuery
{
    public const UNALLOCATED = 'Unallocated';

    public function __construct(private readonly SaleLineClassifier $classifier = new SaleLineClassifier) {}

    /** @return array<string, mixed> */
    public function forFiscalDay(string $storeId, string $fiscalDayId): array
    {
        return $this->compute($storeId, [$fiscalDayId]);
    }

    /**
     * Every business day of the store whose `business_date` is within [from, to] (either bound may be omitted), across all its tills.
     *
     * @return array<string, mixed>
     */
    public function forBusinessDates(string $storeId, ?Carbon $from, ?Carbon $to): array
    {
        $days = FiscalDay::where('store_id', $storeId)
            ->when($from, fn ($query) => $query->where('business_date', '>=', $from->toDateString()))
            ->when($to, fn ($query) => $query->where('business_date', '<=', $to->toDateString()))
            ->pluck('id')->all();

        return $this->compute($storeId, $days);
    }

    /**
     * @param  list<string>  $dayIds
     * @return array<string, mixed>
     */
    private function compute(string $storeId, array $dayIds): array
    {
        $types = [];
        $soldTotal = '0.00';
        $returnedTotal = '0.00';
        $voidedTotal = '0.00';
        $linesSold = '0.00';
        $linesReturned = '0.00';

        foreach (array_chunk($dayIds, 500) as $days) {
            $sales = DB::table('sales')->where('store_id', $storeId)->whereIn('fiscal_day_id', $days)->where('status', '!=', 'VOIDED');
            $soldTotal = bcadd($soldTotal, bcadd((string) $sales->clone()->sum('grand_total'), '0', 2), 2);
            $saleIds = $sales->clone()->pluck('id')->all();
            foreach ($this->classifier->forSales($saleIds)['bySale'] as $lines) {
                foreach ($lines as $line) {
                    $this->add($types, $line['meal_type'], $line['kind'], $line['item'], $line['quantity'], $line['amount'], sold: true);
                    $linesSold = bcadd($linesSold, $line['amount'], 2);
                }
            }

            $refunds = DB::table('refunds')->join('sales', 'sales.id', '=', 'refunds.sale_id')
                ->where('sales.store_id', $storeId)->whereIn('refunds.fiscal_day_id', $days)->where('refunds.status', 'COMPLETED');
            $returnedTotal = bcadd($returnedTotal, bcadd((string) $refunds->clone()->sum('refunds.refund_total'), '0', 2), 2);
            $refundItems = (clone $refunds)->join('refund_items', 'refund_items.refund_id', '=', 'refunds.id')
                ->get(['refund_items.sale_item_id', 'refund_items.quantity_returned', 'refund_items.unit_refund_amount']);
            $classified = $this->classifier->forItems($refundItems->pluck('sale_item_id')->unique()->values()->all())['byId'];
            foreach ($refundItems as $refundItem) {
                $line = $classified[$refundItem->sale_item_id];
                $amount = bcadd((string) $refundItem->unit_refund_amount, '0', 2);
                $this->add($types, $line['meal_type'], $line['kind'], $line['item'], bcadd((string) $refundItem->quantity_returned, '0', 3), $amount, sold: false);
                $linesReturned = bcadd($linesReturned, $amount, 2);
            }

            $voidedTotal = bcadd($voidedTotal, bcadd((string) DB::table('voids')->join('sales', 'sales.id', '=', 'voids.sale_id')
                ->where('sales.store_id', $storeId)->whereIn('voids.fiscal_day_id', $days)->where('voids.status', 'VOIDED')->sum('sales.grand_total'), '0', 2), 2);
        }

        if (bccomp($soldTotal, $linesSold, 2) !== 0) {
            $this->add($types, self::UNALLOCATED, 'PRODUCT', 'Not itemised', '0.000', bcsub($soldTotal, $linesSold, 2), sold: true);
        }
        if (bccomp($returnedTotal, $linesReturned, 2) !== 0) {
            $this->add($types, self::UNALLOCATED, 'PRODUCT', 'Not itemised', '0.000', bcsub($returnedTotal, $linesReturned, 2), sold: false);
        }

        return $this->assemble($types, $soldTotal, $returnedTotal, $voidedTotal);
    }

    /** @param  array<string, array<string, mixed>>  $types */
    private function add(array &$types, string $mealType, string $kind, string $item, string $quantity, string $amount, bool $sold): void
    {
        $type = &$types[$mealType];
        $type ??= ['sold' => '0.00', 'returned' => '0.00', 'items' => []];
        $row = &$type['items'][$kind."\0".$item];
        $row ??= ['item' => $item, 'kind' => $kind, 'qty_sold' => '0.000', 'sold' => '0.00', 'qty_returned' => '0.000', 'returned' => '0.00'];

        $row[$sold ? 'qty_sold' : 'qty_returned'] = bcadd($row[$sold ? 'qty_sold' : 'qty_returned'], $quantity, 3);
        $row[$sold ? 'sold' : 'returned'] = bcadd($row[$sold ? 'sold' : 'returned'], $amount, 2);
        $type[$sold ? 'sold' : 'returned'] = bcadd($type[$sold ? 'sold' : 'returned'], $amount, 2);
    }

    /**
     * @param  array<string, array<string, mixed>>  $types
     * @return array<string, mixed>
     */
    private function assemble(array $types, string $sold, string $returned, string $voided): array
    {
        $rank = fn (string $name): int => match ($name) {
            SaleLineClassifier::OTHER_ITEMS => 1,
            self::UNALLOCATED => 2,
            default => 0,
        };
        $names = array_keys($types);
        usort($names, fn (string $a, string $b): int => [$rank($a), mb_strtolower($a)] <=> [$rank($b), mb_strtolower($b)]);

        $out = [];
        foreach ($names as $name) {
            $type = $types[$name];
            $items = array_values($type['items']);
            usort($items, fn (array $a, array $b): int => [SaleLineClassifier::KIND_ORDER[$a['kind']], mb_strtolower($a['item'])] <=> [SaleLineClassifier::KIND_ORDER[$b['kind']], mb_strtolower($b['item'])]);
            $out[] = [
                'meal_type' => $name,
                'sold_amount' => $type['sold'],
                'returned_amount' => $type['returned'],
                'net_amount' => bcsub($type['sold'], $type['returned'], 2),
                'items' => array_map(fn (array $item): array => [
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

        return [
            'version' => 1,
            'sold_amount' => $sold,
            'returned_amount' => $returned,
            'net_amount' => bcsub($sold, $returned, 2),
            'voided_amount' => $voided,
            'meal_types' => $out,
        ];
    }
}
