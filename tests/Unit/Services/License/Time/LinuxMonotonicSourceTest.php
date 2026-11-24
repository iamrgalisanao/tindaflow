<?php

namespace Tests\Unit\Services\License\Time;

use App\Services\License\Time\LinuxMonotonicSource;
use PHPUnit\Framework\TestCase;

class LinuxMonotonicSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/tindaflow-mono-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
    }

    private function source(?string $bootId, ?string $uptime): LinuxMonotonicSource
    {
        $bootIdPath = $this->dir.'/boot_id';
        $uptimePath = $this->dir.'/uptime';
        if ($bootId !== null) {
            file_put_contents($bootIdPath, $bootId);
        }
        if ($uptime !== null) {
            file_put_contents($uptimePath, $uptime);
        }

        return new LinuxMonotonicSource($bootIdPath, $uptimePath);
    }

    public function test_it_reads_the_boot_id_and_whole_seconds_of_uptime(): void
    {
        $reading = $this->source("3f2b1c9e-0000-4000-8000-123456789abc\n", "12345.67 98765.43\n")->reading();

        $this->assertSame('3f2b1c9e-0000-4000-8000-123456789abc', $reading->bootId);
        $this->assertSame(12345, $reading->uptimeSeconds);
    }

    public function test_a_host_without_the_files_has_no_monotonic_clock(): void
    {
        $this->assertNull($this->source(null, null)->reading());
        $this->assertNull($this->source('some-boot', null)->reading());
    }

    public function test_unreadable_content_is_no_reading(): void
    {
        $this->assertNull($this->source("\n", '10.0 1.0')->reading());
        $this->assertNull($this->source('some-boot', 'garbage')->reading());
    }
}
