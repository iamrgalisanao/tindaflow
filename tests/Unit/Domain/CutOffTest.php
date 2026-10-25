<?php

namespace Tests\Unit\Domain;

use App\Domain\CutOff;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-016 section 5 / CREDIT-004: 1st-15th and 16th-end of month, decided by the store's local calendar date. */
class CutOffTest extends TestCase
{
    private const MANILA = 'Asia/Manila';

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function localInstants(): array
    {
        return [
            'the 1st' => ['2026-10-01 00:00:00', '2026-10-01', '2026-10-15'],
            'the 15th, late evening' => ['2026-10-15 23:59:59', '2026-10-01', '2026-10-15'],
            'the 16th, just after midnight' => ['2026-10-16 00:00:00', '2026-10-16', '2026-10-31'],
            'the 28th of a 31-day month' => ['2026-10-28 12:00:00', '2026-10-16', '2026-10-31'],
            'the 30th of a 30-day month' => ['2026-04-30 18:00:00', '2026-04-16', '2026-04-30'],
            'the 31st' => ['2026-10-31 23:59:59', '2026-10-16', '2026-10-31'],
            'the 28th of a non-leap February' => ['2027-02-28 10:00:00', '2027-02-16', '2027-02-28'],
            'the 29th of a leap February' => ['2028-02-29 10:00:00', '2028-02-16', '2028-02-29'],
            'the 28th of a leap February' => ['2028-02-28 10:00:00', '2028-02-16', '2028-02-29'],
            'the 1st after a month end' => ['2026-11-01 00:00:01', '2026-11-01', '2026-11-15'],
            'new year: 31 December' => ['2026-12-31 23:00:00', '2026-12-16', '2026-12-31'],
            'new year: 1 January' => ['2027-01-01 00:00:00', '2027-01-01', '2027-01-15'],
        ];
    }

    #[DataProvider('localInstants')]
    public function test_the_period_a_local_instant_falls_in(string $local, string $start, string $end): void
    {
        $period = CutOff::containing(CarbonImmutable::parse($local, self::MANILA), self::MANILA);

        $this->assertSame($start, $period->startDate());
        $this->assertSame($end, $period->endDate());
    }

    public function test_midnight_in_manila_is_decided_by_the_manila_date_not_the_utc_date(): void
    {
        // 00:30 on the 16th in Manila is 16:30 on the 15th in UTC: it belongs to the SECOND period.
        $instant = CarbonImmutable::parse('2026-10-15 16:30:00', 'UTC');
        $this->assertSame('2026-10-16', CutOff::containing($instant, self::MANILA)->startDate());
        $this->assertSame('2026-10-01', CutOff::containing($instant, 'UTC')->startDate());

        // 23:30 on the 15th in Manila is 15:30 UTC the same day: still the first period.
        $this->assertSame('2026-10-01', CutOff::containing(CarbonImmutable::parse('2026-10-15 15:30:00', 'UTC'), self::MANILA)->startDate());

        // 00:30 on the 1st of November in Manila is still 31 October in UTC.
        $november = CarbonImmutable::parse('2026-10-31 16:30:00', 'UTC');
        $this->assertSame('2026-11-01', CutOff::containing($november, self::MANILA)->startDate());
        $this->assertSame('2026-11-15', CutOff::containing($november, self::MANILA)->endDate());
    }

    public function test_every_day_of_a_month_has_exactly_one_period_and_the_two_periods_leave_no_gap(): void
    {
        foreach (['2026-02', '2028-02', '2026-04', '2026-10'] as $month) {
            $first = CarbonImmutable::parse("{$month}-01", self::MANILA);
            $starts = [];
            for ($day = $first; $day->month === $first->month; $day = $day->addDay()) {
                $period = CutOff::containing($day->setTime(12, 0), self::MANILA);
                $this->assertTrue($day->toDateString() >= $period->startDate() && $day->toDateString() <= $period->endDate(), $day->toDateString());
                $starts[$period->startDate()] = $period->endDate();
            }
            $this->assertSame(["{$month}-01", "{$month}-16"], array_keys($starts));
            $this->assertSame("{$month}-15", $starts["{$month}-01"]);
            $this->assertSame($first->endOfMonth()->toDateString(), $starts["{$month}-16"]);
        }
    }
}
