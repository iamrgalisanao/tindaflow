<?php

namespace App\Services\FiscalDay;

use App\Domain\Exceptions\FiscalDayNotFoundException;
use App\Models\AuditEvent;
use App\Models\FiscalDay;
use App\Models\User;
use App\Models\ZReading;
use Illuminate\Support\Facades\DB;

/**
 * Z-reading paper. `original()` renders the plain document (the one printed when the day closes) and records nothing: it is a read.
 * `reprint()` records that a copy was produced -- a Z_READING_REPRINTED audit event naming who asked -- and renders the document marked
 * REPRINT -- COPY. Neither touches the reading, the fiscal day or any counter: the figures come from the stored snapshot.
 */
final class ZReadingPrintService
{
    public function __construct(private readonly ZReadingPrinter $printer) {}

    /** @throws FiscalDayNotFoundException a day of another store, or one with no Z-reading yet, is indistinguishable from a missing one */
    public function find(string $fiscalDayId, string $storeId): ZReading
    {
        $day = FiscalDay::where('store_id', $storeId)->find($fiscalDayId);
        $reading = $day === null ? null : ZReading::where('fiscal_day_id', $day->id)->first();

        return $reading ?? throw FiscalDayNotFoundException::forId($fiscalDayId);
    }

    public function original(string $fiscalDayId, string $storeId): string
    {
        return $this->printer->render($this->find($fiscalDayId, $storeId));
    }

    /** @return array{event: AuditEvent, html: string} */
    public function reprint(string $fiscalDayId, User $actor): array
    {
        $reading = $this->find($fiscalDayId, $actor->store_id);

        $event = DB::transaction(function () use ($reading, $actor) {
            $event = AuditEvent::create([
                'store_id' => $actor->store_id,
                'event_type' => 'Z_READING_REPRINTED',
                'actor_user_id' => $actor->id,
                'terminal_id' => $reading->terminal_id,
                'entity_type' => 'fiscal_day',
                'entity_id' => $reading->fiscal_day_id,
                'after_metadata' => [
                    'fiscal_day_id' => $reading->fiscal_day_id,
                    'z_counter' => $reading->totals_snapshot['z_counter'] ?? $reading->z_counter,
                    'business_date' => $reading->business_date?->toDateString(),
                ],
            ]);

            return $event->refresh();
        });

        return ['event' => $event, 'html' => $this->printer->render($reading, $event->occurred_at)];
    }
}
