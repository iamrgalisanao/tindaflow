<?php

namespace Tests\Database;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml productList -- the minimal read-only Catalog slice
 * needed for the POS cart screen to browse/search products.
 */
class ProductListHttpTest extends PostgresSchemaTestCase
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

    public function test_lists_only_the_actors_own_store_products(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);
        $ownProduct = Product::factory()->create(['store_id' => $store->id, 'name' => 'Own Product']);
        $otherProduct = Product::factory()->create(['name' => 'Other Store Product']);
        $login = $this->login($user);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/products');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownProduct->id));
        $this->assertFalse($ids->contains($otherProduct->id));
    }

    public function test_search_matches_name_or_sku(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);
        $match = Product::factory()->create(['store_id' => $store->id, 'name' => 'Coca Cola 1L', 'sku' => 'BEV-001']);
        $noMatch = Product::factory()->create(['store_id' => $store->id, 'name' => 'Instant Noodles']);
        $login = $this->login($user);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/products?search=coca');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
        $this->assertFalse($ids->contains($noMatch->id));
    }

    public function test_inactive_products_are_excluded_by_the_active_filter(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);
        $active = Product::factory()->create(['store_id' => $store->id]);
        $inactive = Product::factory()->inactive()->create(['store_id' => $store->id]);
        $login = $this->login($user);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/products?active=1');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }

    public function test_a_category_filter_that_is_not_a_uuid_matches_nothing_instead_of_failing(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);
        Product::factory()->create(['store_id' => $store->id]);

        $response = $this->forwardSessionCookie($this->login($user))->getJson('/api/v1/products?category_id=not-a-uuid');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/products');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    public function test_no_terminal_credential_is_required(): void
    {
        $store = Store::factory()->create();
        $user = User::factory()->create(['store_id' => $store->id]);
        Product::factory()->create(['store_id' => $store->id]);
        $login = $this->login($user);

        // No tindaflow_terminal cookie presented anywhere here.
        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/products');

        $response->assertOk();
    }
}
