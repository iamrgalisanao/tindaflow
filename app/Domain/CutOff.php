<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * ADR-016 section 5 / invariants.md CREDIT-004: the credit cut-off period a sale falls in. Two periods a month, both in the store's
 * business calendar (its timezone, Asia/Manila): the 1st to the 15th, and the 16th to the LAST day of the month (28, 29, 30 or 31;
 * client decision 2026-10-25). The period is decided by the LOCAL calendar date of the instant, never by its UTC date, so a sale at
 * 00:30 on the 16th in Manila (16:30 on the 15th in UTC) belongs to the second period.
 */
final class CutOff
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public static function containing(CarbonInterface $instant, string $timezone): self
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($timezone)->startOfDay();

        return $local->day <= 15
            ? new self($local->day(1), $local->day(15))
            : new self($local->day(16), $local->endOfMonth()->startOfDay());
    }

    /** The period that STARTS on $date ("YYYY-MM-DD", the 1st or the 16th), or null when $date is not the first day of a cut-off period. */
    public static function startingOn(string $date, string $timezone): ?self
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }
        $period = self::containing($parsed, $timezone);

        return $period->startDate() === $date ? $period : null;
    }

    /** "Oct 1-15, 2026" / "Oct 16-31, 2026". */
    public function label(): string
    {
        return $this->start->format('M').' '.$this->start->day.'-'.$this->end->day.', '.$this->start->year;
    }

    /** The first date of the period, "YYYY-MM-DD". */
    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    /** The last date of the period (the 15th, or the last day of the month), "YYYY-MM-DD". */
    public function endDate(): string
    {
        return $this->end->toDateString();
    }
}
