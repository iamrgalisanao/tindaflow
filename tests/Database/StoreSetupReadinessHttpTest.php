<?php

namespace Tests\Database;

use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\Store;
use App\Models\TaxRegistration;
use App\Models\Terminal;
use App\Models\TerminalFiscalInstallation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * New forward-committed operation (store-setup pass, 2026-09-18) --
 * read-only, non-authoritative aggregate across the four checkout-time
 * resolver prerequisites, so the POS screen can show a blocked state
 * instead of letting a cashier attempt a checkout guaranteed to fail
 * with a setup-defect 500 (the exact bug hit during Stage 7 pass 2's
 * browser verification).
 */
class StoreSetupReadinessHttpTest extends PostgresSchemaTestCase
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

    /** @return array{cashier: User, terminal: Terminal, terminalCredential: TestResponse} */
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

    public function test_a_fully_configured_store_is_ready(): void
    {
        $store = Store::factory()->create();
        ['cashier' => $cashier, 'terminal' => $terminal, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier($store);

        $installation = FiscalInstallation::factory()->create(['store_id' => $store->id]);
        TerminalFiscalInstallation::factory()->create(['store_id' => $store->id, 'terminal_id' => $terminal->id, 'fiscal_installation_id' => $installation->id]);
        InvoiceSeries::factory()->create(['store_id' => $store->id, 'fiscal_installation_id' => $installation->id]);
        InventoryLocation::factory()->create(['store_id' => $store->id]);
        TaxRegistration::factory()->create(['store_id' => $store->id]);

        $login = $this->login($cashier);
        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->getJson('/api/v1/store-setup/readiness');

        $response->assertOk();
        $response->assertJson(['ready' => true, 'checks' => [
            'fiscal_installation' => true,
            'invoice_series' => true,
            'inventory_location' => true,
            'tax_registration' => true,
        ]]);
    }

    public function test_a_fresh_store_with_nothing_configured_is_not_ready(): void
    {
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier();

        $login = $this->login($cashier);
        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->getJson('/api/v1/store-setup/readiness');

        $response->assertOk();
        $response->assertJson(['ready' => false, 'checks' => [
            'fiscal_installation' => false,
            'invoice_series' => false,
            'inventory_location' => false,
            'tax_registration' => false,
        ]]);
    }

    public function test_a_terminal_with_no_fiscal_installation_mapping_is_not_ready_even_with_everything_else_configured(): void
    {
        $store = Store::factory()->create();
        ['cashier' => $cashier, 'terminalCredential' => $terminalCredential] = $this->enrolledCashier($store);

        // Everything else is configured, but no terminal_fiscal_installations
        // row exists for THIS terminal.
        InventoryLocation::factory()->create(['store_id' => $store->id]);
        TaxRegistration::factory()->create(['store_id' => $store->id]);
        FiscalInstallation::factory()->create(['store_id' => $store->id]);

        $login = $this->login($cashier);
        $response = $this->forwardSessionCookie($login)->withTerminalCredential($terminalCredential)
            ->getJson('/api/v1/store-setup/readiness');

        $response->assertOk();
        $response->assertJson(['ready' => false, 'checks' => ['fiscal_installation' => false, 'invoice_series' => false]]);
    }

    public function test_without_a_terminal_credential_is_rejected(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/store-setup/readiness');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }
}
