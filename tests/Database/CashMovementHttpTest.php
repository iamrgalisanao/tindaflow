<?php

namespace Tests\Database;

use App\Models\CashMovement;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** openapi.yaml shiftCashMovementCreate. */
class CashMovementHttpTest extends PostgresSchemaTestCase
{
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

    /** @return array{store: Store, cashier: User, terminal: Terminal, terminalCredential: TestResponse, shift: array} */
    private function openShift(): array
    {
        $store = Store::factory()->create();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);

        $adminLogin = $this->login($admin);
        $tokenResponse = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $terminalCredential = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        $login = $this->login($cashier);
        $opened = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '1000.00']);

        return ['store' => $store, 'cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential, 'shift' => $opened->json('shift')];
    }

    public function test_a_cashier_can_record_a_below_threshold_cash_out(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_OUT', 'amount' => '100.00', 'reason' => 'Supplies']);

        $response->assertStatus(201);
        $response->assertJson(['type' => 'CASH_OUT', 'amount' => '100.00', 'reason' => 'Supplies', 'authorized_by' => $cashier->id]);
    }

    public function test_a_cashier_cannot_record_an_above_threshold_cash_out(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_OUT', 'amount' => '5000.00', 'reason' => 'Big withdrawal']);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->assertSame(0, CashMovement::count());
    }

    public function test_a_manager_can_record_an_above_threshold_cash_out(): void
    {
        ['store' => $store, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $manager = User::factory()->manager()->create(['store_id' => $store->id]);

        $response = $this->forwardSessionCookie($this->login($manager))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_OUT', 'amount' => '5000.00', 'reason' => 'Bank deposit']);

        $response->assertStatus(201);
    }

    public function test_cash_in_below_threshold_needs_no_capability(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_IN', 'amount' => '5000.00', 'reason' => 'Float top-up']);

        $response->assertStatus(201);
    }

    public function test_a_movement_against_a_closed_shift_is_rejected(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_IN', 'amount' => '100.00', 'reason' => 'Late float']);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'SHIFT_NOT_OPEN']]);
    }

    public function test_a_zero_amount_is_rejected_structurally(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_IN', 'amount' => '0.00', 'reason' => 'Nothing']);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_a_blank_reason_is_rejected_structurally(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/cash-movements", ['type' => 'CASH_IN', 'amount' => '10.00', 'reason' => '']);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }
}
