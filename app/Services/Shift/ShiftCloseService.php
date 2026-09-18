<?php

namespace App\Services\Shift;

use App\Domain\Exceptions\ShiftAlreadyClosedException;
use App\Domain\Exceptions\ShiftNotFoundException;
use App\Domain\Money;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Shift;
use App\Models\XReading;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * openapi.yaml shiftClose. Accepts declared_cash only -- expected_cash/
 * variance/every other total is always computed server-side (invariant
 * #37/#38), atomically alongside the closing X-Reading (invariant #43).
 * architecture.md's Global Lock Order: only the shift's own lock is
 * needed (a concurrent checkout against this same shift already takes
 * the same lock first, so the race is already resolved there).
 */
final class ShiftCloseService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly ShiftReadingAggregator $aggregator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the already-shape-validated request body ({declared_cash})
     * @return array{shift: Shift, x_reading: XReading}
     */
    public function close(string $shiftId, string $terminalId, string $actorId, string $idempotencyKey, array $payload): array
    {
        $requestHash = $this->requestHasher->hash($payload);

        $result = $this->idempotencyService->execute(
            $terminalId,
            $idempotencyKey,
            IdempotencyOperationType::ShiftClose,
            $requestHash,
            fn () => $this->performClose($shiftId, $terminalId, $actorId, $payload),
        );

        $shift = Shift::findOrFail($result->resultResourceId);
        $xReading = XReading::where('shift_id', $shift->id)->where('is_closing_reading', true)->firstOrFail();

        return ['shift' => $shift, 'x_reading' => $xReading];
    }

    /** @param  array<string, mixed>  $payload */
    private function performClose(string $shiftId, string $terminalId, string $actorId, array $payload): OperationOutcome
    {
        $lockOrder = new GlobalLockOrder;
        $lockOrder->acquire(LockableResource::Shift);

        $shift = Shift::where('terminal_id', $terminalId)->lockForUpdate()->find($shiftId);
        if ($shift === null) {
            throw ShiftNotFoundException::forId($shiftId);
        }
        if ($shift->status === 'CLOSED') {
            throw ShiftAlreadyClosedException::forId($shiftId);
        }

        $declaredCash = Money::fromApiString((string) $payload['declared_cash']);
        $closedAt = Carbon::now();
        $snapshot = $this->aggregator->aggregate($shift, $declaredCash);

        $xReading = XReading::create([
            'terminal_id' => $terminalId,
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'from_at' => $shift->opened_at,
            'to_at' => $closedAt,
            'generated_at' => $closedAt,
            'generated_by' => $actorId,
            'is_closing_reading' => true,
            'totals_snapshot' => $snapshot,
        ]);

        $shift->update([
            'status' => 'CLOSED',
            'closed_at' => $closedAt,
            'expected_cash' => $snapshot['expected_cash'],
            'declared_cash' => $declaredCash->toApiString(),
            'variance' => $snapshot['variance'],
            'cash_sales' => $snapshot['cash_sales'],
            'non_cash_sales' => $snapshot['non_cash_sales'],
            'refunds_total' => $snapshot['refunds_total'],
            'cash_in_total' => $snapshot['cash_in_total'],
            'cash_out_total' => $snapshot['cash_out_total'],
        ]);

        $terminal = DB::table('terminals')->where('id', $terminalId)->first();

        $auditEvent = AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => 'SHIFT_CLOSED',
            'actor_user_id' => $actorId,
            'terminal_id' => $terminalId,
            'entity_type' => 'shift',
            'entity_id' => $shift->id,
            'after_metadata' => ['shift_id' => $shift->id, 'variance' => $snapshot['variance']],
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminalId,
            'event_type' => 'SHIFT_CLOSED',
            'source_type' => 'x_reading',
            'source_id' => $xReading->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => $snapshot,
        ]);

        return new OperationOutcome(resultType: 'shift', resultResourceId: $shift->id);
    }
}
