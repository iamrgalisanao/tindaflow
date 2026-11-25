<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\InventoryLocation;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreSettings;
use App\Models\Terminal;
use App\Models\User;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesHostHardware;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-018 decision 9: `tindaflow:activate`. The request makes this PC's identity once; the bundle (vendor-signed, bound to that
 * identity) creates the store, users, settings, stock location and till and stores the first certificate, all or nothing, and
 * running it again changes nothing. Every signature here is made with the TEST-ONLY key of SignsTestCertificates.
 */
class ActivateCommandTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private const STORE_ID = '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11';

    private const DEPLOYMENT = '6A5D4E11';

    private string $identityFile;

    private string $bundleFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityFile = sys_get_temp_dir().'/tindaflow-test-identity-'.uniqid().'.json';
        $this->bundleFile = sys_get_temp_dir().'/tindaflow-test-bundle-'.uniqid().'.txt';
        config([
            'tindaflow.license.installation_file' => $this->identityFile,
            'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()],
        ]);
        $this->fakeHostHardware();
    }

    protected function tearDown(): void
    {
        @unlink($this->identityFile);
        @unlink($this->bundleFile);
        parent::tearDown();
    }

    /** @return array{installation: string, installation_key: string} */
    private function makeRequest(): array
    {
        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $this->bundleFile]));
        $request = json_decode((string) file_get_contents($this->bundleFile), true);
        @unlink($this->bundleFile);

        return $request;
    }

    /** Writes a bundle for the request. `$certificate` and `$bundle` override fields of the certificate and the bundle. */
    private function writeBundle(array $request, array $certificate = [], array $bundle = []): void
    {
        $now = CarbonImmutable::now();
        $token = self::sign(self::payload($certificate + [
            'store' => self::STORE_ID,
            'installation' => $request['installation'],
            'deployment' => self::DEPLOYMENT,
            'seq' => 1,
            'prev' => null,
            'iat' => $now->format('Y-m-d\TH:i:sP'),
            'exp' => $now->addDays(30)->format('Y-m-d\TH:i:sP'),
        ]));

        file_put_contents($this->bundleFile, self::sign(self::bundlePayload($bundle + [
            'store_id' => self::STORE_ID,
            'deployment' => self::DEPLOYMENT,
            'installation' => $request['installation'],
            'installation_key' => $request['installation_key'],
            'certificate' => $token,
            'iat' => $now->format('Y-m-d\TH:i:sP'),
        ])));
    }

    private function activate(array $options = []): int
    {
        return Artisan::call('tindaflow:activate', ['--bundle' => $this->bundleFile, '--admin-email' => 'owner@example.test'] + $options);
    }

    public function test_the_request_makes_one_identity_and_repeats_it(): void
    {
        $first = $this->makeRequest();
        $second = $this->makeRequest();

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $first['installation']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($first['installation_key'], true)));
        $this->assertArrayNotHasKey('secret_key', $first, 'the private half never leaves this PC');
    }

    public function test_the_request_carries_the_host_helpers_hardware_hashes(): void
    {
        $this->assertSame(self::testFingerprint(), $this->makeRequest()['fingerprint']);
    }

    public function test_a_request_cannot_be_made_without_a_current_hardware_file(): void
    {
        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);

        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $this->bundleFile]));
        $this->assertStringContainsString('host helper has not written a current hardware file', Artisan::output());
        $this->assertFileDoesNotExist($this->bundleFile);
        $this->assertFileDoesNotExist($this->identityFile, 'a refused request leaves no identity behind');
    }

    public function test_it_needs_exactly_one_of_request_and_bundle(): void
    {
        $this->assertSame(1, Artisan::call('tindaflow:activate'));
        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--bundle' => $this->bundleFile]));
    }

    public function test_a_valid_bundle_creates_the_whole_store_and_stores_the_certificate(): void
    {
        $this->writeBundle($this->makeRequest());

        $this->assertSame(0, $this->activate());
        $output = Artisan::output();

        $store = Store::findOrFail(self::STORE_ID);
        $this->assertSame('TSC Canteen', $store->name);
        $this->assertSame(self::DEPLOYMENT, DeploymentId::current());

        $admin = User::where('store_id', $store->id)->where('role', 'ADMIN')->sole();
        $this->assertSame('owner@example.test', $admin->email);
        $passphrase = collect(explode("\n", $output))->map(fn ($line) => trim($line))->first(fn ($line) => strlen($line) === 20 && ! str_contains($line, ' '));
        $this->assertTrue(Hash::check($passphrase, $admin->password_hash), 'the passphrase shown once is the one stored (hashed)');

        $settings = StoreSettings::findOrFail($store->id);
        $this->assertSame('SLIP', $settings->sale_document_mode);
        $this->assertStringStartsWith('THIS DOCUMENT IS NOT A BIR-REGISTERED INVOICE', $settings->slip_footer);

        $location = InventoryLocation::where('store_id', $store->id)->sole();
        $this->assertSame('Main stockroom', $location->name);
        $this->assertTrue($location->is_default);

        $till = Terminal::where('store_id', $store->id)->sole();
        $this->assertSame('TILL-1', $till->terminal_code);
        $this->assertNull($till->activated_at, 'created but not yet enrolled; enrollment is a separate step');

        $state = DB::table('license_state')->where('id', 1)->first();
        $this->assertNotNull($state->certificate);
        $this->assertSame(1, (int) $state->certificate_seq);
        $this->assertNotNull($state->trusted_time_hwm);
        $this->assertSame(['https://licensing.example.ph', 'https://licensing-standby.example.ph'], json_decode($state->control_plane, true), 'the renewal client needs the provider addresses the bundle named');

        $event = AuditEvent::where('event_type', 'LICENSE_ACTIVATED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame(self::DEPLOYMENT, $event->after_metadata['deployment']);
        $this->assertStringNotContainsString($passphrase, json_encode(AuditEvent::all()->toArray()), 'no audit row carries the passphrase');

        $this->assertSame(LicenseState::Active, app(LicenseGate::class)->check()->verdict->state, 'the gate now sees an active license');
    }

    public function test_a_manager_is_created_when_asked_for(): void
    {
        $this->writeBundle($this->makeRequest());

        $this->artisan('tindaflow:activate', ['--bundle' => $this->bundleFile, '--admin-email' => 'owner@example.test', '--manager-email' => 'manager@example.test'])
            ->expectsQuestion('Manager password (at least 8 characters)', 'a-long-password')
            ->expectsQuestion('Type it again', 'a-long-password')
            ->assertExitCode(0);

        $manager = User::where('role', 'MANAGER')->sole();
        $this->assertSame('manager@example.test', $manager->email);
        $this->assertTrue(Hash::check('a-long-password', $manager->password_hash));
    }

    public function test_a_mistyped_manager_password_changes_nothing(): void
    {
        $this->writeBundle($this->makeRequest());

        $this->artisan('tindaflow:activate', ['--bundle' => $this->bundleFile, '--admin-email' => 'owner@example.test', '--manager-email' => 'manager@example.test'])
            ->expectsQuestion('Manager password (at least 8 characters)', 'a-long-password')
            ->expectsQuestion('Type it again', 'a-different-one')
            ->assertExitCode(1);

        $this->assertSame(0, Store::count());
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->writeBundle($this->makeRequest());
        $this->assertSame(0, $this->activate());
        $counts = [Store::count(), User::count(), Terminal::count(), AuditEvent::count()];

        $this->assertSame(0, $this->activate());

        $this->assertStringContainsString('Already activated', Artisan::output());
        $this->assertSame($counts, [Store::count(), User::count(), Terminal::count(), AuditEvent::count()]);
    }

    public function test_a_build_with_no_vendor_key_refuses(): void
    {
        $request = $this->makeRequest();
        $this->writeBundle($request);
        config(['tindaflow.license.vendor_keys' => []]);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('no vendor key', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_a_bundle_made_for_another_computer_is_refused(): void
    {
        $request = $this->makeRequest();
        $this->writeBundle(['installation' => '11111111-2222-4333-8444-555555555555'] + $request);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('different computer', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_a_bundle_signed_by_another_key_is_refused(): void
    {
        $request = $this->makeRequest();
        $this->writeBundle($request);
        file_put_contents($this->bundleFile, self::sign(self::bundlePayload(['installation' => $request['installation'], 'installation_key' => $request['installation_key']]), 'someone-elses-key'));

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('BAD_SIGNATURE', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_a_certificate_for_another_store_than_the_bundle_is_refused(): void
    {
        $this->writeBundle($this->makeRequest(), ['store' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('do not agree', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_a_deployment_code_that_is_not_the_stores_own_is_refused(): void
    {
        $this->writeBundle($this->makeRequest(), ['deployment' => 'AAAAAAAA'], ['deployment' => 'AAAAAAAA']);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('do not agree', Artisan::output());
    }

    public function test_an_expired_certificate_is_refused(): void
    {
        $now = CarbonImmutable::now();
        $this->writeBundle($this->makeRequest(), ['iat' => $now->subDays(40)->format('Y-m-d\TH:i:sP'), 'exp' => $now->subDay()->format('Y-m-d\TH:i:sP')]);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('already expired', Artisan::output());
    }

    public function test_a_clock_more_than_an_hour_behind_the_provider_is_refused(): void
    {
        $now = CarbonImmutable::now();
        $this->writeBundle($this->makeRequest(), ['iat' => $now->addHours(2)->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays(31)->format('Y-m-d\TH:i:sP')]);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('clock is more than an hour behind', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_a_certificate_for_other_hardware_is_refused(): void
    {
        $this->writeBundle($this->makeRequest(), ['fp' => ['board_serial' => str_repeat('0', 64), 'bios_serial' => str_repeat('1', 64)] + self::testFingerprint()]);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('made for different hardware (1 of 3', Artisan::output());
        $this->assertSame(0, Store::count());
        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_one_changed_component_does_not_stop_activation(): void
    {
        $this->writeBundle($this->makeRequest(), ['fp' => ['bios_serial' => str_repeat('1', 64)] + self::testFingerprint()]);

        $this->assertSame(0, $this->activate(), Artisan::output());
    }

    public function test_activation_needs_a_current_hardware_file(): void
    {
        $this->writeBundle($this->makeRequest());
        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('hardware file is missing', Artisan::output());
        $this->assertSame(0, Store::count());
    }

    public function test_running_it_again_without_a_current_hardware_file_still_reports_already_activated(): void
    {
        $this->writeBundle($this->makeRequest());
        $this->assertSame(0, $this->activate());
        config(['tindaflow.license.hardware_file' => sys_get_temp_dir().'/tindaflow-no-such-hardware-file.json']);

        $this->assertSame(0, $this->activate());
        $this->assertStringContainsString('Already activated', Artisan::output());
    }

    public function test_a_database_that_already_holds_sales_is_refused(): void
    {
        Sale::factory()->create();
        $this->writeBundle($this->makeRequest());

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('already holds sales', Artisan::output());
        $this->assertNull(Store::find(self::STORE_ID));
    }

    public function test_a_database_with_a_different_store_is_refused(): void
    {
        Store::factory()->create();
        $this->writeBundle($this->makeRequest());

        $this->assertSame(1, $this->activate());
        $this->assertStringContainsString('different store', Artisan::output());
        $this->assertNull(Store::find(self::STORE_ID));
    }

    public function test_a_failure_part_way_leaves_nothing_behind(): void
    {
        $this->writeBundle($this->makeRequest());

        // The manager reuses the administrator's email, so creating the manager fails after the store and the administrator exist.
        $this->artisan('tindaflow:activate', ['--bundle' => $this->bundleFile, '--admin-email' => 'owner@example.test', '--manager-email' => 'owner@example.test'])
            ->expectsQuestion('Manager password (at least 8 characters)', 'a-long-password')
            ->expectsQuestion('Type it again', 'a-long-password')
            ->assertExitCode(1);

        $this->assertSame(0, Store::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('license_state')->count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_a_missing_bundle_file_or_admin_email_is_refused_without_touching_anything(): void
    {
        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--bundle' => '/no/such/file', '--admin-email' => 'owner@example.test']));

        $this->writeBundle($this->makeRequest());
        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--bundle' => $this->bundleFile]));
        $this->assertSame(0, Store::count());
    }
}
