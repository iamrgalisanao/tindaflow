<?php

namespace App\Services\Sales;

use App\Domain\Exceptions\SaleAlreadyVoidedException;
use App\Domain\Exceptions\SaleNotFoundException;
use App\Domain\Exceptions\SaleNotVoidableException;
use App\Domain\Exceptions\VoidNotFoundException;
use App\Domain\Exceptions\VoidNotPendingApprovalException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleVoid;
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
use Illuminate\Support\Facades\DB;

/**
 * Stage 2 SS2.8 / state-machines.md SS2. saleVoid records the request; when the requester already
 * holds SALE_VOID_APPROVE the same call executes it (REQUESTED -> APPROVED -> VOIDED). voidApprove is
 * the fiscal execution point, not a permission grant: it resolves the *approver's* terminal, shift and
 * fiscal day now, re-checks eligibility now, and either commits everything or leaves the void exactly
 * as it was (a failed attempt is never an automatic rejection). voidReject is a human decision and
 * never touches the ledger.
 *
 * Every execution runs inside IdempotencyService::execute()'s transaction, so throwing a domain
 * exception anywhere rolls back the void, its stock movements, its audit event and its journal entry
 * together with the idempotency reservation -- which is what lets the same key be reused after the
 * blocking condition is corrected.
 */
