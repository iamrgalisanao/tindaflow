<?php

namespace App\Services\License;

use App\Services\License\Contract\Certificate;
use DateTimeImmutable;

/**
 * The license state machine (ADR-018 decision 2) as a pure function: a verified certificate (or none), what this
 * installation knows about itself, and whether the build carries a vendor key. It reads no clock, no database and no file,
 * so every boundary can be tested by choosing the instants.
 *
 *   no vendor key in the build ........ LEGACY (nothing enforced, nothing changes for existing installs)
 *   vendor key, no certificate ........ LEGACY if an ADR-014 license is still present, else NOT_ACTIVATED
 *   LOCKED (first match wins) ......... REVOKED, CHAIN_INVALID, FINGERPRINT_MISMATCH, CLOCK_ROLLBACK
 *   otherwise, on trusted time ........ ACTIVE until 7 days before exp, WARNING until exp, EXPIRED after
 *
 * A certificate with mode=report computes the same state but does not enforce.
 */
final class LicenseEvaluator
{
    /** The clock may be this far behind the high-water mark (a dead CMOS battery, a minute of drift) before it is a rollback. */
    private const ROLLBACK_TOLERANCE_SECONDS = 3600;

    private const WARNING_WINDOW = '-7 days';

    public function __construct(private readonly FingerprintMatcher $fingerprints = new FingerprintMatcher) {}

    public function evaluate(?Certificate $certificate, LocalFacts $facts, bool $enforcementConfigured, bool $legacyLicensePresent = false): LicenseVerdict
    {
        if (! $enforcementConfigured) {
            return new LicenseVerdict(LicenseState::Legacy, false);
        }

        if ($certificate === null) {
            return $legacyLicensePresent
                ? new LicenseVerdict(LicenseState::Legacy, false)
                : new LicenseVerdict(LicenseState::NotActivated, true);
        }

        $enforcing = $certificate->mode === Certificate::MODE_ENFORCE;

        $lock = $this->lockReason($certificate, $facts);
        if ($lock !== null) {
            return new LicenseVerdict(LicenseState::Locked, $enforcing, $lock);
        }

        return new LicenseVerdict($this->timeState($certificate->exp, $facts->trustedNow()), $enforcing);
    }

    private function lockReason(Certificate $certificate, LocalFacts $facts): ?RefusalReason
    {
        if ($facts->revoked) {
            return RefusalReason::Revoked;
        }

        if ($facts->chainInvalid) {
            return RefusalReason::ChainInvalid;
        }

        if ($facts->observedFingerprint !== null
            && ! $this->fingerprints->isAcceptable($this->fingerprints->matchedCount($certificate->fingerprint, $facts->observedFingerprint))) {
            return RefusalReason::FingerprintMismatch;
        }

        $mark = $facts->trustedTimeHwm;
        if ($mark !== null && $facts->wallNow->getTimestamp() < $mark->getTimestamp() - self::ROLLBACK_TOLERANCE_SECONDS) {
            return RefusalReason::ClockRollback;
        }

        return null;
    }

    private function timeState(DateTimeImmutable $exp, DateTimeImmutable $trustedNow): LicenseState
    {
        return match (true) {
            $trustedNow > $exp => LicenseState::Expired,
            $trustedNow > $exp->modify(self::WARNING_WINDOW) => LicenseState::Warning,
            default => LicenseState::Active,
        };
    }
}
