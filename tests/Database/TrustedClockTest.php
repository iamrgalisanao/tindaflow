<?php

namespace Tests\Database;

use App\Services\License\Contract\Certificate;
use App\Services\License\LicenseEvaluator;
use App\Services\License\LicenseState;
use App\Services\License\LocalFacts;
use App\Services\License\RefusalReason;
use App\Services\License\Time\MonotonicReading;
use App\Services\License\Time\MonotonicSource;
use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesHostHardware;

/**
 * ADR-018 decision 3: the trusted-time high-water mark. It only moves forward, advances on a rolled-back clock through the
 * monotonic delta, and is inert (no table touched) in a build with no vendor key.
 */
class TrustedClockTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;

    private ?MonotonicReading $reading = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MonotonicSource::class, new class($this) implements MonotonicSource
        {
            public function __construct(private TrustedClockTest $test) {}

            public function reading(): ?MonotonicReading
            {
                return $this->test->currentReading();
            }
        });
    }

    public function currentReading(): ?MonotonicReading
    {
        return $this->reading;
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    private function clock(): TrustedClock
    {
        return $this->app->make(TrustedClock::class);
    }

    private function boot(string $bootId, int $uptime): void
    {
        $this->reading = new MonotonicReading($bootId, $uptime);
    }

    private function certificate(): Certificate
    {
        return Certificate::fromPayload([
            'v' => 2, 'typ' => 'tindaflow.activation', 'kid' => 'k', 'deployment' => 'A1B2C3D4',
            'store' => '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11', 'installation' => '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40',
            'fp' => ['smbios_uuid' => '1111111111111111111111111111111111111111111111111111111111111111', 'board_serial' => '2222222222222222222222222222222222222222222222222222222222222222', 'bios_serial' => '3333333333333333333333333333333333333333333333333333333333333333'], 'seats' => 1, 'mode' => 'enforce', 'seq' => 1, 'prev' => null,
            'iat' => '2026-11-24T09:00:00+08:00', 'exp' => '2026-12-25T05:00:00+08:00', 'jti' => '0123456789abcdef0123456789abcdef',
        ]);
    }

    public function test_there_is_no_mark_until_the_first_heartbeat(): void
    {
        $this->assertNull($this->clock()->highWaterMark());
    }

    public function test_the_first_heartbeat_sets_the_mark_to_the_wall_clock(): void
    {
        $this->boot('boot-1', 1000);

        $mark = $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $this->assertEquals($this->at('2026-11-25T09:00:00+08:00'), $mark);
        $this->assertEquals($mark, $this->clock()->highWaterMark());
        $this->assertSame(1, DB::table('license_state')->count());
    }

    public function test_an_honest_minute_advances_the_mark_by_a_minute_with_no_drift(): void
    {
        $this->boot('boot-1', 1000);
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $mark = null;
        foreach (range(1, 120) as $minute) {
            $this->boot('boot-1', 1000 + $minute * 60);
            $mark = $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00')->modify("+{$minute} minutes"));
        }

        $this->assertEquals($this->at('2026-11-25T11:00:00+08:00'), $mark);
    }

    public function test_a_clock_rolled_back_while_the_stack_runs_does_not_stall_the_mark(): void
    {
        $this->boot('boot-1', 1000);
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        // Someone sets the clock back three days; the monotonic clock still counts a minute.
        $this->boot('boot-1', 1060);
        $mark = $this->clock()->heartbeat($this->at('2026-11-22T09:01:00+08:00'));

        $this->assertEquals($this->at('2026-11-25T09:01:00+08:00'), $mark);
    }

    public function test_after_a_restart_only_the_wall_clock_can_advance_the_mark(): void
    {
        $this->boot('boot-1', 5000);
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $this->boot('boot-2', 30);
        $mark = $this->clock()->heartbeat($this->at('2026-11-25T09:10:00+08:00'));

        $this->assertEquals($this->at('2026-11-25T09:10:00+08:00'), $mark, 'the uptime counter restarted, so it cannot be subtracted');
    }

    public function test_a_restart_with_the_clock_set_back_does_not_move_the_mark_back(): void
    {
        $this->boot('boot-1', 5000);
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $this->boot('boot-2', 30);
        $mark = $this->clock()->heartbeat($this->at('2026-11-20T09:00:00+08:00'));

        $this->assertEquals($this->at('2026-11-25T09:00:00+08:00'), $mark);
    }

    public function test_a_host_with_no_monotonic_clock_relies_on_the_wall_clock(): void
    {
        $this->reading = null;
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $this->assertEquals($this->at('2026-11-25T09:05:00+08:00'), $this->clock()->heartbeat($this->at('2026-11-25T09:05:00+08:00')));
    }

    public function test_a_wall_clock_jump_forward_is_counted(): void
    {
        $this->boot('boot-1', 1000);
        $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $this->boot('boot-1', 1060);

        $this->assertEquals($this->at('2026-11-25T10:00:00+08:00'), $this->clock()->heartbeat($this->at('2026-11-25T10:00:00+08:00')));
    }

    public function test_the_mark_moves_forward_to_a_moment_of_record_and_never_back(): void
    {
        $this->clock()->advanceTo($this->at('2026-11-25T09:00:00+08:00'));
        $this->assertEquals($this->at('2026-11-25T09:00:00+08:00'), $this->clock()->highWaterMark());

        $this->clock()->advanceTo($this->at('2026-11-25T08:00:00+08:00'));
        $this->assertEquals($this->at('2026-11-25T09:00:00+08:00'), $this->clock()->highWaterMark(), 'an earlier moment changes nothing');

        $this->clock()->advanceTo($this->at('2026-11-26T09:00:00+08:00'));
        $this->assertEquals($this->at('2026-11-26T09:00:00+08:00'), $this->clock()->highWaterMark());
    }

    public function test_the_mark_is_stored_with_its_offset_whatever_the_session_time_zone(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $this->clock()->advanceTo($this->at('2026-11-25T09:00:00+08:00'));

        $utc = DB::selectOne("select to_char(trusted_time_hwm at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as t from license_state")->t;

        $this->assertSame('2026-11-25 01:00:00', $utc);
    }

    public function test_the_table_holds_exactly_one_row(): void
    {
        $this->clock()->advanceTo($this->at('2026-11-25T09:00:00+08:00'));

        $this->expectException(QueryException::class);
        DB::table('license_state')->insert(['id' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_evaluator_locks_a_clock_set_back_after_the_mark_has_moved_on(): void
    {
        $this->boot('boot-1', 1000);
        $mark = $this->clock()->heartbeat($this->at('2026-11-25T09:00:00+08:00'));

        $facts = new LocalFacts($this->at('2026-11-22T09:00:00+08:00'), $mark);
        $verdict = (new LicenseEvaluator)->evaluate($this->certificate(), $facts, true);

        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::ClockRollback, $verdict->shiftOpenRefusal());
    }

    public function test_the_heartbeat_command_does_nothing_in_a_build_with_no_vendor_key(): void
    {
        config(['tindaflow.license.vendor_keys' => []]);

        $this->assertSame(0, Artisan::call('tindaflow:license-heartbeat'));
        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_the_heartbeat_command_advances_the_mark_when_a_vendor_key_is_present(): void
    {
        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => base64_encode(str_repeat('k', 32))]]);

        $this->assertSame(0, Artisan::call('tindaflow:license-heartbeat'));

        $this->assertNotNull($this->clock()->highWaterMark());
    }

    public function test_the_heartbeat_is_scheduled_every_minute_without_overlap(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'tindaflow:license-heartbeat'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_status_says_not_enforced_and_touches_no_table_without_a_vendor_key(): void
    {
        config(['tindaflow.license.vendor_keys' => []]);

        Artisan::call('tindaflow:license-status');

        $this->assertStringContainsString('Activation: not enforced', Artisan::output());
        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_status_reports_not_activated_and_the_mark_when_a_vendor_key_is_present(): void
    {
        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => base64_encode(str_repeat('k', 32))]]);
        $this->clock()->advanceTo($this->at('2026-11-25T09:00:00+08:00'));

        Artisan::call('tindaflow:license-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Activation: NOT_ACTIVATED.', $output);
        $this->assertStringContainsString('Trusted time mark: 2026-11-25 09:00:00', $output);
        $this->assertStringContainsString('Hardware file: missing', $output);
    }

    public function test_the_host_helpers_clock_is_a_second_clock_that_can_only_move_the_mark_forward(): void
    {
        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => base64_encode(str_repeat('k', 32))]]);
        $this->boot('boot-1', 1000);
        $twoHoursAhead = CarbonImmutable::now()->addHours(2);
        $this->fakeHostHardware(generatedAt: $twoHoursAhead->format('Y-m-d\TH:i:sP'));

        Artisan::call('tindaflow:license-heartbeat');

        $this->assertEquals($twoHoursAhead->setMicrosecond(0), CarbonImmutable::instance($this->clock()->highWaterMark())->setTimezone($twoHoursAhead->getTimezone()));

        // A file stamped earlier than the mark moves nothing.
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->subHour()->format('Y-m-d\TH:i:sP'));
        $this->boot('boot-1', 1060);
        Artisan::call('tindaflow:license-heartbeat');

        $this->assertGreaterThanOrEqual($twoHoursAhead->getTimestamp(), $this->clock()->highWaterMark()->getTimestamp());
    }
}
