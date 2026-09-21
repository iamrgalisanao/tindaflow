<?php

namespace App\Services\Sales;

use App\Domain\Exceptions\RefundNotAllowedException;
use App\Domain\Exceptions\RefundNotFoundException;
use App\Domain\Exceptions\RefundNotPendingApprovalException;
use App\Domain\Exceptions\RefundSettlementMismatchException;
use App\Domain\Exceptions\SaleNotFoundException;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\RefundSettlement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Services\Inventory\StockLedger;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Stage 2 SS2.9 / state-machines.md SS3. The same shape as VoidService: saleRefund records the request
 * and, when the requester already holds SALE_REFUND_APPROVE, executes it in the same call;
 * refundApprove is the fiscal execution point and re-derives everything at that moment; refundReject
 * is a human decision that never touches the ledger.
 *
 * Amounts are never accepted from the client. Each line's amount is derived from the sale_item's
 * frozen net_line_amount with the cumulative-recompute rule (RefundCalculator) against every COMPLETED
 * refund of that line, so a refund requested today and approved after a sibling completed is
 * re-validated against the real remaining quantity and amount. Refund lines and settlements are
 * append-only rows that only exist once a refund is COMPLETED, so while a refund is REQUESTED its
 * lines are kept on the REFUND_REQUESTED audit event (Refund::requestedPayload()).
 */
