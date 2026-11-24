<?php

namespace App\Services\License\Time;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The trusted-time high-water mark of ADR-018 decision 3. It only moves forward: from each heartbeat, and from any observed
 * moment of record (a certificate's `iat`, a shift open or close). Reading it is a plain select; writing is the heartbeat
 * command's job and never happens inside a checkout transaction.
 *
 * A heartbeat adds the larger of the wall-clock delta and the monotonic delta since the last heartbeat, in whole seconds. A
 * clock rolled back while the stack runs has no wall delta but the monotonic clock keeps counting, so the mark keeps moving
 * and the rolled-back clock falls behind it. Time while the stack is not running is not counted by the monotonic part (the
 * boot id changes), only by the wall clock; that residual is accepted in the ADR.
 */
final class TrustedClock
{
    public function __construct(private readonly MonotonicSource $monotonic) {}

    public function highWaterMark(): ?DateTimeImmutable
    {
        $value = DB::table('license_state')->where('id', 1)->value('trusted_time_hwm');

        return $value === null ? null : new DateTimeImmutable($value);
    }

    /** Moves the mark to a moment of record if that is later than it; never back. */
    public function advanceTo(DateTimeImmutable $observed): void
    {
        $this->ensureRow($observed);

        DB::table('license_state')
            ->where('id', 1)
            ->where(fn ($query) => $query->whereNull('trusted_time_hwm')->orWhere('trusted_time_hwm', '<', self::stamp($observed)))
            ->update(['trusted_time_hwm' => self::stamp($observed), 'updated_at' => self::stamp($observed)]);
    }

    /**
     * Sets the mark to the control plane's time, which may be EARLIER than the mark (a clock once set into the future has pushed it
     * ahead). Only an online renewal's signed `iat` may call this (ADR-018 decision 3), and the caller audits it. Returns the mark
     * it replaced, or null if there was none.
     */
    public function rebaseline(DateTimeImmutable $controlPlaneTime): ?DateTimeImmutable
    {
        $previous = $this->highWaterMark();
        $this->ensureRow($controlPlaneTime);

        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => self::stamp($controlPlaneTime), 'updated_at' => self::stamp($controlPlaneTime)]);

        return $previous;
    }

    /** One heartbeat. Returns the mark after it. */
    public function heartbeat(DateTimeImmutable $wallNow): DateTimeImmutable
    {
        $this->ensureRow($wallNow);
        $reading = $this->monotonic->reading();

        return DB::transaction(function () use ($wallNow, $reading): DateTimeImmutable {
            $row = DB::table('license_state')->where('id', 1)->lockForUpdate()->first();

            $mark = $row->trusted_time_hwm === null ? $wallNow : new DateTimeImmutable($row->trusted_time_hwm);
            if ($row->heartbeat_at !== null) {
                $wallDelta = max(0, $wallNow->getTimestamp() - (new DateTimeImmutable($row->heartbeat_at))->getTimestamp());
                $sameBoot = $reading !== null && $row->heartbeat_boot_id === $reading->bootId && $reading->uptimeSeconds >= (int) $row->heartbeat_uptime_seconds;
                $monotonicDelta = $sameBoot ? $reading->uptimeSeconds - (int) $row->heartbeat_uptime_seconds : 0;
                $mark = $mark->modify('+'.max($wallDelta, $monotonicDelta).' seconds');
            }

            DB::table('license_state')->where('id', 1)->update([
                'trusted_time_hwm' => self::stamp($mark),
                'heartbeat_at' => self::stamp($wallNow),
                'heartbeat_boot_id' => $reading?->bootId,
                'heartbeat_uptime_seconds' => $reading?->uptimeSeconds,
                'updated_at' => self::stamp($wallNow),
            ]);

            return $mark;
        });
    }

    private function ensureRow(DateTimeImmutable $now): void
    {
        DB::table('license_state')->insertOrIgnore(['id' => 1, 'created_at' => self::stamp($now), 'updated_at' => self::stamp($now)]);
    }

    /** An explicit offset, so the database never has to guess which time zone a bare date-time was meant in. */
    private static function stamp(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:sP');
    }
}
