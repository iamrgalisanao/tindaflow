<?php

namespace App\Services\Shift;

use App\Domain\Exceptions\ShiftNotFoundException;
use App\Domain\Exceptions\ShiftNotOpenException;
use App\Domain\Money;
use App\Models\AuditEvent;
use App\Models\CashMovement;
use App\Models\ElectronicJournalEntry;
use App\Models\Shift;
use App\Models\User;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * openapi.yaml shiftCashMovementCreate. The request body has no
 * authorizer field (only `type`/`amount`/`reason`) -- `authorized_by`
 * is always the acting user's own id, server-derived, never
 * client-submitted. Above `tindaflow.cash_movements.cash_out_
 * authorization_threshold` (a configurable value; see config file for
 * the store owner to set per their own cash-handling policy), a
 * CASH_OUT requires the acting user to personally hold the CASH_OUT
 * capability (invariant #39) -- there is no separate "manager PIN"
 * mechanism in this contract to authorize on someone else's behalf.
 */
final class CashMovementService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the already-shape-validated request body ({type, amount, reason})
     */
    public function create(string $shiftId, string $terminalId, User $actor, string $idempotencyKey, array $payload): CashMovement
    {
        $requestHash = $this->requestHasher->hash($payload);

        $result = $this->idempotencyService->execute(
            $terminalId,
            $idempotencyKey,
            IdempotencyOperationType::CashMovement,
            $requestHash,
            fn () => $this->performCreate($shiftId, $terminalId, $actor, $payload),
        );

        return CashMovement::findOrFail($result->resultResourceId);
    }

    /** @param  array<string, mixed>  $payload */
    private function performCreate(string $shiftId, string $terminalId, User $actor, array $payload): OperationOutcome
    {
        $shift = Shift::where('terminal_id', $terminalId)->find($shiftId);
        if ($shift === null) {
            throw ShiftNotFoundException::forId($shiftId);
        }
        if ($shift->status !== 'OPEN') {
            throw ShiftNotOpenException::forShift($shiftId);
        }

        $amount = Money::fromApiString((string) $payload['amount']);
        $threshold = Money::fromApiString((string) config('tindaflow.cash_movements.cash_out_authorization_threshold'));

        if ($payload['type'] === 'CASH_OUT' && $amount->greaterThanOrEqual($threshold)) {
            Gate::forUser($actor)->authorize('CASH_OUT');
        }

        $movement = CashMovement::create([
            'shift_id' => $shiftId,
            'type' => $payload['type'],
            'amount' => $amount->toApiString(),
            'reason' => $payload['reason'],
            'authorized_by' => $actor->id,
        ]);

        $terminal = DB::table('terminals')->where('id', $terminalId)->first();

        $auditEvent = AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => $payload['type'],
            'actor_user_id' => $actor->id,
            'terminal_id' => $terminalId,
            'entity_type' => 'cash_movement',
            'entity_id' => $movement->id,
            'after_metadata' => ['shift_id' => $shiftId, 'amount' => $amount->toApiString(), 'reason' => $payload['reason']],
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminalId,
            'event_type' => $payload['type'],
            'source_type' => 'cash_movement',
            'source_id' => $movement->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => ['type' => $payload['type'], 'amount' => $amount->toApiString(), 'reason' => $payload['reason']],
        ]);

        return new OperationOutcome(resultType: 'cash_movement', resultResourceId: $movement->id);
    }
}
