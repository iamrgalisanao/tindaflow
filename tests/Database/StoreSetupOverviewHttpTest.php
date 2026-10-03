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
use App\Services\StoreSetup\StoreSetupReadinessService;
use Illuminate\Testing\TestResponse;

/**
 * storeSetupOverviewGet (GET /store-setup/overview): the Store Setup overview used to guess readiness in the browser
 * ("any installation has a terminal", "any series is active", "any registration has no end date") while the till asked
 * the strict, per-terminal question, so the overview could say READY about a store whose till was blocked. These tests
 * pin the two to the same answer.
 */
class StoreSetupOverviewHttpTest extends PostgresSchemaTestCase
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

    /** @return array<string, mixed> */
    private function overview(User $admin): array
    {
        return $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/store-setup/overview')->assertOk()->json();
    }

    private function till(Store $store, string $code): Terminal
    {
        return Terminal::factory()->create(['store_id' => $store->id, 'terminal_code' => $code]);
    }

    private function mapTo(Terminal $terminal, FiscalInstallation $installation, array $window = []): void
    {
        TerminalFiscalInstallation::factory()->create([
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
            'fiscal_installation_id' => $installation->id,
        ] + $window);
    }

    private function readyStore(): array
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $till = $this->till($store, 'TILL-1');
        $installation = FiscalInstallation::factory()->create(['store_id' => $store->id]);
        $this->mapTo($till, $installation);
        InvoiceSeries::factory()->create(['store_id' => $store->id, 'fiscal_installation_id' => $installation->id]);
        InventoryLocation::factory()->create(['store_id' => $store->id]);
        TaxRegistration::factory()->create(['store_id' => $store->id]);

        return [$store, $admin, $till, $installation];
    }

    public function test_a_fully_configured_store_is_ready(): void
    {
        [, $admin] = $this->readyStore();

        $body = $this->overview($admin);

        $this->assertTrue($body['ready']);
        $this->assertSame(['fiscal_installation' => true, 'invoice_series' => true, 'inventory_location' => true, 'tax_registration' => true], $body['checks']);
        $this->assertSame([['TILL-1', true]], collect($body['terminals'])->map(fn ($t) => [$t['terminal_code'], $t['ready']])->all());
    }

    public function test_a_store_with_no_till_that_can_sell_is_not_ready(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        InventoryLocation::factory()->create(['store_id' => $store->id]);
        TaxRegistration::factory()->create(['store_id' => $store->id]);

        $body = $this->overview($admin);

        $this->assertFalse($body['ready']);
        $this->assertFalse($body['checks']['fiscal_installation']);
        $this->assertFalse($body['checks']['invoice_series']);
        $this->assertSame([], $body['terminals']);
    }

    public function test_a_series_on_a_different_installation_than_the_tills_is_not_ready_and_names_the_till(): void
    {
        [$store, $admin, $till] = $this->readyStore();
        // The old overview said READY here: an ACTIVE series exists, just not for the installation TILL-1 is under.
        InvoiceSeries::query()->delete();
        $other = FiscalInstallation::factory()->create(['store_id' => $store->id]);
        InvoiceSeries::factory()->create(['store_id' => $store->id, 'fiscal_installation_id' => $other->id]);

        $body = $this->overview($admin);

        $this->assertFalse($body['ready']);
        $this->assertFalse($body['checks']['invoice_series']);
        $this->assertTrue($body['checks']['fiscal_installation']);
        $this->assertFalse($body['terminals'][0]['checks']['invoice_series']);
        $this->assertSame($till->id, $body['terminals'][0]['id']);
    }

    public function test_a_tax_registration_that_has_not_started_yet_is_not_ready(): void
    {
        [$store, $admin] = $this->readyStore();
        // The old overview said READY here: the registration has no end date, but it starts next week.
        TaxRegistration::query()->delete();
        TaxRegistration::factory()->create(['store_id' => $store->id, 'effective_from' => now()->addWeek()->toDateString()]);

        $body = $this->overview($admin);

        $this->assertFalse($body['ready']);
        $this->assertFalse($body['checks']['tax_registration']);
    }

    public function test_a_closed_registration_that_still_covers_today_counts(): void
    {
        [$store, $admin] = $this->readyStore();
        TaxRegistration::query()->delete();
        TaxRegistration::factory()->create(['store_id' => $store->id, 'effective_to' => now()->toDateString()]);

        $this->assertTrue($this->overview($admin)['checks']['tax_registration'], 'the till counts it, so the overview must');
    }

    public function test_a_terminal_whose_installation_assignment_has_ended_is_not_ready(): void
    {
        [$store, $admin, $till] = $this->readyStore();
        TerminalFiscalInstallation::query()->where('terminal_id', $till->id)->update(['effective_to' => now()->subHour()]);

        $body = $this->overview($admin);

        $this->assertFalse($body['checks']['fiscal_installation']);
    }

    public function test_one_unready_till_makes_the_store_not_ready_while_the_other_is(): void
    {
        [$store, $admin, , $installation] = $this->readyStore();
        $this->till($store, 'TILL-2'); // never assigned to an installation

        $body = $this->overview($admin);

        $this->assertFalse($body['ready']);
        $this->assertFalse($body['checks']['fiscal_installation']);
        $byCode = collect($body['terminals'])->keyBy('terminal_code');
        $this->assertTrue($byCode['TILL-1']['ready']);
        $this->assertFalse($byCode['TILL-2']['ready']);
        $this->assertNotNull($installation);
    }

    public function test_revoked_and_decommissioned_tills_are_not_counted(): void
    {
        [$store, $admin] = $this->readyStore();
        Terminal::factory()->create(['store_id' => $store->id, 'terminal_code' => 'OLD-1', 'revoked_at' => now()]);
        Terminal::factory()->decommissioned()->create(['store_id' => $store->id, 'terminal_code' => 'OLD-2']);

        $body = $this->overview($admin);

        $this->assertTrue($body['ready']);
        $this->assertSame(['TILL-1'], collect($body['terminals'])->pluck('terminal_code')->all());
    }

    public function test_it_agrees_with_what_the_till_itself_reports_for_every_terminal(): void
    {
        [$store, $admin] = $this->readyStore();
        $this->till($store, 'TILL-2');
        $service = app(StoreSetupReadinessService::class);

        foreach ($this->overview($admin)['terminals'] as $terminal) {
            $this->assertSame($service->forTerminal($store->id, $terminal['id'])['checks'], $terminal['checks'], $terminal['terminal_code']);
        }
    }

    public function test_another_stores_setup_is_never_counted(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $this->till($store, 'TILL-1');
        $this->readyStore(); // a fully configured store that is not this one

        $body = $this->overview($admin);

        $this->assertFalse($body['ready']);
        $this->assertSame(['TILL-1'], collect($body['terminals'])->pluck('terminal_code')->all());
    }

    public function test_it_needs_a_session_and_the_store_settings_capability(): void
    {
        $this->getJson('/api/v1/store-setup/overview')->assertStatus(401);

        $manager = User::factory()->manager()->create();
        $this->forwardSessionCookie($this->login($manager))->getJson('/api/v1/store-setup/overview')->assertStatus(403);
    }
}
