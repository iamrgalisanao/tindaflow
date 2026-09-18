<?php

namespace App\Services\Shift;

use App\Domain\Exceptions\ShiftAlreadyOpenException;
use App\Models\FiscalDay;
use App\Models\Shift;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\DatabaseExceptionTranslator;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * openapi.yaml shiftOpen. api-design.md SS10's FiscalDay auto-open
 * policy: resolves (or atomically creates, in the same transaction) the
 * terminal's currently-OPEN fiscal_day before opening the shift itself
 * -- a V1 operating decision, not a BIR requirement. Global Lock Order
 * (architecture.md): Shift is checked/locked before FiscalDay, matching
 * the frozen shift(0) -> fiscal_day(1) ordinal order exactly.
 */
final class ShiftOpenService
{
    private const SHIFTS_ONE_OPEN_PER_TERMINAL = 'shifts_one_open_per_terminal';

    private const SHIFTS_ONE_OPEN_PER_CASHIER = 'shifts_one_open_per_cashier';

    private const FISCAL_DAYS_ONE_OPEN_PER_TERMINAL = 'fiscal_days_one_open_per_terminal';

    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly DatabaseExceptionTranslator $exceptionTranslator = new DatabaseExceptionTranslator,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the already-shape-validated request body ({opening_cash})
     * @return array{shift: Shift, fiscal_day_was_opened: bool}
     */
    public function open(string $terminalId, string $cashierId, string $idempotencyKey, array $payload): array
    {
        $requestHash = $this->requestHasher->hash($payload);

        $result = $this->idempotencyService->execute(
            $terminalId,
            $idempotencyKey,
            IdempotencyOperationType::ShiftOpen,
            $requestHash,
            fn () => $this->performOpen($terminalId, $cashierId, $payload),
        );

        $shift = Shift::with('fiscalDay')->findOrFail($result->resultResourceId);

        // Honest re-derivation, correct on both a fresh execution and an
        // idempotent replay (no separate flag is persisted anywhere).
        // Deliberately NOT a timestamp comparison: `opened_at` columns
        // are TIMESTAMPTZ(0) (whole-second precision, Stage 5 schema),
        // so two shiftOpen calls a few milliseconds apart can truncate
        // to the identical second and falsely compare equal. HasUuids'
        // UUIDv7 ids are time-sortable, so "this shift is the earliest
        // (by id) referencing this fiscal_day" is a precise, robust
        // fact instead -- true if and only if this shift's own open is
        // what caused the fiscal_day to exist.
        $earliestShiftId = Shift::where('fiscal_day_id', $shift->fiscal_day_id)->orderBy('id')->value('id');
        $fiscalDayWasOpened = $earliestShiftId === $shift->id;

        return ['shift' => $shift, 'fiscal_day_was_opened' => $fiscalDayWasOpened];
    }

    private function performOpen(string $terminalId, string $cashierId, array $payload): OperationOutcome
    {
        $lockOrder = new GlobalLockOrder;
        $openedAt = Carbon::now();

        // Invariants #33/#34: at most one OPEN shift per terminal AND per
        // cashier (across all terminals) -- one locked read covers both,
        // since either match is an equally valid rejection.
        $lockOrder->acquire(LockableResource::Shift);
        $existingShift = Shift::where('status', 'OPEN')
            ->where(fn ($query) => $query->where('terminal_id', $terminalId)->orWhere('cashier_id', $cashierId))
            ->lockForUpdate()
            ->first();
        if ($existingShift !== null) {
            throw ShiftAlreadyOpenException::make();
        }

        // Invariant #32: at most one OPEN fiscal_day per terminal --
        // reuse it if one already exists, otherwise open a new one
        // atomically in this same transaction (api-design.md SS10).
        $lockOrder->acquire(LockableResource::FiscalDay);
        $fiscalDay = FiscalDay::where('terminal_id', $terminalId)->where('status', 'OPEN')->lockForUpdate()->first();

        if ($fiscalDay === null) {
            $terminal = DB::table('terminals')->where('id', $terminalId)->first();

            $this->exceptionTranslator->registerConstraint(
                self::FISCAL_DAYS_ONE_OPEN_PER_TERMINAL,
                fn () => ShiftAlreadyOpenException::make(),
            );

            try {
                $fiscalDay = FiscalDay::create([
                    'store_id' => $terminal->store_id,
                    'terminal_id' => $terminalId,
                    'business_date' => $openedAt->toDateString(),
                    'opened_at' => $openedAt,
                    'status' => 'OPEN',
                ]);
            } catch (QueryException $e) {
                $this->exceptionTranslator->translate($e);
            }
        }

        $this->exceptionTranslator->registerConstraint(
            self::SHIFTS_ONE_OPEN_PER_TERMINAL,
            fn () => ShiftAlreadyOpenException::make(),
        );
        $this->exceptionTranslator->registerConstraint(
            self::SHIFTS_ONE_OPEN_PER_CASHIER,
            fn () => ShiftAlreadyOpenException::make(),
        );

        try {
            $shift = Shift::create([
                'terminal_id' => $terminalId,
                'fiscal_day_id' => $fiscalDay->id,
                'cashier_id' => $cashierId,
                'opening_cash' => (string) $payload['opening_cash'],
                'opened_at' => $openedAt,
                'status' => 'OPEN',
            ]);
        } catch (QueryException $e) {
            $this->exceptionTranslator->translate($e);
        }

        return new OperationOutcome(resultType: 'shift', resultResourceId: $shift->id);
    }
}
