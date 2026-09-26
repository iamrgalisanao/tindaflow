<?php

namespace Tests\Database;

use App\Models\Sale;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use App\Models\ZReading;
use App\Services\FiscalDay\FiscalDayReadingAggregator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** openapi.yaml fiscalDayClose/fiscalDayZReadingGet -- invariant #36/#42. */
class FiscalDayCloseHttpTest extends PostgresSchemaTestCase
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

    /** @return array{store: Store, admin: User, cashier: User, terminalCredential: TestResponse, shift: array} */
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

        return ['store' => $store, 'admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $opened->json('shift')];
    }

    public function test_closing_the_fiscal_day_generates_a_z_reading_with_gross_sales(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $sale = Sale::factory()->create([
            'store_id' => Shift::find($shift['id'])->fiscalDay->store_id,
            'terminal_id' => $shift['terminal_id'],
            'fiscal_day_id' => $shift['fiscal_day_id'],
            'shift_id' => $shift['id'],
            'cashier_id' => $cashier->id,
            'grand_total' => '200.00',
        ]);

        // Close the shift first -- fiscalDayClose requires every
        // referencing shift already CLOSED (invariant #36).
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");

        $response->assertOk();
        $response->assertJson([
            'fiscal_day' => ['status' => 'CLOSED'],
            'z_reading' => ['fiscal_day_id' => $shift['fiscal_day_id']],
        ]);
        $this->assertSame(1, $response->json('z_reading.totals_snapshot.z_counter'));
        $this->assertSame('200.00', $response->json('z_reading.totals_snapshot.gross_sales'));
        $this->assertSame('0.00', $response->json('z_reading.totals_snapshot.accumulated_grand_total_sales_before'));
        $this->assertSame('200.00', $response->json('z_reading.totals_snapshot.accumulated_grand_total_sales_after'));
    }

    public function test_closing_with_an_open_shift_is_rejected(): void
    {
        ['admin' => $admin, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'FISCAL_DAY_HAS_OPEN_SHIFT']]);
        $this->assertSame(0, ZReading::count());
    }

    public function test_closing_an_already_closed_fiscal_day_is_rejected(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();
        $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close")
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");

        $response->assertStatus(409);
        $response->assertJson(['error' => ['code' => 'FISCAL_DAY_CLOSED']]);
    }

    public function test_a_cashier_cannot_close_the_fiscal_day(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    public function test_z_reading_get_is_not_found_before_close(): void
    {
        ['admin' => $admin, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->getJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/z-reading");

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'FISCAL_DAY_NOT_FOUND']]);
    }

    public function test_z_reading_get_returns_the_generated_reading_after_close(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();
        $closed = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");
        $closed->assertOk();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->getJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/z-reading");

        $response->assertOk();
        $this->assertSame($closed->json('z_reading.id'), $response->json('id'));
    }

    public function test_z_counter_increments_across_successive_fiscal_days_on_the_same_terminal(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();
        $firstClose = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");
        $firstClose->assertOk();
        $this->assertSame(1, $firstClose->json('z_reading.totals_snapshot.z_counter'));

        $secondCashier = User::factory()->create(['store_id' => $admin->store_id]);
        $secondOpen = $this->forwardSessionCookie($this->login($secondCashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);
        $secondOpen->assertStatus(201);
        $secondShift = $secondOpen->json('shift');
        $this->assertNotSame($shift['fiscal_day_id'], $secondShift['fiscal_day_id'], 'a new fiscal day must open since the first one is already closed');

        $this->forwardSessionCookie($this->login($secondCashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$secondShift['id']}/close", ['declared_cash' => '500.00'])
            ->assertOk();

        $response = $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$secondShift['fiscal_day_id']}/close");

        $response->assertOk();
        $this->assertSame(2, $response->json('z_reading.totals_snapshot.z_counter'));
    }

    /** Rings one sale in a shift's fiscal day, closes the shift, then closes the day as the admin; returns the fiscal-day close response. */
    private function ringAndCloseTheDay(User $admin, User $cashier, TestResponse $terminalCredential, array $shift, array $sales): TestResponse
    {
        foreach ($sales as [$amount, $status]) {
            Sale::factory()->create([
                'store_id' => $admin->store_id,
                'terminal_id' => $shift['terminal_id'],
                'fiscal_day_id' => $shift['fiscal_day_id'],
                'shift_id' => $shift['id'],
                'cashier_id' => $cashier->id,
                'grand_total' => $amount,
                'status' => $status,
            ]);
        }

        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/shifts/{$shift['id']}/close", ['declared_cash' => '1000.00'])
            ->assertOk();

        return $this->forwardSessionCookie($this->login($admin))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/fiscal-days/{$shift['fiscal_day_id']}/close");
    }

    /** Opens the next day's shift on the same terminal (a new fiscal day opens because the previous one is closed). */
    private function openNextShift(User $admin, TestResponse $terminalCredential): array
    {
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        $opened = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00']);
        $opened->assertStatus(201);

        return ['cashier' => $cashier, 'shift' => $opened->json('shift')];
    }

    /**
     * invariants.md #40: readings are reproducible and never inputs. The accumulated grand total is derived from the ledger
     * (the gross of every earlier closed day of the terminal), so a wrong figure stored on an earlier Z-reading must not leak
     * into the next one. Voided sales are not in any day's gross.
     */
    public function test_the_accumulated_total_is_derived_from_the_ledger_and_not_chained_from_the_previous_reading(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $credential, 'shift' => $shift] = $this->openShift();

        $day1 = $this->ringAndCloseTheDay($admin, $cashier, $credential, $shift, [['200.00', 'COMPLETED'], ['999.00', 'VOIDED']]);
        $day1->assertOk();
        $this->assertSame('200.00', $day1->json('z_reading.totals_snapshot.accumulated_grand_total_sales_after'), 'a voided sale is in no day\'s gross');

        // Corrupt what day 1's reading stored. A chained total would carry this into every later reading.
        $reading1 = ZReading::where('fiscal_day_id', $shift['fiscal_day_id'])->firstOrFail();
        $snapshot = $reading1->totals_snapshot;
        $snapshot['accumulated_grand_total_sales_after'] = '999999.00';
        ZReading::where('id', $reading1->id)->update(['totals_snapshot' => json_encode($snapshot)]);

        $next = $this->openNextShift($admin, $credential);
        $day2 = $this->ringAndCloseTheDay($admin, $next['cashier'], $credential, $next['shift'], [['50.00', 'COMPLETED']]);
        $day2->assertOk();
        $this->assertSame(2, $day2->json('z_reading.totals_snapshot.z_counter'));
        $this->assertSame('200.00', $day2->json('z_reading.totals_snapshot.accumulated_grand_total_sales_before'));
        $this->assertSame('250.00', $day2->json('z_reading.totals_snapshot.accumulated_grand_total_sales_after'));

        $third = $this->openNextShift($admin, $credential);
        $day3 = $this->ringAndCloseTheDay($admin, $third['cashier'], $credential, $third['shift'], []);
        $day3->assertOk();
        $this->assertSame(3, $day3->json('z_reading.totals_snapshot.z_counter'));
        $this->assertSame('250.00', $day3->json('z_reading.totals_snapshot.accumulated_grand_total_sales_before'), 'the sum of every earlier closed day');
        $this->assertSame('250.00', $day3->json('z_reading.totals_snapshot.accumulated_grand_total_sales_after'));
    }

    public function test_aggregating_a_closed_day_again_reproduces_its_stored_reading(): void
    {
        ['admin' => $admin, 'cashier' => $cashier, 'terminalCredential' => $credential, 'shift' => $shift] = $this->openShift();
        $this->ringAndCloseTheDay($admin, $cashier, $credential, $shift, [['200.00', 'COMPLETED']])->assertOk();
        $next = $this->openNextShift($admin, $credential);
        $this->ringAndCloseTheDay($admin, $next['cashier'], $credential, $next['shift'], [['75.50', 'COMPLETED']])->assertOk();

        foreach (ZReading::orderBy('z_counter')->get() as $reading) {
            $again = json_decode(json_encode(app(FiscalDayReadingAggregator::class)->aggregate($reading->fiscalDay)), true);

            $this->assertEquals($reading->totals_snapshot, $again, "Z-reading #{$reading->z_counter} must be reproducible from the ledger");
        }
    }
}
