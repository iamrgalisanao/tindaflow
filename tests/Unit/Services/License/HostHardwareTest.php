<?php

namespace Tests\Unit\Services\License;

use App\Services\License\HostHardware;
use App\Services\License\HostHardwareReading;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesHostHardware;
use Tests\TestCase;

/**
 * ADR-018 decision 4: the application trusts the host helper's file only while it is well formed and under 26 hours old. Anything else
 * yields no fingerprint (which changes no license state) and stops activation and renewal.
 */
class HostHardwareTest extends TestCase
{
    use FakesHostHardware;

    private function reading(): HostHardwareReading
    {
        return app(HostHardware::class)->read();
    }

    public function test_a_current_well_formed_file_is_fresh_and_gives_the_three_hashes(): void
    {
        $this->fakeHostHardware();

        $reading = $this->reading();

        $this->assertSame(HostHardware::FRESH, $reading->status);
        $this->assertSame(self::testFingerprint(), $reading->fingerprint);
        $this->assertTrue($reading->isFresh());
    }

    public function test_the_file_goes_stale_after_26_hours_and_not_before(): void
    {
        $now = CarbonImmutable::now();

        $this->fakeHostHardware(generatedAt: $now->subHours(26)->addSeconds(5)->format('Y-m-d\TH:i:sP'));
        $this->assertSame(HostHardware::FRESH, $this->reading()->status);

        $this->fakeHostHardware(generatedAt: $now->subHours(26)->subSeconds(5)->format('Y-m-d\TH:i:sP'));
        $stale = $this->reading();
        $this->assertSame(HostHardware::STALE, $stale->status);
        $this->assertNull($stale->fingerprint, 'a stale file yields no fingerprint');
        $this->assertNotNull($stale->generatedAt);
    }

    public function test_a_file_dated_more_than_an_hour_ahead_is_not_fresh(): void
    {
        $now = CarbonImmutable::now();

        $this->fakeHostHardware(generatedAt: $now->addMinutes(59)->format('Y-m-d\TH:i:sP'));
        $this->assertSame(HostHardware::FRESH, $this->reading()->status, 'a host clock a little ahead is tolerated');

        $this->fakeHostHardware(generatedAt: $now->addMinutes(61)->format('Y-m-d\TH:i:sP'));
        $future = $this->reading();
        $this->assertSame(HostHardware::INVALID, $future->status);
        $this->assertNull($future->fingerprint);
        $this->assertNotNull($future->generatedAt, 'kept so a time reset can set this stamp aside');
    }

    public function test_a_missing_file_is_missing(): void
    {
        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);

        $this->assertSame(HostHardware::MISSING, $this->reading()->status);
        $this->assertNull($this->reading()->fingerprint);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidContents(): array
    {
        $fp = ['smbios_uuid' => str_repeat('a', 64), 'board_serial' => str_repeat('b', 64), 'bios_serial' => str_repeat('c', 64)];
        $now = '2026-11-24T09:00:00+08:00';

        return [
            'not json' => ['garbage'],
            'a list' => ['[1,2]'],
            'wrong version' => [json_encode(['v' => 2, 'generated_at' => $now, 'fp' => $fp])],
            'no timestamp' => [json_encode(['v' => 1, 'fp' => $fp])],
            'timestamp without an offset' => [json_encode(['v' => 1, 'generated_at' => '2026-11-24 09:00:00', 'fp' => $fp])],
            'only two components' => [json_encode(['v' => 1, 'generated_at' => $now, 'fp' => array_slice($fp, 0, 2)])],
            'an extra component' => [json_encode(['v' => 1, 'generated_at' => $now, 'fp' => $fp + ['mac' => str_repeat('d', 64)]])],
            'an upper-case hash' => [json_encode(['v' => 1, 'generated_at' => $now, 'fp' => ['smbios_uuid' => strtoupper($fp['smbios_uuid'])] + $fp])],
            'a short hash' => [json_encode(['v' => 1, 'generated_at' => $now, 'fp' => ['smbios_uuid' => 'abc'] + $fp])],
        ];
    }

    #[DataProvider('invalidContents')]
    public function test_a_malformed_file_is_invalid_and_gives_no_fingerprint(string $content): void
    {
        $path = $this->fakeHostHardware();
        file_put_contents($path, $content);

        $reading = $this->reading();

        $this->assertSame(HostHardware::INVALID, $reading->status);
        $this->assertNull($reading->fingerprint);
    }

    public function test_a_byte_order_mark_and_any_component_order_are_tolerated(): void
    {
        $path = $this->fakeHostHardware();
        $fp = self::testFingerprint();
        file_put_contents($path, "\xEF\xBB\xBF".json_encode(['v' => 1, 'generated_at' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP'), 'fp' => array_reverse($fp, true)]));

        $this->assertSame(HostHardware::FRESH, $this->reading()->status, 'Windows PowerShell 5.1 can write a BOM; another writer may order keys differently');
    }
}
