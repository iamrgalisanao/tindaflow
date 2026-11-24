<?php

namespace Tests\Concerns;

use Carbon\CarbonImmutable;

/**
 * Writes the file the Windows host helper would write (ADR-018 decision 4) to a temporary path and points the configuration at it.
 * The default hashes are the TEST hashes every license test certificate carries, so a certificate and a fresh file agree.
 */
trait FakesHostHardware
{
    /** @return array<string, string> */
    protected static function testFingerprint(): array
    {
        return [
            'smbios_uuid' => hash('sha256', 'tindaflow-fp-v1:smbios_uuid:TEST-UUID'),
            'board_serial' => hash('sha256', 'tindaflow-fp-v1:board_serial:TEST-BOARD'),
            'bios_serial' => hash('sha256', 'tindaflow-fp-v1:bios_serial:TEST-BIOS'),
        ];
    }

    /**
     * @param  array<string, string>|null  $fingerprint
     * @param  string|null  $generatedAt  an offset ISO time; default now
     * @return string the file's path
     */
    protected function fakeHostHardware(?array $fingerprint = null, ?string $generatedAt = null): string
    {
        $path = sys_get_temp_dir().'/tindaflow-test-hardware-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'v' => 1,
            'generated_at' => $generatedAt ?? CarbonImmutable::now()->format('Y-m-d\TH:i:sP'),
            'fp' => $fingerprint ?? self::testFingerprint(),
        ]));
        config(['tindaflow.license.hardware_file' => $path]);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
