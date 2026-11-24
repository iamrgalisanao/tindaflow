<?php

namespace App\Services\License;

use DateTimeImmutable;

/** What the host helper's file said, and whether it can be trusted. `fingerprint` is non-null only when `status` is FRESH. */
final class HostHardwareReading
{
    /** @param  array<string, string>|null  $fingerprint  component => SHA-256 hex */
    public function __construct(
        public readonly string $status,
        public readonly ?array $fingerprint = null,
        public readonly ?DateTimeImmutable $generatedAt = null,
    ) {}

    public function isFresh(): bool
    {
        return $this->status === HostHardware::FRESH;
    }
}
