<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\License\InstallationIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesHostHardware;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * openapi License tag (ADR-018 decision 10): the Manager area's license screen over HTTP. LICENSE_MANAGE is MANAGER and ADMIN; the
 * three operations are never gated by the license itself; nothing secret is returned; who asked is audited. TEST-ONLY keys.
 */
class LicenseHttpTest extends PostgresSchemaTestCase
{
    use FakesHostHardware;
    use SignsTestCertificates;

    private const STORE = '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11';

    private Store $store;

    private string $identityFile = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Manila']);
        $this->store = (new Store)->forceFill(['id' => self::STORE, 'name' => 'TSC Canteen']);
        $this->store->save();
    }

    protected function tearDown(): void
    {
        if ($this->identityFile !== '') {
            @unlink($this->identityFile);
        }
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['store_id' => $this->store->id, 'role' => $role]);
    }

    private function as(User $user): static
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function licenseStatus(User $user): TestResponse
    {
        return $this->as($user)->getJson('/api/v1/license');
    }

    /** An activated installation whose certificate expires `$daysToExpiry` from now. */
    private function activated(int $daysToExpiry = 20): string
    {
        config([
            'tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()],
            'tindaflow.license.code_keys' => [1 => self::testCodePublicKeyBase64()],
        ]);
        $now = CarbonImmutable::now()->setTimezone('Asia/Manila');
        $token = self::sign(self::payload(['iat' => $now->subDays(10)->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays($daysToExpiry)->format('Y-m-d\TH:i:sP')]));
        DB::table('license_state')->updateOrInsert(['id' => 1], [
            'certificate' => $token, 'certificate_seq' => 42, 'control_plane' => json_encode(['https://cp.example.ph']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeHostHardware();

        return $token;
    }

    public function test_only_managers_and_administrators_may_use_the_license_screen(): void
    {
        $this->getJson('/api/v1/license')->assertStatus(401);

        $cashier = $this->user('CASHIER');
        $this->licenseStatus($cashier)->assertStatus(403);
        $this->as($cashier)->postJson('/api/v1/license/renew')->assertStatus(403);
        $this->as($cashier)->postJson('/api/v1/license/codes', ['code' => 'x'])->assertStatus(403);

        $this->licenseStatus($this->user('MANAGER'))->assertOk();
        $this->licenseStatus($this->user('ADMIN'))->assertOk();
    }

    public function test_a_build_with_no_vendor_key_says_legacy_and_reads_no_license_table(): void
    {
        $response = $this->licenseStatus($this->user('MANAGER'));

        $response->assertOk()->assertJson(['state' => 'LEGACY', 'enforcing' => false, 'certificate_number' => null, 'valid_until' => null, 'accepts_offline_codes' => false, 'hardware_file' => null]);
        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_an_active_license_reports_how_long_it_runs_and_what_the_provider_needs_and_nothing_secret(): void
    {
        $token = $this->activated(20);

        $response = $this->licenseStatus($this->user('MANAGER'));

        $response->assertOk()->assertJson([
            'state' => 'ACTIVE', 'enforcing' => true, 'reason' => null, 'message' => null, 'certificate_number' => 42, 'seats' => 1,
            'extended_until' => null, 'extensions_used' => 0, 'extensions_allowed' => 2, 'accepts_offline_codes' => true,
            'hardware_file' => ['status' => 'FRESH'],
        ]);
        $this->assertSame($response->json('certificate_expires_at'), $response->json('valid_until'));
        $this->assertStringEndsWith('+08:00', $response->json('valid_until'));

        $body = $response->getContent();
        $this->assertStringNotContainsString($token, $body, 'never the certificate');
        foreach (self::testFingerprint() as $hash) {
            $this->assertStringNotContainsString($hash, $body, 'never the hardware hashes');
        }
    }

    public function test_an_expired_license_says_why_a_new_shift_is_refused_and_the_screen_still_works(): void
    {
        $this->activated(-1);

        $this->licenseStatus($this->user('MANAGER'))->assertOk()->assertJson(['state' => 'EXPIRED', 'reason' => 'EXPIRED', 'message' => 'The license has expired. Renew it, or ask your TindaFlow provider.']);
    }

    public function test_a_manager_can_extend_an_expired_license_with_a_code_and_is_recorded(): void
    {
        $this->activated(-1);
        $manager = $this->user('MANAGER');
        $code = self::signCode(self::offlineCode(['iat' => CarbonImmutable::now()->subMinute()->format('Y-m-d\TH:i:sP')]));

        $response = $this->as($manager)->postJson('/api/v1/license/codes', ['code' => " {$code}\n"]);

        $response->assertOk()->assertJsonPath('license.state', 'ACTIVE')->assertJsonPath('license.extensions_used', 1);
        $this->assertStringContainsString('Extended', $response->json('message'));
        $this->assertNotNull($response->json('license.extended_until'));
        $this->assertSame($response->json('license.extended_until'), $response->json('license.valid_until'));
        $this->assertSame($manager->id, AuditEvent::where('event_type', 'LICENSE_EXTENDED')->sole()->actor_user_id);
    }

    public function test_a_refused_code_is_a_422_that_changes_nothing_and_is_recorded_without_the_code(): void
    {
        $this->activated(-1);
        $manager = $this->user('MANAGER');
        $code = self::signCode(self::offlineCode(['baseSeq' => 41, 'iat' => CarbonImmutable::now()->format('Y-m-d\TH:i:sP')]));

        $response = $this->as($manager)->postJson('/api/v1/license/codes', ['code' => $code]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'LICENSE_CODE_REFUSED');
        $this->assertStringContainsString('holds certificate 42', $response->json('error.message'));
        $this->assertStringContainsString('Nothing was changed.', $response->json('error.message'));
        $this->assertNull(DB::table('license_state')->value('extended_until'));
        $event = AuditEvent::where('event_type', 'LICENSE_CODE_REFUSED')->sole();
        $this->assertSame($manager->id, $event->actor_user_id);
        $this->assertStringNotContainsString(substr($code, 0, 40), json_encode($event->toArray()));
    }

    public function test_a_code_is_required(): void
    {
        $this->activated();

        $this->as($this->user('MANAGER'))->postJson('/api/v1/license/codes', [])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_check_now_in_a_build_with_no_vendor_key_says_there_is_nothing_to_renew(): void
    {
        Http::fake();

        $this->as($this->user('MANAGER'))->postJson('/api/v1/license/renew')->assertOk()->assertJsonPath('outcome', 'SKIPPED')->assertJsonPath('license.state', 'LEGACY');

        Http::assertNothingSent();
        $this->assertSame(0, AuditEvent::where('event_type', 'LICENSE_CHECK_REQUESTED')->count());
    }

    public function test_check_now_reports_a_provider_that_cannot_be_reached_as_a_normal_answer_and_records_who_asked(): void
    {
        $this->activated();
        $this->identityFile = sys_get_temp_dir().'/tindaflow-test-identity-'.uniqid().'.json';
        config(['tindaflow.license.installation_file' => $this->identityFile]);
        $identity = app(InstallationIdentity::class)->ensure();
        file_put_contents($this->identityFile, json_encode(['installation' => '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40'] + $identity));
        Http::fake(['*' => Http::response('', 503)]);
        $manager = $this->user('MANAGER');

        $response = $this->as($manager)->postJson('/api/v1/license/renew');

        $response->assertOk()->assertJsonPath('outcome', 'FAILED')->assertJsonPath('license.state', 'ACTIVE');
        $this->assertStringContainsString('could not be reached', $response->json('message'));
        $this->assertStringContainsString('could not be reached', $response->json('license.last_renewal_error'));
        $event = AuditEvent::where('event_type', 'LICENSE_CHECK_REQUESTED')->sole();
        $this->assertSame($manager->id, $event->actor_user_id);
        $this->assertSame('FAILED', $event->after_metadata['outcome']);
    }

    public function test_check_now_is_limited_per_user(): void
    {
        $manager = $this->user('MANAGER');
        for ($i = 0; $i < 4; $i++) {
            $this->as($manager)->postJson('/api/v1/license/renew')->assertOk();
        }

        $this->as($manager)->postJson('/api/v1/license/renew')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
