<?php

namespace App\Services\License;

use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Reads the hardware file the Windows host helper writes (ADR-018 decision 4): `{"v":1,"generated_at":...,"fp":{smbios_uuid,board_serial,bios_serial}}`
 * with the three SHA-256 hashes. The file is unsigned and can be copied with the stack (a recorded residual risk), so this only decides
 * whether to trust it as FRESH: well formed and generated within the last 26 hours. A missing, invalid or older file yields no fingerprint,
 * which changes no license state but stops activation and renewal (HOST_HELPER_STALE). `generated_at` is the host's own clock, a second
 * clock beside the container's.
 */
final class HostHardware
{
    public const FRESH = 'FRESH';

    public const STALE = 'STALE';

    public const MISSING = 'MISSING';

    public const INVALID = 'INVALID';

    public const MAX_AGE_SECONDS = 26 * 3600;

    private const COMPONENTS = ['smbios_uuid', 'board_serial', 'bios_serial'];

    private const TIME_FORMAT = 'Y-m-d\TH:i:sP';

    public function path(): string
    {
        return (string) config('tindaflow.license.hardware_file');
    }

    public function read(?DateTimeImmutable $now = null): HostHardwareReading
    {
        $now ??= CarbonImmutable::now();
        $raw = is_file($this->path()) ? @file_get_contents($this->path()) : false;
        if ($raw === false) {
            return new HostHardwareReading(self::MISSING);
        }

        $data = json_decode(ltrim($raw, "\xEF\xBB\xBF"), true);
        $fingerprint = is_array($data) ? ($data['fp'] ?? null) : null;
        $generatedAt = is_array($data) && is_string($data['generated_at'] ?? null) ? DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $data['generated_at']) : false;

        $valid = is_array($data) && ($data['v'] ?? null) === 1
            && $generatedAt !== false && $generatedAt->format(self::TIME_FORMAT) === $data['generated_at']
            && is_array($fingerprint) && count($fingerprint) === 3 && array_diff(self::COMPONENTS, array_keys($fingerprint)) === []
            && collect($fingerprint)->every(fn ($hash) => is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1);

        if (! $valid) {
            return new HostHardwareReading(self::INVALID);
        }

        if ($now->getTimestamp() - $generatedAt->getTimestamp() > self::MAX_AGE_SECONDS) {
            return new HostHardwareReading(self::STALE, null, $generatedAt);
        }

        return new HostHardwareReading(self::FRESH, $fingerprint, $generatedAt);
    }
}
