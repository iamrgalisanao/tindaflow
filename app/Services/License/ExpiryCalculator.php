<?php

namespace App\Services\License;

use DateTimeImmutable;
use DateTimeZone;

/**
 * ADR-018 decision 1: a certificate's `exp` is the renewal time plus 30 days, rounded UP to the next 05:00 in the store's
 * time zone, so it lives 30 to under 31 days and never expires in trading hours. An instant that is already exactly 05:00:00
 * stays put (the ADR said "next 05:00" for certificates and "at or after" for extensions; one rule is used for both).
 */
final class ExpiryCalculator
{
    public const DEFAULT_DAYS = 30;

    public function expiry(DateTimeImmutable $renewedAt, string $timeZone, int $days = self::DEFAULT_DAYS): DateTimeImmutable
    {
        $target = $renewedAt->setTimezone(new DateTimeZone($timeZone))->modify("+{$days} days");
        $boundary = $target->setTime(5, 0, 0, 0);

        return $target > $boundary ? $boundary->modify('+1 day') : $boundary;
    }
}
