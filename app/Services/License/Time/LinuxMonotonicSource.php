<?php

namespace App\Services\License\Time;

/**
 * Boot id and uptime from the Linux kernel (the application runs in a Linux container, ADR-002). A new boot id means the
 * kernel restarted, so the uptime counter restarted too and the two readings cannot be subtracted.
 */
final class LinuxMonotonicSource implements MonotonicSource
{
    public function __construct(
        private readonly string $bootIdPath = '/proc/sys/kernel/random/boot_id',
        private readonly string $uptimePath = '/proc/uptime',
    ) {}

    public function reading(): ?MonotonicReading
    {
        $bootId = @file_get_contents($this->bootIdPath);
        $uptime = @file_get_contents($this->uptimePath);
        if ($bootId === false || $uptime === false || trim($bootId) === '') {
            return null;
        }

        $seconds = explode(' ', trim($uptime))[0];
        if (! is_numeric($seconds)) {
            return null;
        }

        return new MonotonicReading(trim($bootId), (int) floor((float) $seconds));
    }
}
