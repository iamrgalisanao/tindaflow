<?php

namespace Tests\Database;

use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-018 decision 2 over HTTP: the license gates shiftOpen, caps an open shift's sales after 18 hours once the license has
 * lapsed, never gates shiftClose, never refuses a replay of a completed request, and does nothing at all in a build that
 * carries no vendor key. The certificates are signed with the TEST-ONLY key of SignsTestCertificates.
 */
class LicenseGateHttpTest extends PostgresSchemaTestCase
{
    use SignsTestCertificates;

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

    private function withTerminalCredential(TestResponse $enrollResponse): static
    {
        $cookie = collect($enrollResponse->headers->getCookies())->first(fn ($c) => $c->getName() === 'tindaflow_terminal');

        return $this->withUnencryptedCookie('tindaflow_terminal', $cookie?->getValue() ?? '')->withCredentials();
    }

    /** @return array{cashier: User, terminal: Terminal, terminalCredential: TestResponse} */
    private function enrolledCashier(): array
    {
        $store = Store::factory()->create();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);

        $adminLogin = $this->login($admin);
        $tokenResponse = $this->forwardSessionCookie($adminLogin)->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $terminalCredential = $this->forwardSessionCookie($adminLogin)->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        return ['cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential];
    }

    private function trustTestVendorKey(): void
    {
        config(['tindaflow.license.vendor_keys' => ['test-2026-01' => self::testPublicKeyBase64()]]);
    }

    /** Stores a certificate that expires `$daysToExpiry` from now (negative = already expired). */
    private function storeCertificate(int $daysToExpiry, string $mode = 'enforce', array $row = []): void
    {
        $now = CarbonImmutable::now();
        $token = self::sign(self::payload([
            'mode' => $mode,
            'iat' => $now->subDays(10)->format('Y-m-d\TH:i:sP'),
            'exp' => $now->addDays($daysToExpiry)->format('Y-m-d\TH:i:sP'),
        ]));

        DB::table('license_state')->updateOrInsert(['id' => 1], $row + ['certificate' => $token, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function openShift(array $context, ?string $key = null): TestResponse
    {
        return $this->forwardSessionCookie($this->login($context['cashier']))->withTerminalCredential($context['terminalCredential'])
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);
    }

    public function test_a_build_with_no_vendor_key_opens_shifts_and_touches_no_license_table(): void
    {
        $context = $this->enrolledCashier();

        $this->openShift($context)->assertStatus(201);

        $this->assertSame(0, DB::table('license_state')->count());
    }

    public function test_with_a_key_and_no_certificate_a_shift_is_refused_as_not_activated(): void
    {
        $this->trustTestVendorKey();
        $context = $this->enrolledCashier();

        $response = $this->openShift($context);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'LICENSE_NOT_ACTIVE');
        $response->assertJsonPath('error.details.reason', 'NOT_ACTIVATED');
        $response->assertJsonPath('error.details.state', 'NOT_ACTIVATED');
        $this->assertSame(0, Shift::count());
    }

    public function test_an_active_certificate_opens_a_shift_and_moves_the_trusted_time_mark(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();

        $this->openShift($context)->assertStatus(201);

        $this->assertNotNull(DB::table('license_state')->where('id', 1)->value('trusted_time_hwm'));
    }

    public function test_a_certificate_in_its_last_week_still_opens_a_shift(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(3);

        $this->openShift($this->enrolledCashier())->assertStatus(201);
    }

    public function test_an_expired_certificate_refuses_a_new_shift(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(-1);

        $response = $this->openShift($this->enrolledCashier());

        $response->assertStatus(409);
        $response->assertJsonPath('error.details.reason', 'EXPIRED');
        $this->assertSame(0, Shift::count());
    }

    public function test_a_revoked_installation_is_refused_even_with_an_in_date_certificate(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20, row: ['revoked' => true]);

        $this->openShift($this->enrolledCashier())->assertStatus(409)->assertJsonPath('error.details.reason', 'REVOKED');
    }

    public function test_a_clock_set_back_behind_the_trusted_time_mark_is_refused(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20, row: ['trusted_time_hwm' => CarbonImmutable::now()->addDays(2)]);

