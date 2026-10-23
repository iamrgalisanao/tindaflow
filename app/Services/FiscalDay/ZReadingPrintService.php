<?php

namespace App\Services\FiscalDay;

use App\Domain\Exceptions\FiscalDayNotFoundException;
use App\Models\AuditEvent;
use App\Models\FiscalDay;
use App\Models\User;
use App\Models\ZReading;
use App\Models\ZReadingPrint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Z-reading paper, with the SERVER deciding which printout is the original. The first print of a reading is the plain original and
 * is recorded (`z_reading_prints`, insert-only, unique per reading, so two simultaneous first prints cannot both be originals) with
 * a Z_READING_PRINTED audit event. Every later print is a copy: marked REPRINT -- COPY and recorded as Z_READING_REPRINTED. Nobody can
 * fetch a second unmarked original by calling the API directly, which the earlier "original is a plain read" design allowed.
 *
 * Nothing here touches the reading, the fiscal day or any counter: the figures come from the stored snapshot.
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

    /** @return array{event: AuditEvent, html: string, is_reprint: bool} */
    public function print(string $fiscalDayId, User $actor): array
    {
        $reading = $this->find($fiscalDayId, $actor->store_id);

        $original = $this->claimOriginal($reading, $actor);

        $event = DB::transaction(function () use ($reading, $actor, $original) {
            $event = AuditEvent::create([
                'store_id' => $actor->store_id,
                'event_type' => $original ? 'Z_READING_PRINTED' : 'Z_READING_REPRINTED',
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

        return [
            'event' => $event,
            'html' => $this->printer->render($reading, $original ? null : $event->occurred_at),
            'is_reprint' => ! $original,
        ];
    }

    /** True for exactly one caller per reading, however many ask at once: the unique constraint is the arbiter. */
    private function claimOriginal(ZReading $reading, User $actor): bool
    {
        try {
            DB::transaction(fn () => ZReadingPrint::create(['store_id' => $actor->store_id, 'z_reading_id' => $reading->id, 'printed_by' => $actor->id]));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
