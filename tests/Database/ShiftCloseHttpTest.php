<?php

namespace Tests\Database;

use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use App\Models\XReading;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml shiftClose. Closes the Shift lifecycle shiftOpen/
 * shiftCurrentGet started -- computes expected_cash/variance/every
 * total server-side (invariant #37/#38) and atomically generates the
 * closing X-Reading (invariant #43).
 */
class ShiftCloseHttpTest extends PostgresSchemaTestCase
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

    /** @return array{cashier: User, terminal: Terminal, terminalCredential: TestResponse, shift: array} */
    private function openShift(?Store $store = null): array
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

        $login = $this->login($cashier);
        $opened = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '1000.00']);

        return ['cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential, 'shift' => $opened->json('shift')];
    }

    public function test_closing_a_shift_with_no_activity_returns_opening_cash_as_expected(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00']);

        $response->assertOk();
        $response->assertJson([
            'shift' => ['status' => 'CLOSED', 'expected_cash' => '1000.00', 'declared_cash' => '1000.00', 'variance' => '0.00'],
            'x_reading' => ['is_closing_reading' => true, 'shift_id' => $shift['id']],
        ]);
        $this->assertSame(1, XReading::where('shift_id', $shift['id'])->count());
    }

    public function test_closing_computes_expected_cash_from_sales_and_cash_movements(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $sale = Sale::factory()->create([
            'store_id' => Shift::find($shift['id'])->fiscalDay->store_id,
            'terminal_id' => $shift['terminal_id'],
            'fiscal_day_id' => $shift['fiscal_day_id'],
            'shift_id' => $shift['id'],
            'cashier_id' => $cashier->id,
            'grand_total' => '150.00',
        ]);
        Payment::factory()->create(['sale_id' => $sale->id, 'method' => 'CASH', 'amount' => '150.00']);

        $gcashSale = Sale::factory()->create([
            'store_id' => Shift::find($shift['id'])->fiscalDay->store_id,
            'terminal_id' => $shift['terminal_id'],
            'fiscal_day_id' => $shift['fiscal_day_id'],
            'shift_id' => $shift['id'],
            'cashier_id' => $cashier->id,
            'grand_total' => '80.00',
        ]);
        Payment::factory()->gcash()->create(['sale_id' => $gcashSale->id, 'amount' => '80.00']);

        CashMovement::create(['shift_id' => $shift['id'], 'type' => 'CASH_OUT', 'amount' => '50.00', 'reason' => 'Petty cash']);

        // 1000.00 opening + 150.00 cash sales - 50.00 cash-out = 1100.00
        $login = $this->login($cashier);
        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1100.00']);

        $response->assertOk();
        $response->assertJson([
            'shift' => [
                'expected_cash' => '1100.00', 'declared_cash' => '1100.00', 'variance' => '0.00',
                'cash_sales' => '150.00', 'non_cash_sales' => '80.00', 'cash_out_total' => '50.00',
            ],
        ]);
        $this->assertSame(
            ['CASH' => '150.00', 'GCASH' => '80.00'],
            (array) $response->json('x_reading.totals_snapshot.payment_breakdown'),
        );
    }

    public function test_a_shortfall_is_recorded_as_a_negative_variance(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '950.00']);

        $response->assertOk();
        $response->assertJson(['shift' => ['expected_cash' => '1000.00', 'variance' => '-50.00']]);
    }

    public function test_closing_an_already_closed_shift_is_rejected(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00']);

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'SHIFT_ALREADY_CLOSED']]);
    }

    public function test_closing_a_shift_belonging_to_another_terminal_is_not_found(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential] = $this->openShift();
        ['shift' => $otherShift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$otherShift['id']}/close", ['declared_cash' => '1000.00']);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        // openShift() itself makes a request via withHeader('Idempotency-Key', ...),
        // which sets a *default* header applied to every subsequent call in this
        // test method -- explicitly dropped here so this call truly omits it.
        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withoutHeader('Idempotency-Key')
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00']);

        $response->assertStatus(400);
        $response->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REQUIRED']]);
    }

    public function test_a_retried_close_with_the_same_idempotency_key_replays_the_same_result(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $idempotencyKey = (string) Str::uuid();

        $first = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00']);
        $second = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00']);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('x_reading.id'), $second->json('x_reading.id'));
        $this->assertSame(1, XReading::where('shift_id', $shift['id'])->count());
    }
}
