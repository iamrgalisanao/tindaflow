<?php

namespace App\Services\FiscalDay;

use App\Domain\Exceptions\FiscalDayClosedException;
use App\Domain\Exceptions\FiscalDayHasOpenShiftException;
use App\Domain\Exceptions\FiscalDayNotFoundException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\ZReading;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Carbon;

/**
 * openapi.yaml fiscalDayClose. Rejects with FISCAL_DAY_HAS_OPEN_SHIFT if
 * any Shift referencing this FiscalDay is still OPEN (invariant #36).
 * All Z-Reading totals are derived server-side (architecture.md §46).
 * Global Lock Order: only the fiscal_day's own lock is needed -- the
 * open-shift check is a read taken while holding it, and each shift's
 * own close already serializes against concurrent checkout via its own
 * shift lock independently (architecture.md's Global Lock Order table).
 *
 * No request body exists for this operation (openapi.yaml), so there is
 * no natural per-call payload to hash for idempotency the way
 * shiftOpen/shiftClose do -- the fiscal_day's own id already uniquely
 * identifies the intended effect, so the empty array is hashed as a
 * fixed, stable canonical payload instead.
 */
final class FiscalDayCloseService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly FiscalDayReadingAggregator $aggregator,
    ) {}

    /** @return array{fiscal_day: FiscalDay, z_reading: ZReading} */
    public function close(string $fiscalDayId, string $terminalId, string $actorId, string $idempotencyKey): array
    {
        $requestHash = hash('sha256', $fiscalDayId);

        $result = $this->idempotencyService->execute(
            $terminalId,
            $idempotencyKey,
            IdempotencyOperationType::FiscalDayClose,
            $requestHash,
            fn () => $this->performClose($fiscalDayId, $terminalId, $actorId),
        );

        $fiscalDay = FiscalDay::findOrFail($result->resultResourceId);
        $zReading = ZReading::where('fiscal_day_id', $fiscalDay->id)->firstOrFail();

        return ['fiscal_day' => $fiscalDay, 'z_reading' => $zReading];
    }

    private function performClose(string $fiscalDayId, string $terminalId, string $actorId): OperationOutcome
    {
        $lockOrder = new GlobalLockOrder;
        $lockOrder->acquire(LockableResource::FiscalDay);

        $fiscalDay = FiscalDay::where('terminal_id', $terminalId)->lockForUpdate()->find($fiscalDayId);
        if ($fiscalDay === null) {
            throw FiscalDayNotFoundException::forId($fiscalDayId);
        }
        if ($fiscalDay->status === 'CLOSED') {
            throw FiscalDayClosedException::forFiscalDay($fiscalDayId);
        }

        $hasOpenShift = Shift::where('fiscal_day_id', $fiscalDay->id)->where('status', 'OPEN')->exists();
        if ($hasOpenShift) {
            throw FiscalDayHasOpenShiftException::forFiscalDay($fiscalDayId);
        }

        $closedAt = Carbon::now();
        $snapshot = $this->aggregator->aggregate($fiscalDay);

        $zReading = ZReading::create([
            'terminal_id' => $terminalId,
            'fiscal_day_id' => $fiscalDay->id,
            'business_date' => $fiscalDay->business_date,
            'from_at' => $fiscalDay->opened_at,
            'to_at' => $closedAt,
            'generated_at' => $closedAt,
            'generated_by' => $actorId,
            'z_counter' => $snapshot['z_counter'],
            'totals_snapshot' => $snapshot,
        ]);

        $fiscalDay->update(['status' => 'CLOSED', 'closed_at' => $closedAt]);

        $auditEvent = AuditEvent::create([
            'store_id' => $fiscalDay->store_id,
            'event_type' => 'Z_READING_GENERATED',
            'actor_user_id' => $actorId,
            'terminal_id' => $terminalId,
            'entity_type' => 'fiscal_day',
            'entity_id' => $fiscalDay->id,
            'after_metadata' => ['fiscal_day_id' => $fiscalDay->id, 'z_counter' => $snapshot['z_counter']],
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $fiscalDay->store_id,
            'terminal_id' => $terminalId,
            'event_type' => 'Z_READING',
            'source_type' => 'z_reading',
            'source_id' => $zReading->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => $snapshot,
        ]);

        return new OperationOutcome(resultType: 'fiscal_day', resultResourceId: $fiscalDay->id);
    }
}
