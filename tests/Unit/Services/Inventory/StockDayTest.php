<?php

namespace Tests\Unit\Services\Inventory;

use App\Services\Inventory\StockDay;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * ADR-017 section 4, invariants.md STK-002 (client decision 3): the stock day is the store-local date of the instant minus the cutover hour. Asia/Manila has no
 * daylight saving time, so a stock day is always 24 hours, and the instant is converted to the store's clock whatever zone it is in.
 */
class StockDayTest extends TestCase
{
    public function test_the_application_clock_is_manila(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_with_a_five_oclock_cutover_459_belongs_to_yesterday_and_500_to_today(): void
    {
        $this->assertSame('2026-10-27', StockDay::of(CarbonImmutable::parse('2026-10-28 04:59:59', 'Asia/Manila'), 5));
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-28 05:00:00', 'Asia/Manila'), 5));
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-28 23:59:59', 'Asia/Manila'), 5));
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-29 04:59:59', 'Asia/Manila'), 5));
    }

    public function test_with_the_default_cutover_the_stock_day_is_the_calendar_day(): void
    {
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-28 00:00:00', 'Asia/Manila')));
        $this->assertSame('2026-10-27', StockDay::of(CarbonImmutable::parse('2026-10-27 23:59:59', 'Asia/Manila')));
    }

    public function test_an_instant_in_another_zone_is_read_on_the_stores_clock(): void
    {
        // 20:59 UTC on the 27th is 04:59 on the 28th in Manila: still the 27th's stock day under a 5 o'clock cutover.
        $this->assertSame('2026-10-27', StockDay::of(CarbonImmutable::parse('2026-10-27 20:59:00', 'UTC'), 5));
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-27 21:00:00', 'UTC'), 5));
        // 16:00 UTC on the 27th is midnight in Manila: the 28th with no cutover, the 27th with one.
        $this->assertSame('2026-10-28', StockDay::of(CarbonImmutable::parse('2026-10-27 16:00:00', 'UTC')));
        $this->assertSame('2026-10-27', StockDay::of(CarbonImmutable::parse('2026-10-27 16:00:00', 'UTC'), 1));
    }

    public function test_every_hour_of_a_year_boundary_and_a_leap_day_resolves_to_one_date(): void
    {
        $this->assertSame('2026-12-31', StockDay::of(CarbonImmutable::parse('2027-01-01 03:00:00', 'Asia/Manila'), 5));
        $this->assertSame('2028-02-29', StockDay::of(CarbonImmutable::parse('2028-03-01 04:00:00', 'Asia/Manila'), 5));
        // No daylight saving: the day between two 05:00s is always 24 hours.
        $start = StockDay::startsAt('2026-03-29', 5);
        $this->assertSame(24 * 3600, StockDay::startsAt('2026-03-30', 5)->getTimestamp() - $start->getTimestamp());
        $this->assertSame('05:00', $start->format('H:i'));
    }
}