final class VoidService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly ExecutionContextResolver $contexts,
        private readonly StockLedger $stockLedger,
        private readonly SaleReturnLocator $returnLocator,
    ) {}

    public function request(Terminal $terminal, User $user, string $saleId, string $idempotencyKey, string $reason): SaleVoid
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::SaleVoid,
            $this->requestHasher->hash(['sale_id' => $saleId, 'reason' => $reason]),
            fn () => $this->performRequest($terminal, $user, $saleId, $reason),
        );

        return SaleVoid::findOrFail($result->resultResourceId);
    }

    public function approve(Terminal $terminal, User $user, string $voidId, string $idempotencyKey): SaleVoid
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::VoidApprove,
            $this->requestHasher->hash(['void_id' => $voidId]),
            fn () => $this->performApprove($terminal, $user, $voidId),
        );

        return SaleVoid::findOrFail($result->resultResourceId);
    }

    /**
     * Not terminal-scoped: the contract says a rejection needs neither an enrolled terminal nor an open
     * shift, and the idempotency store is keyed by terminal. A retry is therefore made safe by the state
     * itself -- repeating the same rejection by the same user returns the recorded result and writes
     * nothing further -- see docs/06-backend/stage-15-sales-history-void-refund.md.
     */
    public function reject(User $user, string $voidId, string $reason): SaleVoid
    {
        return DB::transaction(function () use ($user, $voidId, $reason): SaleVoid {
            $this->find($voidId, $user->store_id);
            $void = SaleVoid::whereKey($voidId)->lockForUpdate()->firstOrFail();

            if ($void->status === 'REJECTED' && $void->approved_by === $user->id && $this->recordedRejectionReason($void) === $reason) {
                return $void;
            }
            if ($void->status !== 'REQUESTED') {
                throw VoidNotPendingApprovalException::forVoid($void->id, $void->status);
            }

            $void->update(['status' => 'REJECTED', 'approved_by' => $user->id, 'resolved_at' => Carbon::now()]);

            AuditEvent::create([
                'store_id' => $user->store_id,
                'event_type' => 'SALE_VOID_REJECTED',
                'actor_user_id' => $user->id,
                'entity_type' => 'void',
                'entity_id' => $void->id,
                'after_metadata' => ['void_id' => $void->id, 'sale_id' => $void->sale_id, 'requested_by' => $void->requested_by, 'rejected_by' => $user->id],
                'reason' => $reason,
            ]);

            return $void;
        });
    }

    /** @throws VoidNotFoundException a void of another store is indistinguishable from a missing one */
    public function find(string $voidId, string $storeId): SaleVoid
    {
        $void = SaleVoid::whereKey($voidId)
            ->whereIn('sale_id', Sale::where('store_id', $storeId)->select('id'))
            ->first();

        return $void ?? throw VoidNotFoundException::forId($voidId);
    }

    private function performRequest(Terminal $terminal, User $user, string $saleId, string $reason): OperationOutcome
    {
        $sale = Sale::where('store_id', $user->store_id)->find($saleId) ?? throw SaleNotFoundException::forId($saleId);

        if (! RoleCapabilityCatalog::has($user->role, 'SALE_VOID_APPROVE')) {
            $this->assertVoidable($sale, $this->fiscalDayStatus($sale->fiscal_day_id));
            $void = SaleVoid::create([
                'sale_id' => $sale->id,
                'requested_by' => $user->id,
                'reason' => $reason,
                'status' => 'REQUESTED',
                'requested_at' => Carbon::now(),
            ]);
            $this->auditRequested($user, $terminal, $void);

            return new OperationOutcome(resultType: 'void', resultResourceId: $void->id);
        }

        $lockOrder = new GlobalLockOrder;
        $context = $this->contexts->lockFor($lockOrder, $terminal->id, $user->id, $sale->fiscal_day_id);
        $lockOrder->acquire(LockableResource::Sale);
        $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
        $this->assertVoidable($sale, $context['other']->status);

        $now = Carbon::now();
        $void = SaleVoid::create([
            'sale_id' => $sale->id,
            'requested_by' => $user->id,
            'reason' => $reason,
            'status' => 'VOIDED',
            'approved_by' => $user->id,
            'requested_at' => $now,
            'resolved_at' => $now,
            'terminal_id' => $terminal->id,
            'fiscal_day_id' => $context['fiscalDay']->id,
            'shift_id' => $context['shift']->id,
        ]);
        $this->auditRequested($user, $terminal, $void);
        $this->applyVoid($void, $sale, $user, $terminal);

        return new OperationOutcome(resultType: 'void', resultResourceId: $void->id);
    }

    private function performApprove(Terminal $terminal, User $user, string $voidId): OperationOutcome
    {
        $pending = $this->find($voidId, $user->store_id);
        $originalFiscalDayId = Sale::whereKey($pending->sale_id)->value('fiscal_day_id');

        $lockOrder = new GlobalLockOrder;
        $context = $this->contexts->lockFor($lockOrder, $terminal->id, $user->id, $originalFiscalDayId);
        $lockOrder->acquire(LockableResource::Sale);
        $sale = Sale::whereKey($pending->sale_id)->lockForUpdate()->firstOrFail();
        $void = SaleVoid::whereKey($voidId)->lockForUpdate()->firstOrFail();

        if ($void->status !== 'REQUESTED') {
            throw VoidNotPendingApprovalException::forVoid($void->id, $void->status);
        }
        $this->assertVoidable($sale, $context['other']->status);

        $void->update([
            'status' => 'VOIDED',
            'approved_by' => $user->id,
            'resolved_at' => Carbon::now(),
            'terminal_id' => $terminal->id,
            'fiscal_day_id' => $context['fiscalDay']->id,
            'shift_id' => $context['shift']->id,
        ]);
        $this->applyVoid($void, $sale, $user, $terminal);

        return new OperationOutcome(resultType: 'void', resultResourceId: $void->id);
    }

    /** domain-model.md SS2.8's five conditions, in the order a person would want them explained. */
    private function assertVoidable(Sale $sale, string $originalFiscalDayStatus): void
    {
        if ($sale->status === 'VOIDED') {
            throw SaleAlreadyVoidedException::forSale($sale->id);
        }
        if ($sale->status !== 'COMPLETED') {
            throw SaleNotVoidableException::becauseNotEligible($sale->id, "its status is {$sale->status}; a refund has already been taken against it");
        }
        if (Refund::where('sale_id', $sale->id)->where('status', 'COMPLETED')->exists()) {
            throw SaleNotVoidableException::becauseNotEligible($sale->id, 'a refund has already been completed against it');
        }
        if ($originalFiscalDayStatus !== 'OPEN') {
            throw SaleNotVoidableException::becauseNotEligible($sale->id, 'its fiscal day has closed, so it must be corrected with a refund');
        }
    }

    private function fiscalDayStatus(string $fiscalDayId): string
    {
        return (string) DB::table('fiscal_days')->where('id', $fiscalDayId)->value('status');
    }

    /** Invariants #22/#23: one compensating SALE_RETURN per line for the full quantity; nothing original is edited. */
    private function applyVoid(SaleVoid $void, Sale $sale, User $approver, Terminal $terminal): void
    {
        $items = $sale->items()->orderBy('line_number')->get();

        // One call, so the ledger writes the returns in the canonical stock order (stage 28), not line order.
        $this->stockLedger->recordMany($items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'location_id' => $this->returnLocator->forSaleItem($item, $sale),
            'terminal_id' => $terminal->id,
            'movement_type' => 'SALE_RETURN',
            'quantity' => (string) $item->quantity,
            'reference_type' => 'sale_item',
            'reference_id' => $item->id,
            'unit_cost' => $item->unit_cost_snapshot,
            'created_by' => $approver->id,
        ])->all());

        $sale->update(['status' => 'VOIDED']);

        $audit = AuditEvent::create([
            'store_id' => $sale->store_id,
            'event_type' => 'SALE_VOIDED',
            'actor_user_id' => $approver->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'void',
            'entity_id' => $void->id,
            'before_metadata' => ['sale_status' => 'COMPLETED'],
            'after_metadata' => ['void_id' => $void->id, 'sale_id' => $sale->id, 'sale_status' => 'VOIDED', 'requested_by' => $void->requested_by, 'approved_by' => $approver->id],
            'reason' => $void->reason,
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $sale->store_id,
            'terminal_id' => $terminal->id,
            'event_type' => 'VOID',
            'source_type' => 'void',
            'source_id' => $void->id,
            'audit_event_id' => $audit->id,
            'payload_json' => [
                'void_id' => $void->id,
                'sale_id' => $sale->id,
                'transaction_number' => $sale->transaction_number,
                'invoice_number' => $sale->invoice()->value('invoice_number'),
                'grand_total' => $sale->grand_total,
                'reason' => $void->reason,
                'requested_by' => $void->requested_by,
                'approved_by' => $approver->id,
                'terminal_id' => $terminal->id,
                'fiscal_day_id' => $void->fiscal_day_id,
                'shift_id' => $void->shift_id,
                'voided_at' => $void->resolved_at?->toIso8601String(),
                'items' => $items->map(fn ($item) => [
                    'line_number' => $item->line_number,
                    'product_name' => $item->product_name_snapshot,
                    'quantity' => (string) $item->quantity,
                    'net_line_amount' => $item->net_line_amount,
                ])->all(),
            ],
        ]);
    }

    private function auditRequested(User $user, Terminal $terminal, SaleVoid $void): void
    {
        AuditEvent::create([
            'store_id' => $user->store_id,
            'event_type' => 'SALE_VOID_REQUESTED',
            'actor_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'void',
            'entity_id' => $void->id,
            'after_metadata' => ['void_id' => $void->id, 'sale_id' => $void->sale_id, 'requested_by' => $user->id],
            'reason' => $void->reason,
        ]);
    }

    private function recordedRejectionReason(SaleVoid $void): ?string
    {
        return AuditEvent::where('entity_type', 'void')
            ->where('entity_id', $void->id)
            ->where('event_type', 'SALE_VOID_REJECTED')
            ->orderByDesc('occurred_at')
            ->value('reason');
    }
}