final class RefundService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly ExecutionContextResolver $contexts,
        private readonly StockLedger $stockLedger,
        private readonly SaleReturnLocator $returnLocator,
        private readonly RefundCalculator $calculator,
    ) {}

    /** @param  array{items: list<array<string, mixed>>, settlements: list<array<string, mixed>>, reason: string}  $payload */
    public function request(Terminal $terminal, User $user, string $saleId, string $idempotencyKey, array $payload): Refund
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::SaleRefund,
            $this->requestHasher->hash(['sale_id' => $saleId] + $payload),
            fn () => $this->performRequest($terminal, $user, $saleId, $payload),
        );

        return Refund::findOrFail($result->resultResourceId);
    }

    public function approve(Terminal $terminal, User $user, string $refundId, string $idempotencyKey): Refund
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::RefundApprove,
            $this->requestHasher->hash(['refund_id' => $refundId]),
            fn () => $this->performApprove($terminal, $user, $refundId),
        );

        return Refund::findOrFail($result->resultResourceId);
    }

    /** Not terminal-scoped, for the same reason as VoidService::reject(): a retry is made safe by the state itself. */
    public function reject(User $user, string $refundId, string $reason): Refund
    {
        return DB::transaction(function () use ($user, $refundId, $reason): Refund {
            $this->find($refundId, $user->store_id);
            $refund = Refund::whereKey($refundId)->lockForUpdate()->firstOrFail();

            if ($refund->status === 'REJECTED' && $refund->approved_by === $user->id && $this->recordedRejectionReason($refund) === $reason) {
                return $refund;
            }
            if ($refund->status !== 'REQUESTED') {
                throw RefundNotPendingApprovalException::forRefund($refund->id, $refund->status);
            }

            $refund->update(['status' => 'REJECTED', 'approved_by' => $user->id, 'resolved_at' => Carbon::now()]);

            AuditEvent::create([
                'store_id' => $user->store_id,
                'event_type' => 'REFUND_REJECTED',
                'actor_user_id' => $user->id,
                'entity_type' => 'refund',
                'entity_id' => $refund->id,
                'after_metadata' => ['refund_id' => $refund->id, 'sale_id' => $refund->sale_id, 'requested_by' => $refund->requested_by, 'rejected_by' => $user->id],
                'reason' => $reason,
            ]);

            return $refund;
        });
    }

    /** @throws RefundNotFoundException a refund of another store is indistinguishable from a missing one */
    public function find(string $refundId, string $storeId): Refund
    {
        $refund = Refund::whereKey($refundId)
            ->whereIn('sale_id', Sale::where('store_id', $storeId)->select('id'))
            ->first();

        return $refund ?? throw RefundNotFoundException::forId($refundId);
    }

    /**
     * Non-authoritative convenience for the UI (RefundResult.remaining_refundable): what each line of the
     * sale can still give back, counting COMPLETED refunds only. Always re-derived on the next attempt.
     *
     * @return list<array{sale_item_id: string, remaining_quantity: string, remaining_amount: string}>
     */
    public function remainingRefundable(Sale $sale): array
    {
        $prior = $this->priorReturned($sale->id);

        return $sale->items()->orderBy('line_number')->get()->map(function (SaleItem $item) use ($prior) {
            $remaining = $this->calculator->remaining(
                new Money((string) $item->net_line_amount),
                new Quantity((string) $item->quantity),
                new Quantity($prior[$item->id]['quantity'] ?? '0'),
                new Money($prior[$item->id]['amount'] ?? '0.00'),
            );

            return [
                'sale_item_id' => $item->id,
                'remaining_quantity' => $remaining['quantity']->toApiString(),
                'remaining_amount' => $remaining['amount']->toApiString(),
            ];
        })->all();
    }

    /** @param  array<string, mixed>  $payload */
    private function performRequest(Terminal $terminal, User $user, string $saleId, array $payload): OperationOutcome
    {
        Sale::where('store_id', $user->store_id)->find($saleId) ?? throw SaleNotFoundException::forId($saleId);

        $lockOrder = new GlobalLockOrder;
        $immediate = RoleCapabilityCatalog::has($user->role, 'SALE_REFUND_APPROVE');
        $context = $immediate ? $this->contexts->lockFor($lockOrder, $terminal->id, $user->id) : null;

        $lockOrder->acquire(LockableResource::Sale);
        $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
        if ($sale->status === 'VOIDED') {
            throw RefundNotAllowedException::becauseSaleVoided($sale->id);
        }
        $items = $this->lockItems($sale, $lockOrder);

        $lines = $this->deriveLines($sale, $items, $payload['items']);
        $total = $this->totalOf($lines);
        $this->assertSettlementsMatch($payload['settlements'], $total);

        $refund = Refund::create([
            'sale_id' => $sale->id,
            'requested_by' => $user->id,
            'reason' => $payload['reason'],
            'status' => 'REQUESTED',
            'requested_at' => Carbon::now(),
            'refund_total' => $total->toApiString(),
        ]);

        AuditEvent::create([
            'store_id' => $sale->store_id,
            'event_type' => 'REFUND_REQUESTED',
            'actor_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'refund',
            'entity_id' => $refund->id,
            'after_metadata' => [
                'refund_id' => $refund->id,
                'sale_id' => $sale->id,
                'refund_total' => $total->toApiString(),
                'items' => array_map(fn (array $line) => [
                    'sale_item_id' => $line['item']->id,
                    'quantity_returned' => $line['quantity']->toApiString(),
                    'disposition' => $line['disposition'],
                    'unit_refund_amount' => $line['amount']->toApiString(),
                ], $lines),
                'settlements' => array_map(fn (array $settlement) => [
                    'payment_method' => $settlement['payment_method'],
                    'amount' => $settlement['amount'],
                    'external_reference' => $settlement['external_reference'] ?? null,
                ], $payload['settlements']),
            ],
            'reason' => $payload['reason'],
        ]);

        if ($context !== null) {
            $this->complete($refund, $sale, $items, $lines, $payload['settlements'], $total, $user, $terminal, $context);
        }

        return new OperationOutcome(resultType: 'refund', resultResourceId: $refund->id);
    }

    private function performApprove(Terminal $terminal, User $user, string $refundId): OperationOutcome
    {
        $pending = $this->find($refundId, $user->store_id);

        $lockOrder = new GlobalLockOrder;
        $context = $this->contexts->lockFor($lockOrder, $terminal->id, $user->id);
        $lockOrder->acquire(LockableResource::Sale);
        $sale = Sale::whereKey($pending->sale_id)->lockForUpdate()->firstOrFail();
        $refund = Refund::whereKey($refundId)->lockForUpdate()->firstOrFail();

        if ($refund->status !== 'REQUESTED') {
            throw RefundNotPendingApprovalException::forRefund($refund->id, $refund->status);
        }
        if ($sale->status === 'VOIDED') {
            throw RefundNotAllowedException::becauseSaleVoided($sale->id);
        }

        $requested = $refund->requestedPayload() ?? throw new LogicException("Refund {$refund->id} has no recorded request.");
        $items = $this->lockItems($sale, $lockOrder);

        // Re-derived now, against every refund completed since the request was made (invariants #27/#28/#70).
        $lines = $this->deriveLines($sale, $items, $requested['items']);
        $total = $this->totalOf($lines);
        $this->assertSettlementsMatch($requested['settlements'], $total);

        $this->complete($refund, $sale, $items, $lines, $requested['settlements'], $total, $user, $terminal, $context);

        return new OperationOutcome(resultType: 'refund', resultResourceId: $refund->id);
    }

    /** @return Collection<string, SaleItem> the sale's lines keyed by id, locked in ascending line order */
    private function lockItems(Sale $sale, GlobalLockOrder $lockOrder): Collection
    {
        $items = SaleItem::where('sale_id', $sale->id)->orderBy('line_number')->lockForUpdate()->get();
        foreach ($items as $item) {
            $lockOrder->acquire(LockableResource::SaleItem, $item->line_number);
        }

        return $items->keyBy('id');
    }

    /**
     * @param  Collection<string, SaleItem>  $items
     * @param  list<array<string, mixed>>  $requestedItems  quantity is under `quantity` on a request and `quantity_returned` on a recorded one
     * @return list<array{item: SaleItem, quantity: Quantity, disposition: string, amount: Money}>
     */
    private function deriveLines(Sale $sale, Collection $items, array $requestedItems): array
    {
        $prior = $this->priorReturned($sale->id);
        $lines = [];

        foreach ($requestedItems as $index => $requested) {
            $item = $items->get($requested['sale_item_id']);
            if ($item === null) {
                throw ValidationException::withMessages(["items.{$index}.sale_item_id" => 'This line does not belong to the sale being refunded.']);
            }

            $quantity = new Quantity((string) ($requested['quantity'] ?? $requested['quantity_returned']));
            $amount = $this->calculator->amountForEvent(
                $item->id,
                new Money((string) $item->net_line_amount),
                new Quantity((string) $item->quantity),
                new Quantity($prior[$item->id]['quantity'] ?? '0'),
                new Money($prior[$item->id]['amount'] ?? '0.00'),
                $quantity,
            );

            $lines[] = ['item' => $item, 'quantity' => $quantity, 'disposition' => $requested['disposition'], 'amount' => $amount];
        }

        return $lines;
    }

    /** @param  list<array{amount: Money}>  $lines */
    private function totalOf(array $lines): Money
    {
        return array_reduce($lines, fn (Money $carry, array $line) => $carry->add($line['amount']), Money::zero());
    }

    /** Invariant #70. @param  list<array<string, mixed>>  $settlements */
    private function assertSettlementsMatch(array $settlements, Money $total): void
    {
        $settled = array_reduce($settlements, fn (Money $carry, array $settlement) => $carry->add(new Money((string) $settlement['amount'])), Money::zero());
        if (! $settled->equals($total)) {
            throw RefundSettlementMismatchException::forTotals($settled->toApiString(), $total->toApiString());
        }
    }

    /**
     * @param  Collection<string, SaleItem>  $items
     * @param  list<array{item: SaleItem, quantity: Quantity, disposition: string, amount: Money}>  $lines
     * @param  list<array<string, mixed>>  $settlements
     * @param  array{shift: object, fiscalDay: object, other: object|null}  $context
     */
    private function complete(Refund $refund, Sale $sale, Collection $items, array $lines, array $settlements, Money $total, User $approver, Terminal $terminal, array $context): void
    {
        $now = Carbon::now();
        $refund->update([
            'status' => 'COMPLETED',
            'approved_by' => $approver->id,
            'resolved_at' => $now,
            'refunded_at' => $now,
            'refund_total' => $total->toApiString(),
            'terminal_id' => $terminal->id,
            'fiscal_day_id' => $context['fiscalDay']->id,
            'shift_id' => $context['shift']->id,
        ]);

        $returns = [];
        foreach ($lines as $line) {
            $refundItem = RefundItem::create([
                'refund_id' => $refund->id,
                'sale_item_id' => $line['item']->id,
                'quantity_returned' => $line['quantity']->toApiString(),
                'disposition' => $line['disposition'],
                'unit_refund_amount' => $line['amount']->toApiString(),
            ]);

            // Invariant #30: only RETURN_TO_STOCK puts the unit back on the shelf.
            if ($line['disposition'] === 'RETURN_TO_STOCK') {
                $returns[] = [
                    'product_id' => $line['item']->product_id,
                    'location_id' => $this->returnLocator->forSaleItem($line['item'], $sale),
                    'terminal_id' => $terminal->id,
                    'movement_type' => 'SALE_RETURN',
                    'quantity' => $line['quantity']->toApiString(),
                    'reference_type' => 'refund_item',
                    'reference_id' => $refundItem->id,
                    'unit_cost' => $line['item']->unit_cost_snapshot,
                    'created_by' => $approver->id,
                ];
            }
        }
        // One call, so the ledger writes the returns in the canonical stock order (stage 28), not line order.
        $this->stockLedger->recordMany($returns);

        foreach ($settlements as $settlement) {
            RefundSettlement::create([
                'refund_id' => $refund->id,
                'payment_method' => $settlement['payment_method'],
                'amount' => (string) $settlement['amount'],
                'processed_at' => $now,
                'external_reference' => $settlement['external_reference'] ?? null,
            ]);
        }

        $sale->update(['status' => $this->saleStatusFromHistory($sale, $items)]);

        $audit = AuditEvent::create([
            'store_id' => $sale->store_id,
            'event_type' => 'REFUND_CREATED',
            'actor_user_id' => $approver->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'refund',
            'entity_id' => $refund->id,
            'after_metadata' => ['refund_id' => $refund->id, 'sale_id' => $sale->id, 'refund_total' => $total->toApiString(), 'sale_status' => $sale->status, 'requested_by' => $refund->requested_by, 'approved_by' => $approver->id],
            'reason' => $refund->reason,
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $sale->store_id,
            'terminal_id' => $terminal->id,
            'event_type' => 'REFUND',
            'source_type' => 'refund',
            'source_id' => $refund->id,
            'audit_event_id' => $audit->id,
            'payload_json' => [
                'refund_id' => $refund->id,
                'sale_id' => $sale->id,
                'transaction_number' => $sale->transaction_number,
                'invoice_number' => $sale->invoice()->value('invoice_number'),
                'refund_total' => $total->toApiString(),
                'reason' => $refund->reason,
                'requested_by' => $refund->requested_by,
                'approved_by' => $approver->id,
                'terminal_id' => $terminal->id,
                'fiscal_day_id' => $refund->fiscal_day_id,
                'shift_id' => $refund->shift_id,
                'refunded_at' => $now->toIso8601String(),
                'items' => array_map(fn (array $line) => [
                    'line_number' => $line['item']->line_number,
                    'product_name' => $line['item']->product_name_snapshot,
                    'quantity_returned' => $line['quantity']->toApiString(),
                    'disposition' => $line['disposition'],
                    'unit_refund_amount' => $line['amount']->toApiString(),
                ], $lines),
                'settlements' => array_map(fn (array $settlement) => [
                    'payment_method' => $settlement['payment_method'],
                    'amount' => (string) $settlement['amount'],
                    'external_reference' => $settlement['external_reference'] ?? null,
                ], $settlements),
            ],
        ]);
    }

    /** Invariant #31: recomputed from the COMPLETED refunds every time, never asserted by a caller. */
    private function saleStatusFromHistory(Sale $sale, Collection $items): string
    {
        $returned = $this->priorReturned($sale->id);
        $everythingReturned = $items->every(fn (SaleItem $item) => bccomp($returned[$item->id]['quantity'] ?? '0', (string) $item->quantity, 3) >= 0);

        return $everythingReturned ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
    }

    /** @return array<string, array{quantity: string, amount: string}> per sale_item, over COMPLETED refunds only */
    private function priorReturned(string $saleId): array
    {
        return DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.sale_id', $saleId)
            ->where('refunds.status', 'COMPLETED')
            ->groupBy('refund_items.sale_item_id')
            ->selectRaw('refund_items.sale_item_id, SUM(refund_items.quantity_returned) AS quantity, SUM(refund_items.unit_refund_amount) AS amount')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->sale_item_id => ['quantity' => bcadd((string) $row->quantity, '0', 3), 'amount' => bcadd((string) $row->amount, '0', 2)]])
            ->all();
    }

    private function recordedRejectionReason(Refund $refund): ?string
    {
        return AuditEvent::where('entity_type', 'refund')
            ->where('entity_id', $refund->id)
            ->where('event_type', 'REFUND_REJECTED')
            ->orderByDesc('occurred_at')
            ->value('reason');
    }
}
