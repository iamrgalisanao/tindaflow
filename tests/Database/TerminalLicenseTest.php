<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Terminal;
use App\Models\User;
use App\Services\License\Entitlement;
use App\Support\DeploymentId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * ADR-014: the vendor-signed licence caps how many tills an installation may run. It blocks adding tills (and putting a
 * revoked one back), never selling on the ones already enrolled.
 */
class TerminalLicenseTest extends PostgresSchemaTestCase
{
    private string $privateKeyFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateKeyFile = sys_get_temp_dir().'/tindaflow-test-license-'.uniqid().'.key';
    }

    protected function tearDown(): void
    {
        @unlink($this->privateKeyFile);
        parent::tearDown();
    }

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    /** Generates the vendor key pair with the real command and makes the installation trust its public key. */
    private function trustNewVendorKey(): void
    {
        Artisan::call('tindaflow:license-keygen', ['--private-out' => $this->privateKeyFile]);
        $publicKey = trim(collect(explode("\n", trim(Artisan::output())))->last());
        config(['tindaflow.license.public_key' => $publicKey, 'tindaflow.license.token' => '', 'tindaflow.license.path' => '']);
    }

    /** Signs a license with the real command and installs it. */
    private function installLicense(int $maxTerminals, ?string $deployment = null, ?string $expires = null): string
    {
        Artisan::call('tindaflow:license-issue', array_filter([
            '--private-key' => $this->privateKeyFile,
            '--deployment' => $deployment ?? DeploymentId::current(),
            '--max-terminals' => $maxTerminals,
            '--expires' => $expires,
        ]));
        $license = trim(Artisan::output());
        config(['tindaflow.license.token' => $license]);

        return $license;
    }

    private function adminWithStore(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_with_no_vendor_key_there_is_no_cap(): void
    {
        $admin = $this->adminWithStore();
        $login = $this->login($admin);

        foreach (['TILL-1', 'TILL-2', 'TILL-3', 'TILL-4'] as $code) {
            $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => $code])->assertStatus(201);
        }
        $this->assertNull($this->forwardSessionCookie($login)->getJson('/api/v1/terminals')->json('meta.license'));
    }

    public function test_a_licensed_installation_can_add_tills_up_to_the_limit_and_no_further(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(2);
        $login = $this->login($admin);

        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->assertStatus(201);
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-2'])->assertStatus(201);
        $refused = $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-3']);

        $refused->assertStatus(409)->assertJson(['error' => ['code' => 'TERMINAL_LIMIT_REACHED', 'details' => ['max_terminals' => 2, 'in_use' => 2]]]);
        $this->assertSame(2, Terminal::where('store_id', $admin->store_id)->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'TERMINAL_CREATED')->where('after_metadata->terminal_code', 'TILL-3')->count());
        $this->assertSame(['max_terminals' => 2, 'in_use' => 2], $this->forwardSessionCookie($login)->getJson('/api/v1/terminals')->json('meta.license'));
    }

    public function test_revoking_a_till_frees_its_seat_for_a_replacement(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(1);
        $login = $this->login($admin);
        $first = $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->json('id');
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-2'])->assertStatus(409);

        $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$first}/revoke")->assertOk();

        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-2'])->assertStatus(201);
    }

    public function test_a_revoked_till_cannot_be_put_back_when_its_seat_has_been_taken(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(1);
        $login = $this->login($admin);
        $first = $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->json('id');
        $this->forwardSessionCookie($login)->postJson("/api/v1/terminals/{$first}/revoke")->assertOk();
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-2'])->assertStatus(201);

        $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $first])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TERMINAL_LIMIT_REACHED']]);
    }

    public function test_an_unrevoked_till_can_always_get_a_new_token_even_at_the_limit(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(1);
        $login = $this->login($admin);
        $id = $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'TILL-1'])->json('id');

        $this->forwardSessionCookie($login)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $id])->assertStatus(201);
    }

    public function test_a_missing_forged_expired_or_other_installation_license_allows_no_new_till(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $login = $this->login($admin);

        // Missing.
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'A'])->assertStatus(409);

        // Signed for another installation.
        $this->installLicense(5, 'FFFFFFFF');
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'A'])->assertStatus(409);

        // Expired.
        $this->installLicense(5, null, now()->subDay()->toDateString());
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'A'])->assertStatus(409);

        // Signed by someone else's key.
        $valid = $this->installLicense(5);
        $attackerKey = base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
        file_put_contents($this->privateKeyFile, $attackerKey);
        $forged = $this->installLicense(5);
        $this->assertNotSame($valid, $forged);
        $this->forwardSessionCookie($login)->postJson('/api/v1/terminals', ['terminal_code' => 'A'])->assertStatus(409);

        $this->assertSame(0, Terminal::count());
    }

    public function test_a_tampered_license_is_rejected(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $license = $this->installLicense(1);
        [$payload, $signature] = explode('.', $license);
        $bigger = Entitlement::encode(str_replace('"max_terminals":1', '"max_terminals":99', (string) Entitlement::decode($payload)));
        config(['tindaflow.license.token' => "{$bigger}.{$signature}"]);

        $this->assertSame(0, app(Entitlement::class)->maxTerminals());
        $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/terminals', ['terminal_code' => 'A'])->assertStatus(409);
    }

    public function test_the_license_never_stops_a_till_that_is_already_enrolled_from_selling(): void
    {
        $admin = $this->adminWithStore();
        $enrolled = Terminal::factory()->create(['store_id' => $admin->store_id]);
        $this->trustNewVendorKey(); // no license installed: the cap is 0

        $this->assertSame(0, app(Entitlement::class)->maxTerminals());
        $this->assertNull($enrolled->refresh()->revoked_at);
        $this->assertSame('ACTIVE', $enrolled->status, 'existing tills are untouched; only adding is blocked');
    }

    public function test_the_console_command_respects_the_limit_too(): void
    {
        $admin = $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(1);

        $this->assertSame(0, Artisan::call('tindaflow:create-terminal', ['code' => 'TILL-1']));
        $this->assertSame(1, Artisan::call('tindaflow:create-terminal', ['code' => 'TILL-2']));
        $this->assertStringContainsString('licensed for 1 till', Artisan::output());
        $this->assertSame(1, Terminal::where('store_id', $admin->store_id)->count());
    }

    public function test_license_status_reports_the_deployment_code_and_the_limit(): void
    {
        $this->adminWithStore();
        $this->trustNewVendorKey();
        $this->installLicense(3);

        $this->assertSame(0, Artisan::call('tindaflow:license-status'));
        $output = Artisan::output();
        $this->assertStringContainsString(DeploymentId::current(), $output);
        $this->assertStringContainsString('valid, 3 till(s)', $output);
    }

    public function test_issuing_refuses_bad_input_and_keygen_will_not_overwrite_a_key(): void
    {
        $this->trustNewVendorKey();

        $this->assertSame(1, Artisan::call('tindaflow:license-issue', ['--private-key' => $this->privateKeyFile, '--deployment' => 'nope', '--max-terminals' => 2]));
        $this->assertSame(1, Artisan::call('tindaflow:license-issue', ['--private-key' => $this->privateKeyFile, '--deployment' => 'ABCDEF12', '--max-terminals' => 0]));
        $this->assertSame(1, Artisan::call('tindaflow:license-keygen', ['--private-out' => $this->privateKeyFile]), 'would orphan every issued license');
    }
}
