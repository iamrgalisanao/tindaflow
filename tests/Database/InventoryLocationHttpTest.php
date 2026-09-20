<?php

namespace Tests\Database;

use App\Models\InventoryLocation;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * New forward-committed operations (store-setup pass, 2026-09-18) -- the
 * table InventoryLocationResolver reads at checkout time to resolve
 * where stock deducts from.
 */
class InventoryLocationHttpTest extends PostgresSchemaTestCase
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

    public function test_a_stores_first_location_is_made_the_default_automatically(): void
    {
        $admin = User::factory()->admin()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/inventory-locations', ['name' => 'Main Store']);

        $response->assertStatus(201);
        $response->assertJson(['name' => 'Main Store', 'is_default' => true]);
    }

    public function test_a_second_location_is_not_default_unless_requested(): void
    {
        $admin = User::factory()->admin()->create();
        InventoryLocation::factory()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/inventory-locations', ['name' => 'Back Room']);

        $response->assertStatus(201);
        $response->assertJson(['is_default' => false]);
    }

    public function test_promoting_a_location_to_default_demotes_the_prior_one(): void
    {
        $admin = User::factory()->admin()->create();
        $original = InventoryLocation::factory()->create(['store_id' => $admin->store_id, 'is_default' => true]);
        $candidate = InventoryLocation::factory()->notDefault()->create(['store_id' => $admin->store_id]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->patchJson("/api/v1/inventory-locations/{$candidate->id}", ['is_default' => true]);

        $response->assertOk();
        $response->assertJson(['is_default' => true]);
        $this->assertFalse($original->refresh()->is_default);
        $this->assertSame(1, InventoryLocation::where('store_id', $admin->store_id)->where('is_default', true)->count());
    }

    public function test_explicitly_unsetting_is_default_is_rejected_structurally(): void
    {
        $admin = User::factory()->admin()->create();
        $location = InventoryLocation::factory()->create(['store_id' => $admin->store_id, 'is_default' => true]);
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->patchJson("/api/v1/inventory-locations/{$location->id}", ['is_default' => false]);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_updating_a_location_from_another_store_is_not_found(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignLocation = InventoryLocation::factory()->create();
        $login = $this->login($admin);

        $response = $this->forwardSessionCookie($login)->patchJson("/api/v1/inventory-locations/{$foreignLocation->id}", ['name' => 'Renamed']);

        $response->assertStatus(404);
        $response->assertJson(['error' => ['code' => 'INVENTORY_LOCATION_NOT_FOUND']]);
    }

    public function test_a_non_admin_cannot_create_a_location(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->postJson('/api/v1/inventory-locations', ['name' => 'Main Store']);

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    // Stage 25: listing is session-only (a location is just a name, and a MANAGER's stock, count and transfer
    // screens need it); creating and editing stay FISCAL_CONFIGURATION_MANAGE. Before this, a manager -- who has
    // STOCK_ADJUST but not FISCAL_CONFIGURATION_MANAGE -- got a 403 here and the Stock page could not show location names.
    public function test_any_signed_in_user_of_the_store_can_list_only_their_stores_locations(): void
    {
        $manager = User::factory()->manager()->create();
        $own = InventoryLocation::factory()->create(['store_id' => $manager->store_id, 'name' => 'Counter']);
        InventoryLocation::factory()->create(); // another store's

        $names = fn (User $user) => array_column($this->forwardSessionCookie($this->login($user))->getJson('/api/v1/inventory-locations')->assertOk()->json('data'), 'name');

        $this->assertSame(['Counter'], $names($manager));
        $this->assertSame(['Counter'], $names(User::factory()->create(['store_id' => $manager->store_id])), 'a cashier too');
        $this->assertSame([$own->id], array_column($this->forwardSessionCookie($this->login($manager))->getJson('/api/v1/inventory-locations')->json('data'), 'id'));
    }

    public function test_a_manager_still_cannot_create_or_edit_a_location(): void
    {
        $manager = User::factory()->manager()->create();
        $location = InventoryLocation::factory()->create(['store_id' => $manager->store_id]);

        $this->forwardSessionCookie($this->login($manager))->postJson('/api/v1/inventory-locations', ['name' => 'Backroom'])->assertStatus(403);
        $this->forwardSessionCookie($this->login($manager))->patchJson("/api/v1/inventory-locations/{$location->id}", ['name' => 'Renamed'])->assertStatus(403);

        $this->assertSame(1, InventoryLocation::where('store_id', $manager->store_id)->count());
        $this->assertNotSame('Renamed', $location->refresh()->name);
    }

    public function test_an_unauthenticated_request_cannot_list_locations(): void
    {
        $this->app['session.store']->flush();

        $this->getJson('/api/v1/inventory-locations')->assertStatus(401);
    }
}