        $this->openShift($this->enrolledCashier())->assertStatus(409)->assertJsonPath('error.details.reason', 'CLOCK_ROLLBACK');
    }

    public function test_a_certificate_in_report_mode_logs_what_it_would_refuse_and_refuses_nothing(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(-1, mode: 'report');
        Log::spy();

        $this->openShift($this->enrolledCashier())->assertStatus(201);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'license.would_refuse' && $context['reason'] === 'EXPIRED' && $context['gate'] === 'open')->once();
    }

    public function test_a_certificate_signed_by_an_unknown_key_is_the_same_as_none(): void
    {
        config(['tindaflow.license.vendor_keys' => ['some-other-key' => self::testPublicKeyBase64()]]);
        $this->storeCertificate(20);

        $this->openShift($this->enrolledCashier())->assertStatus(409)->assertJsonPath('error.details.reason', 'NOT_ACTIVATED');
    }

    public function test_a_replay_of_a_completed_shift_open_returns_the_original_even_after_the_license_lapses(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();
        $key = (string) Str::uuid();

        $first = $this->openShift($context, $key);
        $first->assertStatus(201);

        $this->storeCertificate(-5);

        $replay = $this->openShift($context, $key);
        $replay->assertStatus(201);
        $this->assertSame($first->json('shift.id'), $replay->json('shift.id'));
        $this->assertSame(1, Shift::count());

        $this->openShift($context)->assertStatus(409)->assertJsonPath('error.code', 'LICENSE_NOT_ACTIVE');
    }

    public function test_an_expired_license_refuses_a_sale_only_after_the_shift_is_18_hours_old(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();
        $this->openShift($context)->assertStatus(201);
        $this->storeCertificate(-1);

        $sale = fn () => $this->forwardSessionCookie($this->login($context['cashier']))->withTerminalCredential($context['terminalCredential'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', []);

        Shift::query()->update(['opened_at' => CarbonImmutable::now()->subHours(17)]);
        $this->assertNotSame('LICENSE_NOT_ACTIVE', $sale()->json('error.code'), 'a shift under 18 hours old keeps selling');

        Shift::query()->update(['opened_at' => CarbonImmutable::now()->subHours(19)]);
        $refused = $sale();
        $refused->assertStatus(409);
        $refused->assertJsonPath('error.code', 'LICENSE_NOT_ACTIVE');
        $refused->assertJsonPath('error.details.reason', 'SHIFT_TOO_LONG');
    }

    public function test_a_replay_of_a_completed_sale_is_never_refused_by_the_cap(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();
        $this->openShift($context)->assertStatus(201);
        $this->storeCertificate(-1);
        Shift::query()->update(['opened_at' => CarbonImmutable::now()->subHours(19)]);
        $key = (string) Str::uuid();
        DB::table('idempotency_records')->insert([
            'terminal_id' => $context['terminal']->id, 'idempotency_key' => $key, 'operation_type' => 'CHECKOUT',
            'request_hash' => str_repeat('a', 64), 'status' => 'COMPLETED', 'result_type' => 'sale',
            'result_resource_id' => (string) Str::uuid(), 'completed_at' => now(),
        ]);

        $response = $this->forwardSessionCookie($this->login($context['cashier']))->withTerminalCredential($context['terminalCredential'])
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/sales', []);

        $this->assertNotSame('LICENSE_NOT_ACTIVE', $response->json('error.code'));
    }

    public function test_an_old_shift_is_never_capped_while_the_license_is_good(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();
        $this->openShift($context)->assertStatus(201);
        Shift::query()->update(['opened_at' => CarbonImmutable::now()->subHours(40)]);

        $response = $this->forwardSessionCookie($this->login($context['cashier']))->withTerminalCredential($context['terminalCredential'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', []);

        $this->assertNotSame('LICENSE_NOT_ACTIVE', $response->json('error.code'));
    }

    public function test_closing_a_shift_is_never_gated_by_the_license(): void
    {
        $this->trustTestVendorKey();
        $this->storeCertificate(20);
        $context = $this->enrolledCashier();
        $shiftId = $this->openShift($context)->json('shift.id');
        $this->storeCertificate(-30, row: ['revoked' => true]);

        $response = $this->forwardSessionCookie($this->login($context['cashier']))->withTerminalCredential($context['terminalCredential'])
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '500.00']);

        $this->assertNotSame('LICENSE_NOT_ACTIVE', $response->json('error.code'));
        $response->assertSuccessful();
    }
}
