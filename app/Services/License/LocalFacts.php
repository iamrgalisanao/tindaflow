<?php

namespace App\Services\License;

use DateTimeImmutable;

/**
 * What this installation knows about itself when it asks "may I open a shift?": the clock, the trusted-time high-water
 * mark, the hardware hashes the host helper last wrote, and the two verdicts the control plane can hand down. Plain data,
 * so the evaluator stays a pure function.
 */
final class LocalFacts
{
    /**
     * @param  array<string, string>|null  $observedFingerprint  component => hash; null when the host helper's file is missing or stale, which changes no state
     */
    public function __construct(
        public readonly DateTimeImmutable $wallNow,
        public readonly ?DateTimeImmutable $trustedTimeHwm = null,
        public readonly ?array $observedFingerprint = null,
        public readonly bool $revoked = false,
        public readonly bool $chainInvalid = false,
    ) {}

    /** The later of the clock and the high-water mark: a clock set back cannot make time run backwards (ADR-018 decision 3). */
    public function trustedNow(): DateTimeImmutable
    {
        return $this->trustedTimeHwm !== null && $this->trustedTimeHwm > $this->wallNow ? $this->trustedTimeHwm : $this->wallNow;
    }
}
