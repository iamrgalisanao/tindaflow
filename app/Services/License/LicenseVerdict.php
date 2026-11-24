<?php

namespace App\Services\License;

use DateTimeImmutable;

/**
 * The answer to "what is this installation's license state, and what would it refuse?" (ADR-018 decision 2).
 *
 * The refusal methods say what WOULD be refused, whether or not the certificate enforces, so a pilot in report mode can log
 * "would have refused" without refusing. The caller refuses only when {@see $enforcing} is true.
 */
final class LicenseVerdict
{
    /** The longest an open shift keeps selling once the license is EXPIRED, LOCKED or NOT_ACTIVATED. */
    public const OPEN_SHIFT_CAP_SECONDS = 18 * 3600;

    public function __construct(
        public readonly LicenseState $state,
        public readonly bool $enforcing,
        private readonly ?RefusalReason $lockReason = null,
    ) {}

    /** Why a new shift would be refused, or null when it may open. */
    public function shiftOpenRefusal(): ?RefusalReason
    {
        return match ($this->state) {
            LicenseState::Legacy, LicenseState::Active, LicenseState::Warning => null,
            LicenseState::NotActivated => RefusalReason::NotActivated,
            LicenseState::Expired => RefusalReason::Expired,
            LicenseState::Locked => $this->lockReason,
        };
    }

    /**
     * SHIFT_TOO_LONG once an open shift is older than 18 hours on trusted time, and only while the license is EXPIRED, LOCKED or
     * NOT_ACTIVATED. In ACTIVE, WARNING and LEGACY a shift is never capped, so a normal service is never interrupted. (A
     * NOT_ACTIVATED install with a shift already open is treated like EXPIRED: the ADR names only the other two, and
     * leaving an unactivated installation uncapped would defeat the point of activating.)
     */
    public function saleRefusal(DateTimeImmutable $openedAt, DateTimeImmutable $trustedNow): ?RefusalReason
    {
        $capped = in_array($this->state, [LicenseState::Expired, LicenseState::Locked, LicenseState::NotActivated], true);

        return $capped && $trustedNow->getTimestamp() - $openedAt->getTimestamp() > self::OPEN_SHIFT_CAP_SECONDS
            ? RefusalReason::ShiftTooLong
            : null;
    }
}
