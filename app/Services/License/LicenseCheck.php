<?php

namespace App\Services\License;

use DateTimeImmutable;

/** A verdict together with the trusted "now" it was reached at, which the 18-hour cap on an open shift is measured against. */
final class LicenseCheck
{
    /**
     * @param  int|null  $seats  the tills the certificate covers; null when no certificate counts
     * @param  DateTimeImmutable|null  $validUntil  how long the license runs, an offline extension included; null when no certificate counts
     */
    public function __construct(
        public readonly LicenseVerdict $verdict,
        public readonly DateTimeImmutable $trustedNow,
        public readonly ?int $seats = null,
        public readonly ?DateTimeImmutable $validUntil = null,
    ) {}
}
