<?php

namespace Tests\Database;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml shiftOpen/shiftCurrentGet. Unblocks a real POS checkout
 * screen -- CheckoutService's own OPEN-shift/fiscal_day precondition
 * had no HTTP path to satisfy before this. Real HTTP tests through the
 * full middleware chain, under tests/Database/ for the same reason as
 * every other module in this project (PostgreSQL-only schema).
 */
class ShiftOpenHttpTest extends PostgresSchemaTestCase
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

    /** @return array{cashier: User, terminalCredential: TestResponse} */
    private function enrolledCashier(?Store $store = null): array
    {
        $store = $store ?? Store::factory()->create();
        $terminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $cashier = User::factory()->create(['store_id' => $store->id]);

        $adminLogin = $this->login($admin);
        $tokenResponse = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $terminal->id]);
        $terminalCredential = $this->forwardSessionCookie($adminLogin)
            ->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        return ['cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential];
    }

    public function test_opening_a_shift_also_opens_a_new_fiscal_day(): void
    {
        ['cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier();
        $login = $this->login($cashier);

        $this->assertSame(0, FiscalDay::count());

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response->assertStatus(201);
        $response->assertJson([
            'shift' => ['terminal_id' => $terminal->id, 'cashier_id' => $cashier->id, 'status' => 'OPEN', 'opening_cash' => '500.00'],
            'fiscal_day' => ['terminal_id' => $terminal->id, 'status' => 'OPEN'],
            'fiscal_day_was_opened' => true,
        ]);
        $this->assertSame(1, FiscalDay::count());
        $this->assertSame(1, Shift::count());
    }

    public function test_opening_a_second_shift_the_same_day_reuses_the_existing_fiscal_day(): void
    {
        ['cashier' => $firstCashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier();
        $firstLogin = $this->login($firstCashier);
        $firstOpen = $this->forwardSessionCookie($firstLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);
        $firstOpen->assertStatus(201);

        // Close the first shift directly (no shiftClose endpoint yet --
        // out of scope for this pass) so a second cashier can open one
        // on the same terminal without tripping invariant #33.
        Shift::where('id', $firstOpen->json('shift.id'))->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $secondCashier = User::factory()->create(['store_id' => $terminal->store_id]);
        $secondLogin = $this->login($secondCashier);

        $secondOpen = $this->forwardSessionCookie($secondLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '300.00']);

        $secondOpen->assertStatus(201);
        $secondOpen->assertJson(['fiscal_day_was_opened' => false]);
        $this->assertSame($firstOpen->json('fiscal_day.id'), $secondOpen->json('fiscal_day.id'));
        $this->assertSame(1, FiscalDay::count(), 'the same fiscal_day must be reused, not duplicated');
    }

    public function test_a_terminal_that_already_has_an_open_shift_is_rejected(): void
    {
        ['cashier' => $firstCashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier();
        $firstLogin = $this->login($firstCashier);
        $this->forwardSessionCookie($firstLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'])
            ->assertStatus(201);

        $secondCashier = User::factory()->create(['store_id' => $terminal->store_id]);
        $secondLogin = $this->login($secondCashier);

        $response = $this->forwardSessionCookie($secondLogin)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'SHIFT_ALREADY_OPEN']]);
        $this->assertSame(1, Shift::count(), 'the rejected attempt must not create a second shift row');
    }

    public function test_a_cashier_who_already_has_an_open_shift_on_another_terminal_is_rejected(): void
    {
        $store = Store::factory()->create();
        ['cashier' => $cashier, 'terminalCredential' => $firstTerminalCredential] = $this->enrolledCashier($store);
        $login = $this->login($cashier);
        $this->forwardSessionCookie($login)->withTerminalCredential($firstTerminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'])
            ->assertStatus(201);

        // Same cashier, a second (already-enrolled) terminal in the same store.
        $secondAdmin = User::factory()->admin()->create(['store_id' => $store->id]);
        $secondTerminal = Terminal::factory()->unenrolled()->create(['store_id' => $store->id]);
        $secondAdminLogin = $this->login($secondAdmin);
        $tokenResponse = $this->forwardSessionCookie($secondAdminLogin)
            ->postJson('/api/v1/terminal-enrollment-tokens', ['terminal_id' => $secondTerminal->id]);
        $secondTerminalCredential = $this->forwardSessionCookie($secondAdminLogin)
            ->postJson('/api/v1/terminal/enroll', ['token' => $tokenResponse->json('token')]);

        // A fresh login is captured here rather than reusing $login --
        // the intervening bare login($secondAdmin) call above ambiently
        // inherited whatever session cookie was still attached to $this
        // and, via session()->regenerate(), can silently repoint that
        // exact session ID at $secondAdmin instead of $cashier (the same
        // hazard documented in module-a-auth-terminal-initialization.md
        // SS20 for A4's own tests).
        $freshCashierLogin = $this->login($cashier);
        $response = $this->forwardSessionCookie($freshCashierLogin)->withTerminalCredential($secondTerminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'SHIFT_ALREADY_OPEN']]);
    }

    public function test_a_retried_shift_open_with_the_same_idempotency_key_replays_the_same_shift(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier();
        $login = $this->login($cashier);
        $idempotencyKey = (string) Str::uuid();

        $first = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);
        $second = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('shift.id'), $second->json('shift.id'));
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, FiscalDay::count());
    }

    public function test_missing_idempotency_key_header_is_rejected(): void
    {
        ['terminalCredential' => $terminalCredential, 'cashier' => $cashier] = $this->enrolledCashier();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response->assertStatus(400);
        $response->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
    }

    public function test_a_negative_opening_cash_is_rejected_structurally(): void
    {
        ['terminalCredential' => $terminalCredential, 'cashier' => $cashier] = $this->enrolledCashier();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '-50.00']);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_checkout_without_a_terminal_credential_is_rejected(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    public function test_shift_current_returns_the_open_shift(): void
    {
        ['terminal' => $terminal, 'terminalCredential' => $terminalCredential, 'cashier' => $cashier] = $this->enrolledCashier();
        $login = $this->login($cashier);
        $opened = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->getJson('/api/v1/shifts/current');

        $response->assertOk();
        $response->assertJson(['id' => $opened->json('shift.id'), 'terminal_id' => $terminal->id]);
    }

    public function test_shift_current_with_no_open_shift_returns_no_current_shift(): void
    {
        ['terminalCredential' => $terminalCredential, 'cashier' => $cashier] = $this->enrolledCashier();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->getJson('/api/v1/shifts/current');

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'NO_CURRENT_SHIFT']]);
    }

    public function test_shift_open_ignores_a_spoofed_terminal_or_cashier_id_in_the_body(): void
    {
        ['terminal' => $terminal, 'terminalCredential' => $terminalCredential, 'cashier' => $cashier] = $this->enrolledCashier();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', [
                'opening_cash' => '500.00',
                'terminal_id' => (string) Str::uuid(),
                'cashier_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(201);
        $response->assertJson(['shift' => ['terminal_id' => $terminal->id, 'cashier_id' => $cashier->id]]);
    }
}
