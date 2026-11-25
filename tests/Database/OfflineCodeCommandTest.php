<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\License\OfflineCodeRefused;
use App\Services\License\OfflineCodeService;
use App\Services\License\RefusalReason;
use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesHostHardware;
use Tests\Concerns\FakesLicensedInstallation;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-018 decisions 3 and 6: applying offline codes. The installation holds the certificate of `payload()` (seq 42, expiring
 * 2026-12-25 05:00 +08:00); each test chooses "now" with travelTo. Codes are signed with the TEST-ONLY code key.
 */
class OfflineCodeCommandTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use FakesLicensedInstallation;
    use SignsTestCertificates;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Asia/Manila',
            'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()],
            'tindaflow.license.code_keys' => [1 => self::testCodePublicKeyBase64()],
        ]);
        $this->fakeLicensedInstallation();
        DB::table('license_state')->updateOrInsert(['id' => 1], ['certificate' => self::sign(self::payload()), 'certificate_seq' => 42, 'created_at' => now(), 'updated_at' => now()]);
        $this->at('2026-12-26T09:00:00+08:00'); // a day after the certificate expired
    }

    /** Moves the clock, and has the host helper write a file at that moment so the hardware stays fresh. */
    private function at(string $time): void
    {
        $this->travelTo(CarbonImmutable::parse($time));
        $this->fakeHostHardware(generatedAt: CarbonImmutable::parse($time)->setTimezone('Asia/Manila')->format('Y-m-d\TH:i:sP'));
    }

    private function apply(array $code = [], string $label = 'tindaflow-code-test-only'): string
    {
        return app(OfflineCodeService::class)->apply(self::signCode(self::offlineCode($code + ['iat' => '2026-12-26T08:00:00+08:00']), $label));
    }

    private function assertRefused(string $needle, array $code = [], string $label = 'tindaflow-code-test-only'): void
    {
        $before = (array) DB::table('license_state')->first();
        try {
            $this->apply($code, $label);
            $this->fail("must be refused: {$needle}");
        } catch (OfflineCodeRefused $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
        $this->assertSame($before, (array) DB::table('license_state')->first(), 'a refused code changes nothing and is not used up');
    }

    private function state(): LicenseState
    {
        return app(LicenseGate::class)->check()->verdict->state;
    }

    private function extendedUntil(): string
    {
        return CarbonImmutable::parse(DB::table('license_state')->value('extended_until'))->setTimezone('Asia/Manila')->format('Y-m-d H:i');
    }

    public function test_an_extension_brings_an_expired_license_back_until_the_next_five_oclock_30_days_on(): void
    {
        $this->assertSame(LicenseState::Expired, $this->state());

        $message = $this->apply();

        // max(certificate expiry 25 Dec 05:00, code issued 26 Dec 08:00) + 30 days = 25 Jan 08:00, up to the next 05:00.
        $this->assertSame('2027-01-26 05:00', $this->extendedUntil());
        $this->assertStringContainsString('until 2027-01-26 05:00 (extension 1 of 2', $message);
        $this->assertSame(LicenseState::Active, $this->state());
        $this->assertNull(app(LicenseGate::class)->check()->verdict->shiftOpenRefusal(), 'a new shift may open');

        $audit = AuditEvent::where('event_type', 'LICENSE_EXTENDED')->sole();
        $this->assertSame(1, $audit->after_metadata['ordinal']);
    }

    public function test_the_extension_itself_runs_out(): void
    {
        $this->apply();

        $this->at('2027-01-26T05:01:00+08:00');

        $this->assertSame(LicenseState::Expired, $this->state());
    }

    public function test_a_code_issued_before_the_certificate_expires_counts_from_the_expiry(): void
    {
        $this->at('2026-12-20T09:00:00+08:00');

        $this->apply(['iat' => '2026-12-20T08:00:00+08:00']);

        $this->assertSame('2027-01-24 05:00', $this->extendedUntil());
    }

    public function test_the_command_applies_a_typed_code(): void
    {
        $code = self::signCode(self::offlineCode(['iat' => '2026-12-26T08:00:00+08:00']));

        $this->artisan('tindaflow:license-code')->expectsQuestion('Paste the code', strtolower($code))->expectsOutputToContain('Extended')->assertExitCode(0);
        $this->assertSame(1, Artisan::call('tindaflow:license-code', ['code' => [$code]]), 'a second time it is refused');
        $this->assertStringContainsString('already been used', Artisan::output());
    }

    public function test_status_shows_the_certificate_number_and_the_extension(): void
    {
        $this->apply();

        Artisan::call('tindaflow:license-status');
        $output = Artisan::output();

        $this->assertStringContainsString('Certificate: 42.', $output);
        $this->assertStringContainsString('Extended offline until 2027-01-26 05:00 (1 of 2 in a row).', $output);
    }

    public function test_only_two_extensions_in_a_row(): void
    {
        $this->apply();
        $this->at('2027-01-27T09:00:00+08:00');
        $this->apply(['ordinal' => 2, 'iat' => '2027-01-27T08:00:00+08:00']);

        $this->at('2027-02-28T09:00:00+08:00');
        $this->assertRefused('2 extensions in a row', ['ordinal' => 3, 'iat' => '2027-02-28T08:00:00+08:00']);
    }

    public function test_a_code_that_would_not_make_the_license_run_longer_is_refused_and_kept(): void
    {
        $this->apply(['iat' => '2026-12-26T08:00:00+08:00']);

        $this->assertRefused('would not make the license run any longer', ['ordinal' => 2, 'iat' => '2026-12-26T08:30:00+08:00']);
    }

    public function test_a_period_already_over_is_refused(): void
    {
        $this->at('2027-03-01T09:00:00+08:00');

        $this->assertRefused('already over', ['iat' => '2026-12-26T08:00:00+08:00']);
    }

    public function test_each_code_is_used_once_and_an_older_one_not_at_all(): void
    {
        $this->apply(['ordinal' => 5]);

        $this->assertRefused('already been used', ['ordinal' => 5]);
        $this->assertRefused('newer one', ['ordinal' => 4]);
    }

    public function test_a_code_for_another_installation_deployment_or_certificate_is_refused(): void
    {
        $this->assertRefused('different installation', ['installation' => '11111111-2222-4333-8444-555555555555']);
        $this->assertRefused('different installation', ['deployment' => 'FFFFFFFF']);
        $this->assertRefused('holds certificate 42', ['baseSeq' => 41]);
    }

    public function test_a_code_signed_by_the_certificate_key_or_mistyped_is_refused(): void
    {
        $this->assertRefused('BAD_SIGNATURE', [], 'tindaflow-license-test-only');

        $this->expectExceptionMessage('incomplete or mistyped');
        app(OfflineCodeService::class)->apply('AAAAA-BBBBB');
    }

    public function test_a_clock_behind_the_provider_is_refused(): void
    {
        $this->assertRefused('clock is more than an hour behind', ['iat' => '2026-12-26T11:00:00+08:00']);
    }

    public function test_a_code_is_refused_when_the_stored_certificate_belongs_to_another_installation(): void
    {
        $this->fakeIdentityFile('11111111-2222-4333-8444-555555555555');

        $this->assertRefused('not accepted by this installation');
    }

    public function test_a_build_without_a_code_key_accepts_no_code(): void
    {
        config(['tindaflow.license.code_keys' => []]);

        $this->assertRefused('carries no code key');
    }

    public function test_an_extension_never_unlocks_a_locked_installation_and_nothing_unlocks_a_revoked_one(): void
    {
        $this->fakeHostHardware(['board_serial' => str_repeat('0', 64), 'bios_serial' => str_repeat('1', 64)] + self::testFingerprint(), CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        $this->assertRefused('cannot unlock');

        DB::table('license_state')->where('id', 1)->update(['revoked' => true]);
        $this->assertRefused('revoked');
        $this->assertRefused('revoked', ['type' => 2, 'days' => 0]);
    }

    public function test_a_time_reset_frees_a_clock_that_was_once_set_into_the_future(): void
    {
        // The clock was set to March and corrected back to December: the mark stays in March and the PC is locked.
        $this->at('2027-03-01T09:00:00+08:00');
        app(TrustedClock::class)->advanceTo(CarbonImmutable::now());
        $this->travelTo(CarbonImmutable::parse('2026-12-20T09:00:00+08:00'));
        $this->assertSame(RefusalReason::ClockRollback, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());

        $this->apply(['type' => 2, 'days' => 0, 'iat' => '2026-12-20T08:55:00+08:00']);

        $this->assertSame(LicenseState::Warning, $this->state(), 'unlocked, five days before expiry');
        $this->assertSame(CarbonImmutable::parse('2026-12-20T08:55:00+08:00')->getTimestamp(), app(TrustedClock::class)->highWaterMark()->getTimestamp());
        $audit = AuditEvent::where('event_type', 'LICENSE_TIME_RESET')->sole();
        $this->assertStringStartsWith('2027-03-01', CarbonImmutable::parse($audit->after_metadata['from'])->setTimezone('Asia/Manila')->toDateString());
    }

    public function test_after_a_time_reset_the_hardware_file_written_under_the_wrong_clock_cannot_push_the_mark_forward_again(): void
    {
        $this->at('2027-03-01T09:00:00+08:00'); // the host helper stamps its file with the wrong clock
        app(TrustedClock::class)->advanceTo(CarbonImmutable::now());
        $this->travelTo(CarbonImmutable::parse('2026-12-20T09:00:00+08:00')); // clock corrected; the file still says March

        $this->apply(['type' => 2, 'days' => 0, 'iat' => '2026-12-20T08:55:00+08:00']);
        Artisan::call('tindaflow:license-heartbeat');

        $this->assertSame(LicenseState::Warning, $this->state(), 'the March stamp is set aside');

        // The helper writes a new file under the corrected clock, which counts again.
        $this->fakeHostHardware(generatedAt: '2026-12-20T09:10:00+08:00');
        $this->travelTo(CarbonImmutable::parse('2026-12-20T09:10:30+08:00'));
        Artisan::call('tindaflow:license-heartbeat');
        $this->assertGreaterThanOrEqual(CarbonImmutable::parse('2026-12-20T09:10:00+08:00')->getTimestamp(), app(TrustedClock::class)->highWaterMark()->getTimestamp());
    }
}
