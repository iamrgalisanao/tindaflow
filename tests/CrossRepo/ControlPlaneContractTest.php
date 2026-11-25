<?php

namespace Tests\CrossRepo;

use App\Models\AuditEvent;
use App\Models\Sale;
use App\Models\Store;
use App\Services\License\Contract\Base64Url;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\License\RefusalReason;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use GuzzleHttp\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PDO;
use RuntimeException;
use Tests\Concerns\FakesHostHardware;
use Tests\Database\PostgresSchemaTestCase;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;
use Throwable;

/**
 * The real client against the real control plane (the private `tindaflow-control-plane` repository), over real HTTP, through
 * the real commands of both. Nothing of either side is faked except the two things no test can have: the vendor key (the
 * TEST-ONLY key of the golden vectors) and the Windows host helper's file.
 *
 * What it does NOT cover: TLS, DNS and the host helper itself. The client only accepts https control plane addresses, so its
 * requests are caught just before the network and replayed unchanged (method, path, headers, body) to the control plane served
 * on 127.0.0.1. The signed target address is the https one, which is what the control plane is configured with.
 *
 * The control plane's registry is a real PostgreSQL database of its own, as in production (ADR-019 slice 2). It is dropped and
 * rebuilt for every test, so its name (PGSQL_CONTROL_PLANE_TEST_DATABASE) must be `tindaflow_cp..._test`; it is created if missing.
 *
 * Not part of the normal suites: it needs a checkout of the control plane with its dependencies installed. Run it with
 *   TINDAFLOW_CONTROL_PLANE_PATH=/path/to/tindaflow-control-plane php artisan test tests/CrossRepo
 * The control plane's CI runs it on every change there and once a day against this repository's main branch.
 */
class ControlPlaneContractTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private const PRIMARY = 'https://licensing.example.ph';

    private const STANDBY = 'https://licensing-standby.example.ph';

    private string $controlPlane = '';

    private string $workDirectory = '';

    /** @var array<string, string> */
    private array $environment = [];

    /** @var resource|null */
    private $server = null;

    private string $deploymentCode = '';

    private string $storeId = '';

    private bool $loseRenewalAnswers = false;

    protected function setUp(): void
    {
        $path = (string) getenv('TINDAFLOW_CONTROL_PLANE_PATH');
        if ($path === '') {
            $this->markTestSkipped('Set TINDAFLOW_CONTROL_PLANE_PATH to a checkout of tindaflow-control-plane to run the cross-repository test.');
        }

        parent::setUp();

        // A path that was given but is wrong must fail, never skip: a CI job that silently skips this test would prove nothing.
        $this->assertFileExists($path.'/artisan', 'TINDAFLOW_CONTROL_PLANE_PATH is not a control plane checkout');
        $this->assertFileExists($path.'/vendor/autoload.php', 'install the control plane\'s dependencies first');
        $this->controlPlane = $path;

        $this->workDirectory = sys_get_temp_dir().'/tindaflow-cross-'.bin2hex(random_bytes(6));
        mkdir($this->workDirectory);
        $this->createRegistryDatabase();
        $this->environment = $this->controlPlaneEnvironment();

        config([
            'app.timezone' => 'Asia/Manila',
            'tindaflow.license.installation_file' => $this->workDirectory.'/installation.json',
            'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()],
            'tindaflow.license.code_keys' => [1 => self::testCodePublicKeyBase64()],
        ]);
        $this->fakeHostHardware();

        $this->artisanThere(['migrate:fresh', '--force']);
        $port = $this->serveControlPlane();
        $this->routeClientRequestsTo($port);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
        if ($this->workDirectory !== '') {
            File::deleteDirectory($this->workDirectory);
        }
        if ($this->controlPlane !== '') {
            parent::tearDown();
        }
    }

    public function test_activation_then_renewals_form_one_clean_chain(): void
    {
        $this->activate();

        $store = Store::findOrFail($this->storeId);
        $this->assertSame('Cross Repo Cafe', $store->name);
        $this->assertSame($this->deploymentCode, DeploymentId::current(), 'both sides derive the same deployment code from the store id');
        $this->assertSame(LicenseState::Active, $this->licenseState());

        $this->assertRenewal(0, 'still current');
        $this->assertSame(1, $this->heldSeq());

        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->assertSame(2, $this->heldSeq());
        $this->assertFalse($this->lastRenewalAudit()['chain_gap'], 'the successor links to the token the client held: seq + 1 and prev = its SHA-256');

        // One changed component is tolerated: the chain goes on, the certificate keeps the approved hardware, and the provider is told.
        $this->fakeHostHardware(['bios_serial' => hash('sha256', 'a replaced board firmware')] + self::testFingerprint());
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 3');
        $this->assertFalse($this->lastRenewalAudit()['chain_gap']);
        $this->assertEquals(self::testFingerprint(), $this->heldFingerprint(), 'the approved hardware is never replaced');
        $this->assertSame(LicenseState::Active, $this->licenseState(), 'two of three still match the certificate');
        $this->assertSame(1, $this->registryEvents('FINGERPRINT_CHANGED'));
    }

    public function test_hardware_cannot_be_walked_away_one_component_at_a_time(): void
    {
        $this->activate();
        $first = ['bios_serial' => hash('sha256', 'another bios')] + self::testFingerprint();
        $this->fakeHostHardware($first);
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');

        $this->fakeHostHardware(['board_serial' => hash('sha256', 'another board')] + $first);
        $this->ageLatestCertificate();

        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(2, $this->heldSeq());
    }

    public function test_a_renewal_whose_answer_was_lost_gets_the_same_certificate_again(): void
    {
        $this->activate();
        $this->ageLatestCertificate();

        // The control plane issues certificate 2, and neither address's answer arrives.
        $this->loseRenewalAnswers = true;
        $this->assertRenewal(1, 'could not be reached');
        $this->assertSame(1, $this->heldSeq());

        $this->loseRenewalAnswers = false;
        $this->assertRenewal(0, 'Renewed: certificate 2');

        $this->assertSame(2, (int) $this->registry()->query('select count(*) from issued_certificates')->fetchColumn(), 'no second certificate was issued');
        $this->assertGreaterThanOrEqual(1, $this->registryEvents('RENEWAL_REPLAYED'));
        $this->assertSame(0, $this->registryEvents('CHAIN_FORKED'));
    }

    public function test_a_computer_with_two_changed_components_is_refused(): void
    {
        $this->activate();
        $this->fakeHostHardware(['board_serial' => hash('sha256', 'another board'), 'bios_serial' => hash('sha256', 'another bios')] + self::testFingerprint());

        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(1, $this->heldSeq());
        $this->assertSame(1, $this->registryEvents('FINGERPRINT_MISMATCH'));
    }

    public function test_a_request_signed_with_another_key_is_rejected_by_the_control_plane(): void
    {
        $this->activate();
        $identity = app(InstallationIdentity::class)->load();
        $other = sodium_crypto_sign_keypair();
        file_put_contents((string) config('tindaflow.license.installation_file'), json_encode(['secret_key' => base64_encode(sodium_crypto_sign_secretkey($other))] + $identity));

        $this->assertRenewal(1, 'HTTP 401');
        $this->assertSame(1, $this->heldSeq());
    }

    public function test_a_suspended_deployment_is_not_renewed_until_it_is_resumed(): void
    {
        $this->activate();
        $this->ageLatestCertificate();

        $this->artisanThere(['license:suspend', $this->deploymentCode, '--reason=Unpaid']);
        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(1, $this->heldSeq());
        $this->assertSame(LicenseState::Active, $this->licenseState(), 'suspending takes nothing away from the certificate already held');

        $this->artisanThere(['license:resume', $this->deploymentCode, '--reason=Paid']);
        $this->assertRenewal(0, 'Renewed: certificate 2');
    }

    public function test_an_installation_restored_from_an_old_backup_is_reported_as_a_fork(): void
    {
        $this->activate();
        $backup = (array) DB::table('license_state')->where('id', 1)->first(['certificate', 'certificate_seq']);
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');

        DB::table('license_state')->where('id', 1)->update($backup);

        $this->assertRenewal(1, 'forked');
        $this->assertSame(1, $this->heldSeq(), 'a fork report changes nothing on the client');
        $this->assertSame(1, $this->registryEvents('CHAIN_FORKED'));
    }

    public function test_a_revocation_signed_by_the_control_plane_locks_the_client_for_good(): void
    {
        $this->activate();

        // The real command asks for the deployment code to be typed back.
        $this->artisanThere(['license:revoke', $this->deploymentCode, '--reason=Breach'], input: $this->deploymentCode."\n");
        $this->assertRenewal(0, 'revoked this installation');

        $verdict = app(LicenseGate::class)->check()->verdict;
        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::Revoked, $verdict->shiftOpenRefusal());

        $refusal = $this->artisanThere(['license:resume', $this->deploymentCode, '--reason=Mistake'], expectedExit: 1);
        $this->assertStringContainsString('cannot be resumed', $refusal, 'the provider knows the client cannot be unlocked');
    }

    public function test_an_extension_code_from_the_control_plane_keeps_an_offline_client_trading(): void
    {
        $this->activate();
        $certificate = app(LicenseGate::class);
        $expiry = new DateTimeImmutable((string) json_decode((string) Base64Url::decode(explode('.', (string) DB::table('license_state')->value('certificate'))[0]), true)['exp']);

        // The provider makes the code now; the shop is still offline a day after its certificate ran out.
        $this->artisanThere(['license:extension-code', $this->deploymentCode, '--reason=Fibre cut', '--base-seq=1']);
        $code = $this->latestOfflineCode();

        $this->travelTo(CarbonImmutable::instance($expiry)->addDay());
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        $this->assertSame(LicenseState::Expired, $certificate->check()->verdict->state);

        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$code]]), Artisan::output());
        $this->assertSame(LicenseState::Active, $certificate->check()->verdict->state);

        // Back online, a renewal replaces the certificate and drops the extension.
        $this->travelBack();
        $this->fakeHostHardware();
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->assertNull(DB::table('license_state')->value('extended_until'));
    }

    public function test_a_time_reset_code_from_the_control_plane_unlocks_a_clock_once_set_ahead(): void
    {
        $this->activate();

        // The clock is set two months ahead while the system runs, then corrected: the recorded time stays ahead and the PC locks.
        $this->travelTo(CarbonImmutable::now()->addDays(60));
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        Artisan::call('tindaflow:license-heartbeat');
        $this->travelBack();
        $this->assertSame(RefusalReason::ClockRollback, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());

        $this->artisanThere(['license:time-reset-code', $this->deploymentCode, '--reason=Clock was set ahead']);
        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$this->latestOfflineCode()]]), Artisan::output());

        // The heartbeat must not take the wrongly stamped hardware file as the time again.
        Artisan::call('tindaflow:license-heartbeat');
        $this->assertSame(LicenseState::Active, $this->licenseState());
    }

    // The ways back to trading (ADR-019 slice 3). Each is drilled on a shop that has traded, and may change nothing but licensing
    // state and the audit log, which only gains licensing entries.

    public function test_an_expired_shop_with_internet_trades_again_after_one_online_renewal(): void
    {
        $this->activateAndTrade();
        $this->letTheLicenseRunOut();

        $this->assertOnlyLicensingChanges(fn () => $this->assertRenewal(0, 'Renewed: certificate 2'));

        $this->assertTradingAgain();
    }

    public function test_a_shop_that_expired_while_suspended_trades_again_once_resumed(): void
    {
        $this->activateAndTrade();
        $this->artisanThere(['license:suspend', $this->deploymentCode, '--reason=Unpaid']);
        $this->letTheLicenseRunOut();
        $this->assertRenewal(1, 'HTTP 403');
        $this->assertSame(LicenseState::Expired, $this->licenseState());

        $this->artisanThere(['license:resume', $this->deploymentCode, '--reason=Paid']);

        $this->assertOnlyLicensingChanges(fn () => $this->assertRenewal(0, 'Renewed: certificate 2'));
        $this->assertTradingAgain();
    }

    public function test_an_expired_shop_with_no_internet_trades_again_by_file(): void
    {
        $this->activateAndTrade();
        $this->letTheLicenseRunOut();

        $this->assertOnlyLicensingChanges(function () {
            $reply = $this->renewByFile();
            $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--apply' => $reply]), Artisan::output());
        });

        $this->assertTradingAgain();
        $this->assertSame(2, $this->heldSeq());
        $this->assertFalse($this->lastRenewalAudit()['chain_gap']);
        Http::assertNothingSent();
    }

    public function test_a_shop_that_never_gets_the_internet_can_be_renewed_by_file_again_and_again(): void
    {
        $this->activateAndTrade();

        foreach ([2, 3] as $seq) {
            $this->letTheLicenseRunOut();
            $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--apply' => $this->renewByFile()]), Artisan::output());
            $this->assertSame($seq, $this->heldSeq());
            $this->assertTradingAgain();
        }

        $this->assertSame(0, $this->registryEvents('CHAIN_FORKED'));
    }

    public function test_a_revocation_reaches_a_shop_with_no_internet_by_file(): void
    {
        $this->activateAndTrade();
        $this->artisanThere(['license:revoke', $this->deploymentCode, '--reason=Breach'], input: $this->deploymentCode."\n");

        $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--apply' => $this->renewByFile()]), Artisan::output());

        $this->assertSame(RefusalReason::Revoked, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());
        Http::assertNothingSent();
    }

    public function test_a_clock_once_set_ahead_is_cleared_by_an_online_renewal_the_same_day(): void
    {
        $this->activateAndTrade();
        $this->setTheClockAheadAndBack();
        // The latest certificate is hours old, not a day: an ordinary renewal would be answered with the one already held.
        $this->registry()->exec("update issued_certificates set iat = now() - interval '2 hours'");
        $this->fakeHostHardware();

        $this->assertOnlyLicensingChanges(fn () => $this->assertRenewal(0, 'Renewed: certificate 2'));

        $this->assertTradingAgain();
    }

    // Adopting a shop that already trades (ADR-019 slice 3): the provider registers the store id the shop already has, and applying
    // the bundle may change nothing but licensing state and the audit log.

    public function test_a_shop_that_already_trades_is_adopted_with_nothing_else_changed_and_then_renews(): void
    {
        $store = $this->tradingShopBeforeAnyLicense();
        $bundle = $this->adoptionBundle();

        $this->assertOnlyLicensingChanges(fn () => $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $bundle, '--adopt' => true]), Artisan::output()));

        $this->assertSame($store->id, $this->storeId, 'the registry took the id the shop already had');
        $this->assertSame(DeploymentId::shorten($store->id), $this->deploymentCode);
        $this->assertSame($this->deploymentCode, DeploymentId::current(), 'the code on its backups and reports is unchanged');
        $this->assertTradingAgain();

        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->assertFalse($this->lastRenewalAudit()['chain_gap']);
    }

    public function test_a_shop_adopted_in_report_mode_is_licensed_and_never_refused(): void
    {
        $this->tradingShopBeforeAnyLicense();
        $bundle = $this->adoptionBundle(['--mode=report']);

        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $bundle, '--adopt' => true]), Artisan::output());

        $this->assertFalse(app(LicenseGate::class)->check()->verdict->enforcing);
        $this->assertSame(LicenseState::Active, $this->licenseState());
    }

    // Reconnecting a certificate chain (ADR-019 slice 3): a backup older than the latest certificate is a fork until the provider,
    // having checked with the owner, approves the certificate number the shop holds.

    public function test_a_shop_restored_from_an_old_backup_is_reconnected_with_only_licensing_changes_and_the_other_copy_is_refused(): void
    {
        $this->activateAndTrade();
        $backup = (array) DB::table('license_state')->where('id', 1)->first();
        foreach ([2, 3] as $seq) {
            $this->ageLatestCertificate();
            $this->assertRenewal(0, "Renewed: certificate {$seq}");
        }
        $later = (array) DB::table('license_state')->where('id', 1)->first();

        DB::table('license_state')->where('id', 1)->update($backup);
        $this->assertRenewal(1, 'this computer holds certificate 1');
        $output = $this->artisanThere(['license:rechain', $this->deploymentCode, '--seq=1', '--reason=Owner confirmed a restore']);
        $this->assertStringContainsString('presented certificate 1', $output, 'the provider sees what was refused');

        $this->assertOnlyLicensingChanges(fn () => $this->assertRenewal(0, 'Renewed: certificate 4'));

        $this->assertTradingAgain();
        $this->assertTrue($this->lastRenewalAudit()['chain_gap']);
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 5');

        // Whoever still holds what the shop had before the restore is now the copy.
        DB::table('license_state')->where('id', 1)->update($later);
        $this->assertRenewal(1, 'forked');
        $this->assertSame(3, $this->heldSeq());
    }

    public function test_a_restored_shop_with_no_internet_is_reconnected_by_file(): void
    {
        $this->activateAndTrade();
        $backup = (array) DB::table('license_state')->where('id', 1)->first();
        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        DB::table('license_state')->where('id', 1)->update($backup);

        $request = $this->workDirectory.'/renewal-request.txt';
        $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $request]), Artisan::output());
        $this->assertStringContainsString('chain', $this->artisanThere(['license:renew-file', $request, '--out='.$request.'.reply'], expectedExit: 1));
        $this->artisanThere(['license:rechain', $this->deploymentCode, '--seq=1', '--reason=Owner confirmed a restore']);

        $this->assertOnlyLicensingChanges(function () use ($request) {
            $this->artisanThere(['license:renew-file', $request, '--out='.$request.'.reply']);
            $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--apply' => $request.'.reply']), Artisan::output());
        });

        $this->assertSame(3, $this->heldSeq());
        $this->assertSame(LicenseState::Active, $this->licenseState());
    }

    // Replacing the computer (ADR-019 slice 3). The shop is restored onto new hardware with its database and identity file, which
    // locks it; the provider licenses the new computer and retires the old one.

    public function test_a_shop_restored_onto_a_new_computer_is_replaced_with_only_licensing_changes_and_the_old_one_is_refused(): void
    {
        $this->tradingShopBeforeAnyLicense();
        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $this->adoptionBundle(), '--adopt' => true]), Artisan::output());
        // The old computer has used an offline code, which the new one's count must not inherit.
        $this->artisanThere(['license:extension-code', $this->deploymentCode, '--reason=Outage']);
        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$this->latestOfflineCode()]]), Artisan::output());
        $oldIdentity = (string) file_get_contents((string) config('tindaflow.license.installation_file'));
        $oldLicense = (array) DB::table('license_state')->where('id', 1)->first();

        $newHardware = ['smbios_uuid' => str_repeat('d', 64), 'board_serial' => str_repeat('e', 64), 'bios_serial' => str_repeat('f', 64)];
        $this->fakeHostHardware($newHardware);
        $this->assertSame(RefusalReason::FingerprintMismatch, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal(), 'restored onto other hardware, it is locked');

        $bundle = $this->replacementBundle();
        $this->assertOnlyLicensingChanges(fn () => $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $bundle, '--replace' => true]), Artisan::output()));
        $this->assertTradingAgain();

        $this->ageLatestCertificate();
        $this->assertRenewal(0, 'Renewed: certificate 2');
        $this->artisanThere(['license:extension-code', $this->deploymentCode, '--reason=Another outage']);
        $this->assertSame(0, Artisan::call('tindaflow:license-code', ['code' => [$this->latestOfflineCode()]]), Artisan::output());
        $this->assertSame(1, (int) DB::table('license_state')->value('last_code_ordinal'), 'the new computer counts its codes from the start');

        // The old computer is switched on again, exactly as it was.
        file_put_contents((string) config('tindaflow.license.installation_file'), $oldIdentity);
        DB::table('license_state')->where('id', 1)->update($oldLicense);
        $this->fakeHostHardware();
        $this->ageLatestCertificate();

        $this->assertRenewal(1, 'has been replaced');
        $this->assertSame(1, $this->registryEvents('REPLACED_COMPUTER_SEEN'));
        $this->assertSame(0, $this->registryEvents('CHAIN_FORKED'));
    }

    private function replacementBundle(): string
    {
        $request = $this->workDirectory.'/replacement-request.json';
        $bundle = $this->workDirectory.'/replacement-bundle.txt';

        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--replace' => true, '--out' => $request]), Artisan::output());
        $this->artisanThere(['license:replace-computer', $this->deploymentCode, $request, '--reason=The old computer died', '--out='.$bundle]);

        return $bundle;
    }

    private function tradingShopBeforeAnyLicense(): Store
    {
        Sale::factory()->create();

        return Store::sole();
    }

    /** @param  list<string>  $options  for registering the client */
    private function adoptionBundle(array $options = []): string
    {
        $request = $this->workDirectory.'/adoption-request.json';
        $bundle = $this->workDirectory.'/adoption-bundle.txt';

        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--adopt' => true, '--out' => $request]), Artisan::output());

        $registered = $this->artisanThere(['license:register-client', 'Existing Cafe', '--existing='.$request, ...$options]);
        $this->assertSame(1, preg_match('/deployment code ([0-9A-F]{8}), store id ([0-9a-f-]{36})/', $registered, $found), $registered);
        [, $this->deploymentCode, $this->storeId] = $found;

        $this->artisanThere(['license:issue-bundle', $this->deploymentCode, $request, '--out='.$bundle]);

        return $bundle;
    }

    private function activateAndTrade(): void
    {
        $this->activate();
        Sale::factory()->create();
    }

    /**
     * As if 40 days had passed with no renewal. The control plane runs on the real clock, so instead of moving time, the certificate
     * the shop holds (and the registry's record of its age) are made 40 days old: the same certificate number, signed by the same key.
     */
    private function letTheLicenseRunOut(): void
    {
        $payload = json_decode((string) Base64Url::decode(explode('.', (string) DB::table('license_state')->value('certificate'))[0]), true);
        $now = CarbonImmutable::now()->setTimezone('Asia/Manila');
        $payload['iat'] = $now->subDays(40)->format('Y-m-d\TH:i:sP');
        $payload['exp'] = $now->subDays(10)->format('Y-m-d\TH:i:sP');

        DB::table('license_state')->where('id', 1)->update(['certificate' => self::sign($payload)]);
        $this->registry()->exec("update issued_certificates set iat = now() - interval '40 days'");

        $this->assertSame(RefusalReason::Expired, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal(), 'a new shift is refused');
    }

    private function setTheClockAheadAndBack(): void
    {
        $this->travelTo(CarbonImmutable::now()->addDays(60));
        $this->fakeHostHardware(generatedAt: CarbonImmutable::now()->format('Y-m-d\TH:i:sP'));
        Artisan::call('tindaflow:license-heartbeat');
        $this->travelBack();
        $this->assertSame(RefusalReason::ClockRollback, app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());
    }

    /** The whole offline round trip through the real commands of both: request here, answered there. @return string the reply file */
    private function renewByFile(): string
    {
        $request = $this->workDirectory.'/renewal-request.txt';
        $reply = $this->workDirectory.'/renewal-reply-'.bin2hex(random_bytes(4)).'.json';

        $this->assertSame(0, Artisan::call('tindaflow:license-renew', ['--request' => true, '--out' => $request]), Artisan::output());
        $this->artisanThere(['license:renew-file', $request, '--out='.$reply]);

        return $reply;
    }

    private function assertTradingAgain(): void
    {
        $verdict = app(LicenseGate::class)->check()->verdict;
        $this->assertSame(LicenseState::Active, $verdict->state);
        $this->assertNull($verdict->shiftOpenRefusal(), 'a new shift may open');
    }

    private function assertOnlyLicensingChanges(Closure $wayBack): void
    {
        $shop = $this->shopRecords();
        $audit = $this->auditRecords();
        $this->assertNotSame('0:', $shop['sales'], 'the drill is only worth something with a sale to keep');

        $wayBack();

        $this->assertSame($shop, $this->shopRecords(), 'nothing outside licensing changed');
        $after = $this->auditRecords();
        $this->assertSame($audit, array_intersect_key($after, $audit), 'no audit entry was changed or removed');
        foreach (AuditEvent::whereKey(array_keys(array_diff_key($after, $audit)))->pluck('event_type') as $type) {
            $this->assertStringStartsWith('LICENSE_', $type);
        }
    }

    /** @return array<string, string> table => how many rows and a digest of them, for everything that is not licensing, audit or cache */
    private function shopRecords(): array
    {
        $records = [];
        $tables = DB::select("select table_name as name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE' order by 1");
        foreach (array_column($tables, 'name') as $table) {
            if (! in_array($table, ['license_state', 'audit_events', 'cache', 'cache_locks', 'sessions'], true)) {
                $records[$table] = DB::selectOne("select count(*) || ':' || coalesce(md5(string_agg(t::text, ',' order by t::text)), '') as digest from \"{$table}\" t")->digest;
            }
        }

        return $records;
    }

    /** @return array<string, string> audit entry id => digest */
    private function auditRecords(): array
    {
        return array_column(DB::select('select id, md5(t::text) as digest from audit_events t order by id'), 'digest', 'id');
    }

    /** The code as the registry holds it; license:codes prints this same text. */
    private function latestOfflineCode(): string
    {
        // Newest by id (time-ordered), not by ordinal: each computer counts its codes from 1.
        $code = (string) $this->registry()->query('select code from offline_codes order by id desc limit 1')->fetchColumn();
        $this->assertStringContainsString($code, $this->artisanThere(['license:codes', $this->deploymentCode]));

        return $code;
    }

    /** The offline path end to end, through the real commands: the client's request, the provider's bundle, the client applying it. */
    private function activate(): void
    {
        $request = $this->workDirectory.'/request.json';
        $bundle = $this->workDirectory.'/bundle.txt';

        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $request]), Artisan::output());

        $registered = $this->artisanThere(['license:register-client', 'Cross Repo Cafe', '--seats=2', '--footer=NOT A BIR-REGISTERED INVOICE']);
        $this->assertSame(1, preg_match('/deployment code ([0-9A-F]{8}), store id ([0-9a-f-]{36})/', $registered, $found), $registered);
        [, $this->deploymentCode, $this->storeId] = $found;

        $this->artisanThere(['license:issue-bundle', $this->deploymentCode, $request, '--out='.$bundle]);
        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--bundle' => $bundle, '--admin-email' => 'owner@example.test']), Artisan::output());
    }

    private function assertRenewal(int $expectedExit, string $expectedText): void
    {
        $exit = Artisan::call('tindaflow:license-renew');
        $output = Artisan::output();

        $this->assertSame($expectedExit, $exit, $output.$this->serverLog());
        $this->assertStringContainsString($expectedText, $output, $this->serverLog());
    }

    private function licenseState(): LicenseState
    {
        return app(LicenseGate::class)->check()->verdict->state;
    }

    private function heldSeq(): int
    {
        return (int) DB::table('license_state')->where('id', 1)->value('certificate_seq');
    }

    /** @return array<string, string> the approved hardware inside the certificate held */
    private function heldFingerprint(): array
    {
        $token = (string) DB::table('license_state')->where('id', 1)->value('certificate');

        return json_decode((string) Base64Url::decode(explode('.', $token)[0]), true)['fp'];
    }

    /** @return array<string, mixed> */
    private function lastRenewalAudit(): array
    {
        return AuditEvent::where('event_type', 'LICENSE_RENEWED')->get()->sortByDesc(fn (AuditEvent $event) => $event->after_metadata['seq'])->first()->after_metadata;
    }

    private function registry(): PDO
    {
        return $this->postgres($this->registryDatabase());
    }

    /** The one place the registry's name is decided. Every test wipes this database, so anything not named as a test database is refused. */
    private function registryDatabase(): string
    {
        $name = (string) env('PGSQL_CONTROL_PLANE_TEST_DATABASE', 'tindaflow_cp_cross_test');
        // Ending in `_test` is not enough to be safe to wipe: a developer's everyday database may be named that way too.
        if (preg_match('/\Atindaflow_cp(_[a-z0-9]+)*_test\z/', $name) !== 1) {
            throw new RuntimeException("The cross-repository test wipes the control plane's database, so it must be named tindaflow_cp..._test. Got '{$name}'.");
        }

        return $name;
    }

    private function createRegistryDatabase(): void
    {
        $server = $this->postgres('postgres');
        $exists = $server->prepare('select 1 from pg_database where datname = ?');
        $exists->execute([$this->registryDatabase()]);
        if ($exists->fetchColumn() === false) {
            $server->exec('create database '.$this->registryDatabase());
        }
    }

    private function postgres(string $database): PDO
    {
        $server = $this->postgresServer();

        return new PDO("pgsql:host={$server['DB_HOST']};port={$server['DB_PORT']};dbname={$database}", $server['DB_USERNAME'], $server['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array{DB_HOST: string, DB_PORT: string, DB_USERNAME: string, DB_PASSWORD: string} the same server the client's tests use */
    private function postgresServer(): array
    {
        return [
            'DB_HOST' => (string) env('PGSQL_TEST_HOST', '127.0.0.1'),
            'DB_PORT' => (string) env('PGSQL_TEST_PORT', '5432'),
            'DB_USERNAME' => (string) env('PGSQL_TEST_USERNAME', 'postgres'),
            'DB_PASSWORD' => (string) env('PGSQL_TEST_PASSWORD', ''),
        ];
    }

    /** The control plane re-issues only after 24 hours, so make the one it holds two days old. */
    private function ageLatestCertificate(): void
    {
        $this->registry()->exec("update issued_certificates set iat = now() - interval '2 days'");
    }

    private function registryEvents(string $event): int
    {
        $query = $this->registry()->prepare('select count(*) from registry_events where event = ?');
        $query->execute([$event]);

        return (int) $query->fetchColumn();
    }

    /**
     * A clean environment: nothing of this application's own configuration may leak into the control plane's.
     *
     * @return array<string, string>
     */
    private function controlPlaneEnvironment(): array
    {
        $inherited = array_intersect_key(getenv(), array_flip(['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'HOME', 'USERPROFILE', 'PHPRC', 'PHP_INI_SCAN_DIR']));

        return $inherited + $this->postgresServer() + [
            'APP_ENV' => 'local',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_TIMEZONE' => 'UTC',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $this->registryDatabase(),
            'DB_URL' => '',
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
            'LICENSING_SIGNING_KID' => 'test-2026-01',
            'LICENSING_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey(self::testKeyPair())),
            'LICENSING_CODE_KEY_ID' => '1',
            'LICENSING_CODE_SIGNING_KEY' => base64_encode(sodium_crypto_sign_secretkey(self::testKeyPair('tindaflow-code-test-only'))),
            'LICENSING_CONTROL_PLANE_URLS' => self::PRIMARY.','.self::STANDBY,
            'LICENSING_TIMEZONE' => 'Asia/Manila',
        ];
    }

    /** @return list<string> the PHP command, loading sodium where a plain child process would not have it */
    private function php(): array
    {
        static $needsSodium = null;
        if ($needsSodium === null) {
            [$output] = $this->process([PHP_BINARY, '-r', 'echo extension_loaded("sodium") ? "yes" : "no";']);
            $needsSodium = trim($output) !== 'yes';
        }

        return $needsSodium ? [PHP_BINARY, '-d', 'extension=sodium'] : [PHP_BINARY];
    }

    /** @param  list<string>  $arguments */
    private function artisanThere(array $arguments, int $expectedExit = 0, ?string $input = null): string
    {
        [$output, $exit] = $this->process([...$this->php(), 'artisan', ...$arguments], $input);
        $this->assertSame($expectedExit, $exit, 'control plane: artisan '.implode(' ', $arguments)."\n".$output);

        return $output;
    }

    /**
     * @param  list<string>  $command
     * @return array{0: string, 1: int}
     */
    private function process(array $command, ?string $input = null): array
    {
        $errors = $this->workDirectory.'/process-errors.log';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes, $this->controlPlane, $this->environment);
        if ($process === false) {
            throw new RuntimeException('Could not start '.implode(' ', $command));
        }
        if ($input !== null) {
            fwrite($pipes[0], $input);
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        return [$output.(string) @file_get_contents($errors), $exit];
    }

    private function serveControlPlane(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $log = $this->workDirectory.'/server.log';
        $router = $this->controlPlane.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
        $this->server = proc_open(
            [...$this->php(), '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->controlPlane.'/public',
            $this->environment,
        );

        $client = new Client(['http_errors' => false, 'timeout' => 2]);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                if ($client->get("http://127.0.0.1:{$port}/up")->getStatusCode() === 200) {
                    return $port;
                }
            } catch (Throwable) {
                // not listening yet
            }
            usleep(100_000);
        }

        $this->fail('The control plane did not start.'.$this->serverLog());
    }

    private function serverLog(): string
    {
        return "\n--- control plane log ---\n".substr((string) @file_get_contents($this->workDirectory.'/server.log'), -4000);
    }

    private function routeClientRequestsTo(int $port): void
    {
        $client = new Client(['http_errors' => false, 'timeout' => 20]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($client, $port) {
            $url = $request->url();
            if (! str_starts_with($url, self::PRIMARY.'/') && ! str_starts_with($url, self::STANDBY.'/')) {
                throw new RuntimeException("The client called an address the bundle did not name: {$url}");
            }

            $response = $client->request($request->method(), "http://127.0.0.1:{$port}".parse_url($url, PHP_URL_PATH), [
                'headers' => array_map(fn (array $values) => implode(', ', $values), array_diff_key($request->headers(), ['Host' => true])),
                'body' => $request->body(),
            ]);

            if ($this->loseRenewalAnswers && str_ends_with($url, '/v1/renewals')) {
                return Http::response('', 503);
            }

            return Http::response((string) $response->getBody(), $response->getStatusCode(), ['Content-Type' => $response->getHeaderLine('Content-Type')]);
        });
    }
}
