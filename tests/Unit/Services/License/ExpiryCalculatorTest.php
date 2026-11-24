<?php

namespace Tests\Unit\Services\License;

use App\Services\License\ExpiryCalculator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-018 decision 1: `exp` is the renewal time plus 30 days, rounded UP to the next 05:00 in the store's time zone, so a
 * certificate lives 30 to under 31 days and expiry never falls in trading hours. An instant that is exactly 05:00:00
 * stays where it is (the one rule the ADR left ambiguous; fixed here and recorded in the pull request).
 */
class ExpiryCalculatorTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function renewals(): array
    {
        return [
            'mid-morning rounds up to the next 05:00' => ['2026-11-24T09:00:00+08:00', '2026-12-25T05:00:00+08:00'],
            'one second before 05:00 rounds up to that same 05:00' => ['2026-11-24T04:59:59+08:00', '2026-12-24T05:00:00+08:00'],
            'exactly 05:00:00 stays' => ['2026-11-24T05:00:00+08:00', '2026-12-24T05:00:00+08:00'],
            'one second after 05:00 rounds up to the next day' => ['2026-11-24T05:00:01+08:00', '2026-12-25T05:00:00+08:00'],
            'late evening' => ['2026-11-24T23:59:59+08:00', '2026-12-25T05:00:00+08:00'],
            'the same instant given in UTC' => ['2026-11-24T01:00:00+00:00', '2026-12-25T05:00:00+08:00'],
        ];
    }

    #[DataProvider('renewals')]
    public function test_thirty_days_rounded_up_to_0500_in_the_store_time_zone(string $renewedAt, string $expected): void
    {
        $exp = (new ExpiryCalculator)->expiry(new DateTimeImmutable($renewedAt), 'Asia/Manila');

        $this->assertSame($expected, $exp->format('c'));
    }

    public function test_the_certificate_never_lives_less_than_30_days_or_31_days_or_more(): void
    {
        foreach (['00:00:00', '04:59:59', '05:00:00', '05:00:01', '12:00:00', '23:59:59'] as $time) {
            $renewed = new DateTimeImmutable("2026-11-24T{$time}+08:00");
            $life = (new ExpiryCalculator)->expiry($renewed, 'Asia/Manila')->getTimestamp() - $renewed->getTimestamp();

            $this->assertGreaterThanOrEqual(30 * 86400, $life, $time);
            $this->assertLessThan(31 * 86400, $life, $time);
        }
    }

    public function test_the_number_of_days_can_be_given_for_extensions(): void
    {
        $exp = (new ExpiryCalculator)->expiry(new DateTimeImmutable('2026-12-25T05:00:00+08:00'), 'Asia/Manila', 30);

        $this->assertSame('2027-01-24T05:00:00+08:00', $exp->format('c'));
    }
}
