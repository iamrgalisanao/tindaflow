<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Sale;
use App\Models\Store;
use App\Services\License\InstallationIdentity;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\License\RefusalReason;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FakesHostHardware;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-019 slice 3: a licensed shop on a new or repaired computer. The usual case is a whole-shop restore: the database and the
 * identity file are the old computer's, and the hardware is not, so the license is locked. `--replace` asks for a license for this
 * computer under a new identity, which takes over only when the provider's bundle is applied. TEST-ONLY keys throughout.
 */
class ReplaceComputerCommandTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private string $directory = '';

    private Store $store;

    /** @var array{installation: string, public_key: string, secret_key: string} */
    private array $oldIdentity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/tindaflow-test-replace-'.uniqid('', true);
        mkdir($this->directory.'/license', 0700, true);
        config(['tindaflow.license.installation_file' => $this->directory.'/license/installation.json', 'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);

        // A shop that traded, licensed to the old computer, restored onto this one.
        Sale::factory()->create();
        $this->store = Store::sole();
        $this->oldIdentity = app(InstallationIdentity::class)->ensure();
        $now = CarbonImmutable::now();
        DB::table('license_state')->updateOrInsert(['id' => 1], [
            'certificate' => $this->certificate($this->oldIdentity['installation'], $this->oldHardware(), ['seq' => 9, 'iat' => $now->subDays(3)->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays(27)->format('Y-m-d\TH:i:sP')]),
            'certificate_seq' => 9, 'revoked' => false, 'control_plane' => json_encode(['https://old.example.ph']),
            'extended_until' => $now->addDays(40)->format('Y-m-d\TH:i:sP'), 'extension_base_seq' => 9, 'extensions_in_a_row' => 2, 'last_code_ordinal' => 5,
            'pending_request_id' => (string) Str::uuid(), 'last_renewal_error' => 'HTTP 403', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeHostHardware(); // this computer reports the TEST hashes, which are not the old computer's
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/{,license/}*', GLOB_BRACE) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->directory.'/license');
        @rmdir($this->directory);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function oldHardware(): array
    {
        return ['smbios_uuid' => str_repeat('1', 64), 'board_serial' => str_repeat('2', 64), 'bios_serial' => str_repeat('3', 64)];
    }

    /** @param  array<string, string>  $hardware  @param  array<string, mixed>  $overrides */
    private function certificate(string $installation, array $hardware, array $overrides = [], ?string $storeId = null): string
    {
        $now = CarbonImmutable::now();
        $storeId ??= $this->store->id;

        return self::sign(self::payload($overrides + [
            'store' => $storeId, 'installation' => $installation, 'deployment' => DeploymentId::shorten($storeId), 'fp' => $hardware, 'seq' => 1, 'prev' => null,
            'iat' => $now->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays(30)->format('Y-m-d\TH:i:sP'),
        ]));
    }

    /** @return array<string, mixed> */
    private function request(array $options = ['--replace' => true]): array
    {
        $path = $this->directory.'/request.json';
        $exit = Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $path] + $options);
        $this->assertSame(0, $exit, Artisan::output());
        $request = json_decode((string) file_get_contents($path), true);
        unlink($path);

        return $request;
    }

    /** @param  array<string, mixed>  $request  @param  array<string, mixed>  $certificate */
    private function bundle(array $request, array $certificate = [], ?string $storeId = null, ?string $path = null): string
    {
        $storeId ??= $this->store->id;
        file_put_contents($path ??= $this->directory.'/bundle.txt', self::sign(self::bundlePayload([
            'store_id' => $storeId, 'deployment' => DeploymentId::shorten($storeId), 'installation' => $request['installation'],
            'installation_key' => $request['installation_key'], 'iat' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP'),
            'certificate' => $this->certificate($request['installation'], $certificate['fp'] ?? self::testFingerprint(), $certificate, $storeId),
        ])));

        return $path;
    }

    /** @param  array<string, mixed>  $options */
    private function apply(string $bundle, array $options = ['--replace' => true]): array
    {
        $exit = Artisan::call('tindaflow:activate', ['--bundle' => $bundle] + $options);

        return [$exit, Artisan::output()];
    }

    private function identity(): string
    {
        return app(InstallationIdentity::class)->load()['installation'];
    }

    private function state(): object
    {
        return DB::table('license_state')->where('id', 1)->first();
    }

    private function verdict(): array
    {
        $verdict = app(LicenseGate::class)->check()->verdict;

        return [$verdict->state, $verdict->shiftOpenRefusal()];
    }

    /** @return array<string, string> */
    private function shopRecords(): array
    {
        $records = [];
        foreach (DB::select("select table_name as name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE' order by 1") as $table) {
            if (! in_array($table->name, ['license_state', 'audit_events', 'cache', 'cache_locks', 'sessions'], true)) {
                $records[$table->name] = DB::selectOne("select count(*) || ':' || coalesce(md5(string_agg(t::text, ',' order by t::text)), '') as digest from \"{$table->name}\" t")->digest;
            }
        }

        return $records;
    }

    /** Runs something that must be refused and checks that neither the license nor this computer's identity moved. */
    private function assertRefusedAndUnchanged(string $needle, string $bundle, array $options = ['--replace' => true]): void
    {
        $before = [(array) $this->state(), $this->identity(), $this->shopRecords(), AuditEvent::count()];

        [$exit, $output] = $this->apply($bundle, $options);

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString($needle, $output);
        $this->assertSame($before, [(array) $this->state(), $this->identity(), $this->shopRecords(), AuditEvent::count()]);
    }

    public function test_a_restored_shop_on_other_hardware_is_locked(): void
    {
        $this->assertSame([LicenseState::Locked, RefusalReason::FingerprintMismatch], $this->verdict());
    }

    public function test_the_request_makes_a_new_identity_and_keeps_the_old_one_in_use(): void
    {
        $request = $this->request();

        $this->assertNotSame($this->oldIdentity['installation'], $request['installation']);
        $this->assertNotSame($this->oldIdentity['public_key'], $request['installation_key']);
        $this->assertSame($this->oldIdentity['installation'], $request['replaces']);
        $this->assertSame(['id' => $this->store->id, 'name' => $this->store->name], $request['store']);
        $this->assertEquals(self::testFingerprint(), $request['fingerprint']);
        $this->assertArrayNotHasKey('secret_key', $request);
        $this->assertSame($this->oldIdentity['installation'], $this->identity(), 'asking changes nothing');
        $this->assertSame($request, $this->request(), 'asking again gives the same request');
    }

    public function test_a_request_without_the_old_identity_file_still_names_what_the_shop_last_held(): void
    {
        unlink(config('tindaflow.license.installation_file'));

        $this->assertSame($this->oldIdentity['installation'], $this->request()['replaces']);
    }

    public function test_requests_that_cannot_lead_anywhere_are_refused_and_point_the_right_way(): void
    {
        $path = $this->directory.'/request.json';
        $refused = function (array $options, string $needle) use ($path) {
            $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $path] + $options));
            $this->assertStringContainsString($needle, Artisan::output());
            $this->assertFileDoesNotExist($path);
        };

        $refused([], '--replace');
        $refused(['--adopt' => true], '--replace');
        $refused(['--adopt' => true, '--replace' => true], 'not both');

        DB::table('license_state')->where('id', 1)->update(['revoked' => true]);
        $refused(['--replace' => true], 'revoked');

        DB::table('license_state')->where('id', 1)->update(['revoked' => false]);
        config(['tindaflow.license.hardware_file' => $this->directory.'/no-hardware.json']);
        $refused(['--replace' => true], 'host helper');

        $this->fakeHostHardware();
        Store::factory()->create();
        $refused(['--replace' => true], '2 stores');

        $this->assertFileDoesNotExist(app(InstallationIdentity::class)->pendingPath(), 'a refused request leaves no new identity behind');
    }

    public function test_applying_the_replacement_changes_only_the_license_and_unlocks_the_shop(): void
    {
        $bundle = $this->bundle($request = $this->request());
        $shop = $this->shopRecords();
        $audits = AuditEvent::count();

        [$exit, $output] = $this->apply($bundle);

        $this->assertSame(0, $exit, $output);
        $this->assertSame($shop, $this->shopRecords());
        $this->assertSame($audits + 1, AuditEvent::count());
        $this->assertSame([LicenseState::Active, null], $this->verdict());
        $this->assertSame($request['installation'], $this->identity());
        $this->assertFileDoesNotExist(app(InstallationIdentity::class)->pendingPath());
        $this->assertStringContainsString("'{$this->store->name}' is now licensed to this computer", $output);
        $this->assertStringContainsString('old computer', $output);

        $state = $this->state();
        $this->assertSame(1, (int) $state->certificate_seq);
        $this->assertNull($state->extended_until);
        $this->assertNull($state->extension_base_seq);
        $this->assertSame(0, (int) $state->extensions_in_a_row);
        $this->assertSame(0, (int) $state->last_code_ordinal, 'codes are made for one computer, so the new one counts from the start');
        $this->assertNull($state->pending_request_id);
        $this->assertNull($state->last_renewal_error);
        $this->assertSame(['https://licensing.example.ph', 'https://licensing-standby.example.ph'], json_decode($state->control_plane, true));
        $event = AuditEvent::where('event_type', 'LICENSE_ACTIVATED')->sole();
        $this->assertSame($this->oldIdentity['installation'], $event->after_metadata['replaced_installation']);
    }

    public function test_applying_it_again_changes_nothing(): void
    {
        $bundle = $this->bundle($this->request());
        $this->assertSame(0, $this->apply($bundle)[0]);
        $before = [(array) $this->state(), $this->identity(), AuditEvent::count()];

        [$exit, $output] = $this->apply($bundle);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Already', $output);
        $this->assertSame($before, [(array) $this->state(), $this->identity(), AuditEvent::count()]);
    }

    /** The database was committed and the power failed before the identity file was swapped. Applying again finishes the job. */
    public function test_a_swap_cut_short_is_finished_by_applying_again(): void
    {
        $bundle = $this->bundle($this->request());
        $pending = (string) file_get_contents(app(InstallationIdentity::class)->pendingPath());
        $old = (string) file_get_contents(config('tindaflow.license.installation_file'));
        $this->assertSame(0, $this->apply($bundle)[0]);
        file_put_contents(app(InstallationIdentity::class)->pendingPath(), $pending);
        file_put_contents(config('tindaflow.license.installation_file'), $old);
        $this->assertSame(LicenseState::NotActivated, $this->verdict()[0]);

        $this->assertSame(0, $this->apply($bundle)[0]);

        $this->assertSame([LicenseState::Active, null], $this->verdict());
        $this->assertSame(1, AuditEvent::where('event_type', 'LICENSE_ACTIVATED')->count());
    }

    public function test_a_shop_whose_identity_file_was_lost_can_be_replaced(): void
    {
        unlink(config('tindaflow.license.installation_file'));
        $bundle = $this->bundle($this->request());

        [$exit, $output] = $this->apply($bundle);

        $this->assertSame(0, $exit, $output);
        $this->assertSame([LicenseState::Active, null], $this->verdict());
    }

    public function test_a_bundle_nothing_here_asked_for_is_refused(): void
    {
        $stranger = ['installation' => (string) Str::uuid(), 'installation_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))];
        $this->assertRefusedAndUnchanged('no replacement request', $this->bundle($stranger));

        $this->request();
        $this->assertRefusedAndUnchanged('different computer', $this->bundle($stranger));
    }

    public function test_the_replacement_checks_the_hardware_the_store_and_the_certificate(): void
    {
        $request = $this->request();

        $this->assertRefusedAndUnchanged('different hardware', $this->bundle($request, ['fp' => $this->oldHardware()]));
        $this->assertRefusedAndUnchanged('another store', $this->bundle($request, [], 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'));
        $past = CarbonImmutable::now()->subDays(40);
        $this->assertRefusedAndUnchanged('already expired', $this->bundle($request, ['iat' => $past->format('Y-m-d\TH:i:sP'), 'exp' => $past->addDays(30)->format('Y-m-d\TH:i:sP')]));
    }

    public function test_a_revoked_shop_is_not_brought_back_by_a_replacement(): void
    {
        $bundle = $this->bundle($this->request());
        DB::table('license_state')->where('id', 1)->update(['revoked' => true]);

        $this->assertRefusedAndUnchanged('revoked', $bundle);
    }

    public function test_a_replacement_takes_no_user_and_is_not_adoption(): void
    {
        $bundle = $this->bundle($this->request());

        $this->assertRefusedAndUnchanged('creates no user', $bundle, ['--replace' => true, '--admin-email' => 'owner@example.test']);
        $this->assertRefusedAndUnchanged('not both', $bundle, ['--replace' => true, '--adopt' => true]);
    }

    public function test_the_other_ways_of_applying_it_point_to_replace(): void
    {
        $bundle = $this->bundle($this->request());
        $before = (array) $this->state();

        foreach ([['--adopt' => true], ['--admin-email' => 'owner@example.test']] as $options) {
            [$exit, $output] = $this->apply($bundle, $options);
            $this->assertSame(1, $exit, $output);
        }

        $this->assertSame($before, (array) $this->state());
        $this->assertSame($this->oldIdentity['installation'], $this->identity());
    }

    public function test_the_replacement_never_moves_the_recorded_time_back(): void
    {
        $mark = CarbonImmutable::now()->addMinutes(30);
        DB::table('license_state')->where('id', 1)->update(['trusted_time_hwm' => $mark->format('Y-m-d\TH:i:sP')]);

        $this->assertSame(0, $this->apply($this->bundle($this->request()))[0]);

        $this->assertSame($mark->getTimestamp(), CarbonImmutable::parse($this->state()->trusted_time_hwm)->getTimestamp());
    }
}
