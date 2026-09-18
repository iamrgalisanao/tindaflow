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
}
