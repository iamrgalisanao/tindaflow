<?php

namespace App\Services\Slips;

use App\Domain\Exceptions\SlipNotFoundException;
use App\Models\AuditEvent;
use App\Models\SaleSlip;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;

/**
 * saleSlipReprint, the slip twin of InvoiceReprintService (invariants #15/#16 applied to the slip). A reprint changes nothing: no
 * slip, sale or counter is touched and no number is allocated. It only records that a copy was produced -- a SLIP_REPRINTED audit
 * event whose id *is* the reprint occurrence's id -- so every copy stays independently auditable however many are printed. The
 * electronic journal is an invoice-mode artifact and gets no entry (ADR-016 section 7).
 *
 * Idempotent per (terminal, key): a retry returns the same occurrence, rebuilt from the stored audit event.
 */
final class SlipReprintService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
    ) {}

    /** @return array{event: AuditEvent, slip: SaleSlip} */
    public function reprint(Terminal $terminal, User $user, string $slipId, string $idempotencyKey): array
    {
        $result = $this->idempotencyService->execute(
            $terminal->id,
            $idempotencyKey,
            IdempotencyOperationType::SlipReprint,
            $this->requestHasher->hash(['slip_id' => $slipId]),
            fn () => $this->record($terminal, $user, $slipId),
        );

        $event = AuditEvent::findOrFail($result->resultResourceId);

        return ['event' => $event, 'slip' => SaleSlip::findOrFail($event->entity_id)];
    }

    /** @throws SlipNotFoundException a slip of another store is indistinguishable from a missing one */
    public function find(string $slipId, string $storeId): SaleSlip
    {
        return SaleSlip::where('store_id', $storeId)->find($slipId) ?? throw SlipNotFoundException::forId($slipId);
    }

    private function record(Terminal $terminal, User $user, string $slipId): OperationOutcome
    {
        $slip = $this->find($slipId, $user->store_id);

        $event = AuditEvent::create([
            'store_id' => $slip->store_id,
            'event_type' => 'SLIP_REPRINTED',
            'actor_user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'entity_type' => 'sale_slip',
            'entity_id' => $slip->id,
            'after_metadata' => ['slip_id' => $slip->id, 'slip_number' => $slip->slip_number, 'sale_id' => $slip->sale_id],
        ]);
        // The database stamps the event; every response uses that one moment, so a replay reproduces it exactly.
        $event->refresh();

        return new OperationOutcome(resultType: 'slip_reprint', resultResourceId: $event->id);
    }
}
