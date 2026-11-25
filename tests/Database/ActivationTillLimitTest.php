<?php

namespace Tests\Database;

use App\Domain\Exceptions\TerminalLimitReachedException;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use App\Services\License\Entitlement;
use App\Services\License\LicenseGate;
use App\Services\License\LicenseState;
use App\Services\Terminal\TerminalProvisioner;
use App\Services\Terminal\TerminalSeats;
use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesLicensedInstallation;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-019 slice 4 (ADR-018, its amendment of ADR-015): in a licensed shop the activation certificate sets how many tills there
 * may be. Only adding a till, or putting a revoked one back, is ever limited; a till already set up is not. A build with no vendor
 * key keeps the ADR-014 rule, which TerminalLicenseTest covers.
 */
class ActivationTillLimitTest extends PostgresSchemaTestCase
{
    use FakesLicensedInstallation;
    use SignsTestCertificates;

    private Store $store;

    private User $admin;

    private ?TestResponse $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()], 'tindaflow.license.path' => '', 'tindaflow.license.token' => '']);
        $this->fakeLicensedInstallation();
        $this->store = Store::findOrFail(self::licensedStoreId());
        $this->admin = User::factory()->admin()->create(['store_id' => $this->store->id]);
    }

    /** @param  array<string, mixed>  $row */
    private function license(int $seats, int $daysToExpiry = 20, string $mode = 'enforce', array $row = []): void
    {
        $now = CarbonImmutable::now();
        $token = self::sign(self::payload([
            'seats' => $seats, 'mode' => $mode, 'iat' => $now->subDays(5)->format('Y-m-d\TH:i:sP'), 'exp' => $now->addDays($daysToExpiry)->format('Y-m-d\TH:i:sP'),
        ]));

        DB::table('license_state')->updateOrInsert(['id' => 1], $row + ['certificate' => $token, 'revoked' => false, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** A valid ADR-014 license for this shop, allowing `$tills` tills. */
    private function oldTillLicense(int $tills): void
    {
        $pair = sodium_crypto_sign_keypair();
        $payload = (string) json_encode(['deployment' => DeploymentId::current(), 'max_terminals' => $tills, 'expires_at' => null]);
        config([
            'tindaflow.license.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'tindaflow.license.token' => Entitlement::encode($payload).'.'.Entitlement::encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair))),
        ]);
    }

    private function asAdmin(): static
    {
        $this->session ??= $this->postJson('/api/v1/auth/login', ['email' => $this->admin->email, 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $cookie = collect($this->session->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), (string) $cookie?->getValue())->withCredentials();
    }

    private function addTill(string $code): TestResponse
    {
        return $this->asAdmin()->postJson('/api/v1/terminals', ['terminal_code' => $code]);
    }

    /** @return array{max_terminals: int, in_use: int}|null */
    private function shownLimit(): ?array
    {
        return $this->asAdmin()->getJson('/api/v1/terminals')->assertOk()->json('meta.license');
    }

    public function test_the_certificate_allows_tills_up_to_its_seats_and_no_further(): void
    {
        $this->license(2);

        $this->addTill('TILL-1')->assertStatus(201);
        $this->addTill('TILL-2')->assertStatus(201);
        $refused = $this->addTill('TILL-3');

        $refused->assertStatus(409)->assertJson(['error' => ['code' => 'TERMINAL_LIMIT_REACHED', 'details' => ['max_terminals' => 2, 'in_use' => 2]]]);
        $this->assertStringContainsString('licensed for 2 tills', $refused->json('error.message'));
        $this->assertSame(['max_terminals' => 2, 'in_use' => 2], $this->shownLimit());
    }

    public function test_in_its_last_week_the_certificate_still_allows_its_seats(): void
    {
        $this->license(1, daysToExpiry: 3);

        $this->assertSame(LicenseState::Warning, app(LicenseGate::class)->check()->verdict->state);
        $this->addTill('TILL-1')->assertStatus(201);
    }

    public function test_an_expired_locked_or_not_activated_shop_adds_no_till_and_says_why(): void
    {
        $states = [
            'NOT_ACTIVATED' => fn () => DB::table('license_state')->delete(),
            'EXPIRED' => fn () => $this->license(5, daysToExpiry: -1),
            'LOCKED' => fn () => $this->license(5, row: ['revoked' => true]),
        ];
        foreach ($states as $state => $arrange) {
            $arrange();

            $refused = $this->addTill('TILL-1')->assertStatus(409)->assertJsonPath('error.code', 'TERMINAL_LIMIT_REACHED');

            $this->assertStringContainsString("while the license is {$state}", $refused->json('error.message'));
            $this->assertSame(['max_terminals' => 0, 'in_use' => 0], $this->shownLimit());
        }
        $this->assertSame(0, Terminal::count());
    }

    public function test_tills_already_set_up_are_not_blocked_when_the_limit_falls_below_them_or_the_license_lapses(): void
    {
        $this->license(2);
        $first = $this->addTill('TILL-1')->assertStatus(201)->json('id');
        $this->addTill('TILL-2')->assertStatus(201);

        foreach ([fn () => $this->license(1), fn () => $this->license(2, daysToExpiry: -1)] as $arrange) {
            $arrange();

            $this->asAdmin()->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $first])->assertStatus(201);
            $this->addTill('TILL-3')->assertStatus(409);
            $this->assertSame(2, app(TerminalSeats::class)->inUse());
        }
    }

    public function test_a_revoked_till_comes_back_only_while_there_is_room_in_the_certificate(): void
    {
        $this->license(1);
        $first = $this->addTill('TILL-1')->json('id');
        $this->asAdmin()->postJson("/api/v1/terminals/{$first}/revoke")->assertOk();
        $this->addTill('TILL-2')->assertStatus(201);

        $this->asAdmin()->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $first])->assertStatus(409)->assertJsonPath('error.code', 'TERMINAL_LIMIT_REACHED');

        $this->license(2);
        $this->asAdmin()->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $first])->assertStatus(201);
    }

    public function test_the_console_command_is_limited_too(): void
    {
        $this->license(1);
        $this->addTill('TILL-1')->assertStatus(201);

        $this->assertSame(1, Artisan::call('tindaflow:create-terminal', ['code' => 'TILL-2']));
        $this->assertStringContainsString('licensed for 1 till', Artisan::output());
    }

    public function test_report_mode_refuses_nothing_and_logs_what_it_would_have_refused(): void
    {
        $this->license(1, mode: 'report');
        Log::spy();

        $this->addTill('TILL-1')->assertStatus(201);
        $this->addTill('TILL-2')->assertStatus(201);

        $this->assertNull($this->shownLimit());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'license.would_refuse'
            && $context['gate'] === 'tillAdd' && $context['max_terminals'] === 1 && $context['in_use'] === 1)->once();
    }

    public function test_the_certificate_replaces_an_old_till_license_in_a_licensed_shop(): void
    {
        $this->oldTillLicense(9);
        $this->license(1);

        $this->addTill('TILL-1')->assertStatus(201);

        $this->addTill('TILL-2')->assertStatus(409);
        Artisan::call('tindaflow:license-status');
        $this->assertStringContainsString('Till limit: 1, from the activation license.', Artisan::output());
    }

    public function test_before_activation_a_shop_with_an_old_till_license_keeps_its_old_limit(): void
    {
        $this->oldTillLicense(1);

        $this->assertSame(LicenseState::Legacy, app(LicenseGate::class)->check()->verdict->state);
        $this->addTill('TILL-1')->assertStatus(201);
        $this->addTill('TILL-2')->assertStatus(409)->assertJsonPath('error.details.max_terminals', 1);
    }

    public function test_an_old_till_license_left_behind_does_not_unlicense_a_shop_whose_certificate_is_no_longer_accepted(): void
    {
        $this->oldTillLicense(9);
        $this->license(1);
        // A certificate for another computer, as after copying the license state: it does not count.
        $this->fakeIdentityFile('11111111-2222-4333-8444-555555555555');

        $verdict = app(LicenseGate::class)->check()->verdict;

        $this->assertSame(LicenseState::NotActivated, $verdict->state);
        $this->assertNotNull($verdict->shiftOpenRefusal());
        $this->addTill('TILL-1')->assertStatus(409);
    }

    public function test_the_till_an_activation_creates_is_checked_against_the_bundles_own_seats(): void
    {
        $provisioner = app(TerminalProvisioner::class);

        // Nothing is stored yet, so the gate would allow none.
        $provisioner->create($this->store, 'TILL-1', $this->admin, 'activation', activationSeats: 1);

        $this->expectException(TerminalLimitReachedException::class);
        $provisioner->create($this->store, 'TILL-2', $this->admin, 'activation', activationSeats: 1);
    }
}
