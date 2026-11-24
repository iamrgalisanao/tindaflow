<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * ADR-018 decision 11: the `scheduler` container that runs the license heartbeat and renewal, and the liveness marker its health check
 * reads. The compose file is read as data (a real `docker compose up` is out of reach in this suite), so what is pinned here is the shape
 * that keeps the service safe: the restricted database role, the shared storage, the read-only host folder, a start-up renewal that cannot
 * stop the scheduler, and a health check that fails when the scheduler stops.
 */
class SchedulerServiceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function service(string $name = 'scheduler'): array
    {
        $compose = Yaml::parseFile(base_path('docker/compose.yaml'));

        return $compose['services'][$name];
    }

    private function scheduled(string $needle): Event
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, $needle) || $event->description === $needle);

        $this->assertNotNull($event, "nothing scheduled matches {$needle}");

        return $event;
    }

    public function test_it_runs_the_same_image_as_the_app_with_the_restricted_database_role(): void
    {
        $scheduler = $this->service();

        $this->assertSame($this->service('app')['image'], $scheduler['image'], 'one image, so a version change moves them together');
        $this->assertSame('', $scheduler['environment']['DB_OWNER_USERNAME']);
        $this->assertSame('', $scheduler['environment']['DB_OWNER_PASSWORD'], 'the owner credentials are blanked exactly as in the app service');
        $this->assertSame(['backend'], $scheduler['networks'], 'it needs the database and outbound HTTPS, not the web network');
        $this->assertSame('unless-stopped', $scheduler['restart']);
    }

    public function test_it_tries_a_renewal_at_start_up_and_then_runs_the_scheduler_whatever_the_renewal_did(): void
    {
        $command = implode(' ', $this->service()['command']);

        $this->assertStringContainsString('runuser -u www-data', $command, 'artisan never runs as root');
        $this->assertMatchesRegularExpression('/tindaflow:license-renew;\s*exec php artisan schedule:work/', $command, 'a failed renewal (no network, not activated) must not stop the scheduler');
    }

    public function test_it_shares_the_storage_volume_and_reads_the_host_folder_read_only(): void
    {
        $volumes = $this->service()['volumes'];

        $this->assertContains('app-storage:/var/www/html/storage', $volumes);
        $this->assertContains('${TINDAFLOW_HOST_DIR:-./host}:/var/tindaflow-host:ro', $volumes);
        $this->assertContains('${TINDAFLOW_HOST_DIR:-./host}:/var/tindaflow-host:ro', $this->service('app')['volumes'], 'the app and the scheduler read the same hardware file');
    }

    public function test_it_starts_only_after_the_app_is_healthy_which_is_after_the_migrations(): void
    {
        $this->assertSame('service_healthy', $this->service()['depends_on']['app']['condition']);
        $this->assertSame('service_completed_successfully', $this->service('app')['depends_on']['migrate']['condition']);
    }

    public function test_its_health_check_reads_the_marker_the_schedule_touches_every_minute(): void
    {
        $test = implode(' ', $this->service()['healthcheck']['test']);

        $this->assertStringContainsString('/var/www/html/storage/framework/scheduler-alive', $test);
        $this->assertStringContainsString('-mmin -3', $test);

        $alive = $this->scheduled('scheduler-alive');
        $this->assertSame('* * * * *', $alive->expression);
    }

    public function test_the_liveness_marker_is_really_touched(): void
    {
        $path = storage_path('framework/scheduler-alive');
        @unlink($path);

        $this->scheduled('scheduler-alive')->run($this->app);

        $this->assertFileExists($path);
        $this->assertEqualsWithDelta(time(), filemtime($path), 5);
        @unlink($path);
    }

    public function test_the_overlap_locks_expire_in_minutes_so_a_killed_container_cannot_block_the_license_for_a_day(): void
    {
        $heartbeat = $this->scheduled('tindaflow:license-heartbeat');
        $renew = $this->scheduled('tindaflow:license-renew');

        $this->assertTrue($heartbeat->withoutOverlapping);
        $this->assertSame(5, $heartbeat->expiresAt);
        $this->assertTrue($renew->withoutOverlapping);
        $this->assertGreaterThan(10 + 5, $renew->expiresAt, 'longer than the up-to-ten-minute jitter plus the request');
        $this->assertLessThan(60, $renew->expiresAt, 'and shorter than the hourly interval');
    }
}
