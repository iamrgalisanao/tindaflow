<?php

namespace App\Services\Shift;

use App\Domain\Exceptions\ShiftNotFoundException;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Shift;
use App\Models\XReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * openapi.yaml shiftXReadingCreate. On-demand, interim accountability
 * pull (invariant #43) -- never changes `shift.status` or resets any
 * total, and takes no request body (no `declared_cash` to compute
 * `variance` against, so it's always null here). No Idempotency-Key and
 * no 409 response exist in the frozen contract for this operation, so a
 * shift that already exists but is CLOSED is still readable here rather
 * than rejected -- only a shift that doesn't belong to this terminal at
 * all is a 404.
 */
final class ShiftXReadingService
{
    public function __construct(private readonly ShiftReadingAggregator $aggregator) {}

    public function generate(string $shiftId, string $terminalId, string $actorId): XReading
    {
        $shift = Shift::where('terminal_id', $terminalId)->find($shiftId);
        if ($shift === null) {
            throw ShiftNotFoundException::forId($shiftId);
        }

        $generatedAt = Carbon::now();
        $snapshot = $this->aggregator->aggregate($shift);

        $xReading = XReading::create([
            'terminal_id' => $terminalId,
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'from_at' => $shift->opened_at,
            'to_at' => $generatedAt,
            'generated_at' => $generatedAt,
            'generated_by' => $actorId,
            'is_closing_reading' => false,
            'totals_snapshot' => $snapshot,
        ]);

        $terminal = DB::table('terminals')->where('id', $terminalId)->first();

        $auditEvent = AuditEvent::create([
            'store_id' => $terminal->store_id,
            'event_type' => 'X_READING_GENERATED',
            'actor_user_id' => $actorId,
            'terminal_id' => $terminalId,
            'entity_type' => 'x_reading',
            'entity_id' => $xReading->id,
            'after_metadata' => ['shift_id' => $shift->id],
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminalId,
            'event_type' => 'X_READING',
            'source_type' => 'x_reading',
            'source_id' => $xReading->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => $snapshot,
        ]);

        return $xReading;
    }
}
