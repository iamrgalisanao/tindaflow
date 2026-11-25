<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Terminal;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesHostHardware;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-019 slice 3: licensing a shop that already trades. `--adopt` records the license for the store that is already here and
 * changes nothing else; without it, activation never touches an existing shop. Every signature is made with the TEST-ONLY key.
 */
class AdoptStoreCommandTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private string $identityFile = '';

    private string $workFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityFile = sys_get_temp_dir().'/tindaflow-test-identity-'.uniqid('', true).'.json';
        $this->workFile = sys_get_temp_dir().'/tindaflow-test-adopt-'.uniqid('', true);
        config(['tindaflow.license.installation_file' => $this->identityFile, 'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
        $this->fakeHostHardware();
    }

    protected function tearDown(): void
    {
        @unlink($this->identityFile);
        @unlink($this->workFile);
        parent::tearDown();
    }

    /** A shop that has traded: one store with a till, a cashier, an open shift and a sale. */
    private function tradingShop(): Store
    {
        Sale::factory()->create();

        return Store::sole();
    }

    /** @return array<string, mixed> */
    private function adoptionRequest(): array
    {
        $this->assertSame(0, Artisan::call('tindaflow:activate', ['--request' => true, '--adopt' => true, '--out' => $this->workFile]), Artisan::output());
        $request = json_decode((string) file_get_contents($this->workFile), true);
        @unlink($this->workFile);

        return $request;
    }

    /**
     * The provider's bundle for a request, for the given store.
     *
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $certificate  overrides of the certificate
     */
    private function bundle(array $request, string $storeId, array $certificate = []): string
    {
        $now = CarbonImmutable::now();
        $deployment = DeploymentId::shorten($storeId);
        $token = self::sign(self::payload($certificate + [
            'store' => $storeId, 'installation' => $request['installation'], 'deployment' => $deployment, 'seq' => 1, 'prev' => null,
            'iat' => $now->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays(30)->format('Y-m-d\TH:i:sP'),
        ]));
        file_put_contents($this->workFile, self::sign(self::bundlePayload([
            'store_id' => $storeId, 'deployment' => $deployment, 'installation' => $request['installation'],
            'installation_key' => $request['installation_key'], 'certificate' => $token, 'iat' => $now->format('Y-m-d\TH:i:sP'),
        ])));

        return $this->workFile;
    }

    /** @param  array<string, mixed>  $options */
    private function apply(string $bundle, array $options = ['--adopt' => true]): int
    {
        return Artisan::call('tindaflow:activate', ['--bundle' => $bundle] + $options);
    }

    /** @return array<string, string> table => rows and a digest, for everything but licensing, audit and cache */
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

    private function assertNothingChanged(array $shop): void
    {
        $this->assertSame($shop, $this->shopRecords());
        $this->assertSame(0, DB::table('license_state')->whereNotNull('certificate')->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'LICENSE_ACTIVATED')->count());
    }

    public function test_the_adoption_request_names_the_shop_that_is_here(): void
    {
        $store = $this->tradingShop();

        $request = $this->adoptionRequest();

        $this->assertSame(['id' => $store->id, 'name' => $store->name], $request['store']);
        $this->assertSame('tindaflow.activation-request', $request['typ']);
        $this->assertEquals(self::testFingerprint(), $request['fingerprint']);
        $this->assertArrayNotHasKey('secret_key', $request);
    }

    public function test_an_adoption_request_needs_exactly_one_shop(): void
    {
        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--adopt' => true, '--out' => $this->workFile]));
        $this->assertStringContainsString('no shop', Artisan::output());

        Store::factory()->count(2)->create();
        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--adopt' => true, '--out' => $this->workFile]));
        $this->assertStringContainsString('2 stores', Artisan::output());

        $this->assertFileDoesNotExist($this->workFile);
        $this->assertFileDoesNotExist($this->identityFile, 'a refused request leaves no identity behind');
    }

    public function test_a_plain_request_from_a_computer_that_already_has_a_shop_is_refused_early(): void
    {
        $store = $this->tradingShop();

        $this->assertSame(1, Artisan::call('tindaflow:activate', ['--request' => true, '--out' => $this->workFile]));
        $output = Artisan::output(); // reading it empties it

        $this->assertStringContainsString("already has a shop ('{$store->name}')", $output);
        $this->assertStringContainsString('--adopt', $output);
        $this->assertFileDoesNotExist($this->workFile);
    }

    public function test_adopting_licenses_the_shop_and_changes_nothing_else(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id);
        $shop = $this->shopRecords();
        $auditsBefore = AuditEvent::count();

        $exit = $this->apply($bundle);
        $output = Artisan::output(); // reading it empties it
        $this->assertSame(0, $exit, $output);

        $this->assertSame($shop, $this->shopRecords(), 'no user, setting, till, location or sale was added or changed');
        $this->assertSame($auditsBefore + 1, AuditEvent::count());
        $event = AuditEvent::where('event_type', 'LICENSE_ACTIVATED')->sole();
        $this->assertTrue($event->after_metadata['adopted']);
        $this->assertNull($event->actor_user_id, 'run from the console, by nobody who signed in');
        $state = DB::table('license_state')->where('id', 1)->first();
        $this->assertSame(1, (int) $state->certificate_seq);
        $this->assertSame(['https://licensing.example.ph', 'https://licensing-standby.example.ph'], json_decode($state->control_plane, true));
        $this->assertSame(LicenseState::Active, app(LicenseGate::class)->check()->verdict->state);
        $this->assertNull(app(LicenseGate::class)->check()->verdict->shiftOpenRefusal());
        $this->assertSame(DeploymentId::shorten($store->id), DeploymentId::current());
        $this->assertStringContainsString("Adopted '{$store->name}'", $output);
        $this->assertStringContainsString('A shift is open', $output);
    }

    public function test_adopting_in_report_mode_refuses_nothing_even_after_it_lapses(): void
    {
        $store = $this->tradingShop();
        $this->assertSame(0, $this->apply($this->bundle($this->adoptionRequest(), $store->id, ['mode' => 'report'])));
        $this->assertStringContainsString('report mode', Artisan::output());

        $this->travel(40)->days();

        $verdict = app(LicenseGate::class)->check()->verdict;
        $this->assertSame(LicenseState::Expired, $verdict->state);
        $this->assertFalse($verdict->enforcing);
    }

    public function test_more_tills_than_the_license_covers_is_said_and_blocks_nothing(): void
    {
        $store = $this->tradingShop();
        Terminal::factory()->count(2)->create(['store_id' => $store->id]);

        $this->assertSame(0, $this->apply($this->bundle($this->adoptionRequest(), $store->id, ['seats' => 1])));
        $output = Artisan::output();

        $this->assertStringContainsString('3 tills', $output);
        $this->assertStringContainsString('covers 1', $output);
    }

    public function test_adopting_again_changes_nothing(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id);
        $this->assertSame(0, $this->apply($bundle));
        $counts = [AuditEvent::count(), DB::table('license_state')->count()];

        $this->assertSame(0, $this->apply($bundle));

        $this->assertStringContainsString('Already activated', Artisan::output());
        $this->assertSame($counts, [AuditEvent::count(), DB::table('license_state')->count()]);
    }

    public function test_adopting_refuses_a_bundle_for_another_store(): void
    {
        $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle));

        $this->assertStringContainsString('made for another store', Artisan::output());
        $this->assertNothingChanged($shop);
    }

    public function test_adopting_refuses_when_the_database_holds_more_than_the_one_store(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id);
        Store::factory()->create();
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle));

        $this->assertStringContainsString('2 stores', Artisan::output());
        $this->assertNothingChanged($shop);
    }

    public function test_adopting_checks_the_hardware_like_any_activation(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id, ['fp' => ['board_serial' => str_repeat('0', 64), 'bios_serial' => str_repeat('1', 64)] + self::testFingerprint()]);
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle));

        $this->assertStringContainsString('different hardware', Artisan::output());
        $this->assertNothingChanged($shop);
    }

    public function test_adopting_takes_no_administrator_or_manager(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id);
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle, ['--adopt' => true, '--admin-email' => 'owner@example.test']));

        $this->assertStringContainsString('creates no user', Artisan::output());
        $this->assertNothingChanged($shop);
    }

    public function test_without_adopt_a_shop_with_sales_is_refused_and_told_what_to_do(): void
    {
        $store = $this->tradingShop();
        $bundle = $this->bundle($this->adoptionRequest(), $store->id);
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle, ['--admin-email' => 'new-owner@example.test']));
        $output = Artisan::output();

        $this->assertStringContainsString('already holds sales', $output);
        $this->assertStringContainsString('--adopt', $output);
        $this->assertNothingChanged($shop);
    }

    /** Before this, a shop with no sales yet was set up again over itself: a new administrator, slip mode, a stock location and a till. */
    public function test_without_adopt_a_shop_with_no_sales_yet_is_not_set_up_over_itself(): void
    {
        $store = Store::factory()->create();
        $request = $this->adoptionRequest();
        $bundle = $this->bundle($request, $store->id);
        $shop = $this->shopRecords();

        $this->assertSame(1, $this->apply($bundle, ['--admin-email' => 'new-owner@example.test']));
        $output = Artisan::output();

        $this->assertStringContainsString('already holds this store', $output);
        $this->assertStringContainsString('--adopt', $output);
        $this->assertNothingChanged($shop);
    }
}
