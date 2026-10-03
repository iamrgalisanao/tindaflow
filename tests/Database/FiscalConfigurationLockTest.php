<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreSetup\FiscalConfigurationLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

/**
 * ADR-014: a store's fiscal configuration is locked for its administrators until the server operator unlocks it from the
 * console, and the shop keeps everything that is not fiscal (business details, stock locations).
 */
class FiscalConfigurationLockTest extends PostgresSchemaTestCase
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

    /** @return array<string, array{string, string}> */
    private function fiscalWrites(): array
    {
        return [
            'fiscal installation' => ['POST', '/api/v1/fiscal-installations'],
            'tax registration' => ['POST', '/api/v1/tax-registrations'],
            'invoice series' => ['POST', '/api/v1/invoice-series'],
        ];
    }

    public function test_a_store_administrator_cannot_change_the_fiscal_configuration_by_default(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);

        $this->assertNotContains('FISCAL_CONFIGURATION_MANAGE', $login->json('capabilities'));
        foreach ($this->fiscalWrites() as $name => [$method, $uri]) {
            $this->forwardSessionCookie($login)->json($method, $uri, [])
                ->assertStatus(403, "{$name} must be locked");
        }
    }

    public function test_the_fiscal_configuration_can_still_be_read_while_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);

        foreach (['/api/v1/fiscal-installations', '/api/v1/tax-registrations', '/api/v1/invoice-series'] as $uri) {
            $this->forwardSessionCookie($login)->getJson($uri)->assertOk();
        }
    }

    public function test_the_shops_own_stock_locations_stay_editable_while_the_fiscal_configuration_is_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);

        $this->forwardSessionCookie($login)->postJson('/api/v1/inventory-locations', ['name' => 'Back room'])->assertStatus(201);
    }

    public function test_an_unlock_gives_the_stores_administrators_the_capability_and_a_lock_takes_it_away(): void
    {
        $admin = User::factory()->admin()->create();
        $lock = app(FiscalConfigurationLock::class);

        $lock->unlock($admin->store_id, 30);
        $login = $this->login($admin);
        $this->assertContains('FISCAL_CONFIGURATION_MANAGE', $login->json('capabilities'));
        $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [])->assertStatus(422);

        $lock->lock($admin->store_id);
        $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [])->assertStatus(403);
        $this->assertNotContains('FISCAL_CONFIGURATION_MANAGE', $this->forwardSessionCookie($login)->getJson('/api/v1/auth/me')->json('capabilities'));
    }

    public function test_the_unlock_ends_by_itself(): void
    {
        $admin = User::factory()->admin()->create();
        app(FiscalConfigurationLock::class)->unlock($admin->store_id, 30);
        $this->assertTrue(app(FiscalConfigurationLock::class)->isUnlocked($admin->store_id));

        $this->travel(31)->minutes();

        $this->assertFalse(app(FiscalConfigurationLock::class)->isUnlocked($admin->store_id));
        $this->forwardSessionCookie($this->login($admin))->postJson('/api/v1/tax-registrations', [])->assertStatus(403);
    }

    public function test_unlocking_one_store_does_not_unlock_another(): void
    {
        $unlocked = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        app(FiscalConfigurationLock::class)->unlock($unlocked->store_id, 30);

        $this->forwardSessionCookie($this->login($other))->postJson('/api/v1/tax-registrations', [])->assertStatus(403);
    }

    public function test_a_manager_or_cashier_never_gets_it_even_while_unlocked(): void
    {
        $manager = User::factory()->manager()->create();
        $cashier = User::factory()->create(['store_id' => $manager->store_id]);
        app(FiscalConfigurationLock::class)->unlock($manager->store_id, 30);

        foreach ([$manager, $cashier] as $user) {
            $login = $this->login($user);
            $this->assertNotContains('FISCAL_CONFIGURATION_MANAGE', $login->json('capabilities'));
            $this->forwardSessionCookie($login)->postJson('/api/v1/tax-registrations', [])->assertStatus(403);
        }
    }

    public function test_unlock_and_lock_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $lock = app(FiscalConfigurationLock::class);

        $lock->unlock($admin->store_id, 30);
        $lock->lock($admin->store_id);
        $lock->lock($admin->store_id); // already locked: nothing more to record

        $this->assertSame(1, AuditEvent::where('event_type', 'FISCAL_CONFIGURATION_UNLOCKED')->where('store_id', $admin->store_id)->count());
        $this->assertSame(1, AuditEvent::where('event_type', 'FISCAL_CONFIGURATION_LOCKED')->where('store_id', $admin->store_id)->count());
    }

    public function test_the_unlock_length_is_capped(): void
    {
        $store = Store::factory()->create();

        $until = app(FiscalConfigurationLock::class)->unlock($store->id, 100000);

        $this->assertLessThanOrEqual(now()->addMinutes(FiscalConfigurationLock::MAX_MINUTES + 1)->getTimestamp(), $until->getTimestamp());
    }

    public function test_the_console_command_unlocks_reports_and_locks(): void
    {
        $store = Store::factory()->create();
        $lock = app(FiscalConfigurationLock::class);

        $this->assertSame(0, Artisan::call('tindaflow:fiscal-setup', ['action' => 'unlock', '--minutes' => 45]));
        $this->assertStringContainsString('unlocked until', Artisan::output());
        $this->assertTrue($lock->isUnlocked($store->id));

        Artisan::call('tindaflow:fiscal-setup', ['action' => 'status']);
        $this->assertStringContainsString('unlocked until', Artisan::output());

        $this->assertSame(0, Artisan::call('tindaflow:fiscal-setup', ['action' => 'lock']));
        $this->assertFalse($lock->isUnlocked($store->id));
        Artisan::call('tindaflow:fiscal-setup', ['action' => 'status']);
        $this->assertStringContainsString('is locked', Artisan::output());
    }

    public function test_the_console_command_refuses_a_bad_action_or_length_and_changes_nothing(): void
    {
        $store = Store::factory()->create();

        $this->assertSame(1, Artisan::call('tindaflow:fiscal-setup', ['action' => 'open']));
        $this->assertSame(1, Artisan::call('tindaflow:fiscal-setup', ['action' => 'unlock', '--minutes' => 0]));
        $this->assertSame(1, Artisan::call('tindaflow:fiscal-setup', ['action' => 'unlock', '--minutes' => 9999]));
        $this->assertFalse(app(FiscalConfigurationLock::class)->isUnlocked($store->id));
    }
}
