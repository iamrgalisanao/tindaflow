<?php

namespace Tests\Database;

use App\Models\Shift;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use App\Models\XReading;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** openapi.yaml shiftXReadingCreate/shiftXReadingList -- invariant #43. */
class ShiftXReadingHttpTest extends PostgresSchemaTestCase
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

    /** @return array{cashier: User, terminalCredential: TestResponse, shift: array} */
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

        return ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $opened->json('shift')];
    }

    public function test_an_interim_reading_never_changes_shift_status_or_variance(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->postJson("/api/v1/shifts/{$shift['id']}/x-readings");

        $response->assertStatus(201);
        $response->assertJson(['is_closing_reading' => false, 'shift_id' => $shift['id']]);
        $this->assertNull($response->json('totals_snapshot.variance'));
        $this->assertSame('OPEN', Shift::find($shift['id'])->status);
    }

    public function test_multiple_interim_readings_are_allowed(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();

        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->postJson("/api/v1/shifts/{$shift['id']}/x-readings")->assertStatus(201);
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->postJson("/api/v1/shifts/{$shift['id']}/x-readings")->assertStatus(201);

        $this->assertSame(2, XReading::where('shift_id', $shift['id'])->count());
    }

    public function test_list_returns_readings_for_this_shift_only(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential, 'shift' => $shift] = $this->openShift();
        $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->postJson("/api/v1/shifts/{$shift['id']}/x-readings")->assertStatus(201);

        ['cashier' => $otherCashier, 'terminalCredential' => $otherCredential, 'shift' => $otherShift] = $this->openShift();
        $this->forwardSessionCookie($this->login($otherCashier))->withTerminalCredential($otherCredential)
            ->postJson("/api/v1/shifts/{$otherShift['id']}/x-readings")->assertStatus(201);

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->getJson("/api/v1/shifts/{$shift['id']}/x-readings");

        $response->assertOk();
        $this->assertCount(1, $response->json());
        $this->assertSame($shift['id'], $response->json('0.shift_id'));
    }

    public function test_generating_a_reading_for_a_shift_on_another_terminal_is_not_found(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential] = $this->openShift();
        ['shift' => $otherShift] = $this->openShift();

        $response = $this->forwardSessionCookie($this->login($cashier))->withTerminalCredential($terminalCredential)
            ->postJson("/api/v1/shifts/{$otherShift['id']}/x-readings");

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'SHIFT_NOT_FOUND']]);
    }
}
