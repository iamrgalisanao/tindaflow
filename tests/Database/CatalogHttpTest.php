<?php

namespace Tests\Database;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * openapi.yaml Catalog tag -- productCreate/Get/Update/Activate/Deactivate and categoryList/Create,
 * brandList/Create. All operations already existed in the frozen contract, so no error code is new:
 * duplicates and bad references surface as VALIDATION_FAILED field errors, unknown or foreign
 * products as PRODUCT_NOT_FOUND.
 */
class CatalogHttpTest extends PostgresSchemaTestCase
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

    private function asUser(User $user): static
    {
        return $this->forwardSessionCookie($this->login($user));
    }

    /** @return array<string, mixed> */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'sku' => 'RIC-001',
            'name' => 'Rice 1kg',
            'unit_of_measure' => 'kg',
            'selling_price' => '55.00',
            'tax_class' => 'VATABLE',
        ], $overrides);
    }

    // ---------------------------------------------------------------- create

    public function test_an_admin_can_create_a_product_scoped_to_their_store(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id]);
        $brand = Brand::factory()->create(['store_id' => $admin->store_id]);

        $response = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput([
            'barcode' => '4800000000017',
            'description' => 'Long grain',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'cost' => '40.00',
        ]));

        $response->assertStatus(201);
        $response->assertJson([
            'sku' => 'RIC-001',
            'barcode' => '4800000000017',
            'name' => 'Rice 1kg',
            'description' => 'Long grain',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_of_measure' => 'kg',
            'cost' => '40.00',
            'selling_price' => '55.00',
            'tax_class' => 'VATABLE',
            'track_inventory' => true,
            'reorder_level' => 0,
            'active' => true,
        ]);
        $this->assertSame($admin->store_id, Product::findOrFail($response->json('id'))->store_id);
    }

    public function test_a_manager_holds_catalog_manage_too(): void
    {
        $manager = User::factory()->manager()->create();

        $this->asUser($manager)->postJson('/api/v1/products', $this->validInput())->assertStatus(201);
    }

    public function test_a_cashier_cannot_create_a_product(): void
    {
        $cashier = User::factory()->create();

        $response = $this->asUser($cashier)->postJson('/api/v1/products', $this->validInput());

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    public function test_an_unauthenticated_request_cannot_create_a_product(): void
    {
        $this->postJson('/api/v1/products', $this->validInput())->assertStatus(401);
    }

    public function test_missing_required_fields_are_rejected_with_field_details(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/products', []);

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertEqualsCanonicalizing(
            ['sku', 'name', 'unit_of_measure', 'selling_price', 'tax_class'],
            array_keys($response->json('error.details')),
        );
    }

    public function test_a_duplicate_sku_in_the_same_store_is_rejected_but_allowed_in_another_store(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'RIC-001']);
        $otherStoreAdmin = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);

        $duplicate = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput());
        $duplicate->assertStatus(422);
        $duplicate->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('sku', $duplicate->json('error.details'));

        $this->asUser($otherStoreAdmin)->postJson('/api/v1/products', $this->validInput())->assertStatus(201);
    }

    public function test_a_barcode_already_on_another_product_or_an_alternate_barcode_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['store_id' => $admin->store_id, 'barcode' => '111']);
        $owner = Product::factory()->withoutBarcode()->create(['store_id' => $admin->store_id]);
        ProductBarcode::create(['product_id' => $owner->id, 'store_id' => $admin->store_id, 'barcode' => '222', 'is_primary' => false]);

        $primary = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput(['sku' => 'A-1', 'barcode' => '111']));
        $alternate = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput(['sku' => 'A-2', 'barcode' => '222']));

        foreach ([$primary, $alternate] as $response) {
            $response->assertStatus(422);
            $this->assertArrayHasKey('barcode', $response->json('error.details'));
        }
    }

    public function test_an_empty_barcode_is_stored_as_null_and_a_barcode_may_repeat_across_stores(): void
    {
        $admin = User::factory()->admin()->create();
        Product::factory()->create(['barcode' => '999']); // another store's

        $response = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput(['barcode' => '']));
        $sameAsForeign = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput(['sku' => 'B-2', 'barcode' => '999']));

        $response->assertStatus(201);
        $this->assertNull($response->json('barcode'));
        $sameAsForeign->assertStatus(201);
    }

    public function test_category_and_brand_must_belong_to_the_actors_store(): void
    {
        $admin = User::factory()->admin()->create();
        $foreignCategory = Category::factory()->create();
        $foreignBrand = Brand::factory()->create();

        $response = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput([
            'category_id' => $foreignCategory->id,
            'brand_id' => $foreignBrand->id,
        ]));

        $response->assertStatus(422);
        $this->assertEqualsCanonicalizing(['category_id', 'brand_id'], array_keys($response->json('error.details')));
    }

    public function test_tax_class_and_money_shapes_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([
            ['tax_class' => 'SALES_TAX'],
            ['selling_price' => '55'],
            ['selling_price' => '-5.00'],
            ['selling_price' => '55.5'],
            ['cost' => '40'],
            ['reorder_level' => -1],
        ] as $bad) {
            $response = $this->asUser($admin)->postJson('/api/v1/products', $this->validInput($bad));
            $response->assertStatus(422);
            $this->assertArrayHasKey(array_key_first($bad), $response->json('error.details'), json_encode($bad));
        }

        foreach (['VATABLE', 'VAT_EXEMPT', 'ZERO_RATED', 'NON_VAT'] as $index => $taxClass) {
            $this->asUser($admin)->postJson('/api/v1/products', $this->validInput(['sku' => "T-{$index}", 'tax_class' => $taxClass]))
                ->assertStatus(201);
        }
    }

    // ------------------------------------------------------------------- get

    public function test_get_returns_own_product_and_hides_other_stores_and_unknown_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $own = Product::factory()->create(['store_id' => $admin->store_id]);
        $foreign = Product::factory()->create();

        $this->asUser($admin)->getJson("/api/v1/products/{$own->id}")->assertOk()->assertJson(['id' => $own->id, 'sku' => $own->sku]);

        foreach ([$foreign->id, (string) Str::uuid()] as $id) {
            $this->asUser($admin)->getJson("/api/v1/products/{$id}")
                ->assertStatus(404)
                ->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);
        }

        $this->asUser($admin)->getJson('/api/v1/products/not-a-uuid')->assertStatus(404);
    }

    public function test_any_authenticated_user_can_read_a_product(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['store_id' => $cashier->store_id]);

        $this->asUser($cashier)->getJson("/api/v1/products/{$product->id}")->assertOk();
    }

    // ---------------------------------------------------------------- update

    public function test_update_changes_catalog_fields_and_leaves_omitted_optional_fields_alone(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'store_id' => $admin->store_id, 'sku' => 'OLD-1', 'description' => 'Keep me', 'barcode' => '777', 'selling_price' => '10.00',
        ]);

        $response = $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $this->validInput(['sku' => 'OLD-1', 'selling_price' => '12.50']));

        $response->assertOk();
        $response->assertJson(['selling_price' => '12.50', 'name' => 'Rice 1kg', 'description' => 'Keep me', 'barcode' => '777']);
    }

    public function test_update_can_clear_optional_fields_with_null(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id]);
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'category_id' => $category->id, 'barcode' => '888']);

        $response = $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $this->validInput([
            'sku' => $product->sku, 'barcode' => null, 'category_id' => null,
        ]));

        $response->assertOk();
        $this->assertNull($response->json('barcode'));
        $this->assertNull($response->json('category_id'));
    }

    public function test_update_may_keep_its_own_sku_and_barcode_but_not_take_another_products(): void
    {
        $admin = User::factory()->admin()->create();
        $mine = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'MINE', 'barcode' => '555']);
        Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'THEIRS', 'barcode' => '666']);

        $same = $this->asUser($admin)->patchJson("/api/v1/products/{$mine->id}", $this->validInput(['sku' => 'MINE', 'barcode' => '555']));
        $collide = $this->asUser($admin)->patchJson("/api/v1/products/{$mine->id}", $this->validInput(['sku' => 'THEIRS', 'barcode' => '666']));

        $same->assertOk();
        $collide->assertStatus(422);
        $this->assertEqualsCanonicalizing(['sku', 'barcode'], array_keys($collide->json('error.details')));
    }

    public function test_update_requires_the_same_required_fields_as_create(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id]);

        $response = $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", ['name' => 'Only a name']);

        $response->assertStatus(422);
        $this->assertArrayHasKey('sku', $response->json('error.details'));
    }

    public function test_update_of_another_stores_product_is_not_found(): void
    {
        $admin = User::factory()->admin()->create();
        $foreign = Product::factory()->create();

        $this->asUser($admin)->patchJson("/api/v1/products/{$foreign->id}", $this->validInput())
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);
        $this->assertNotSame('Rice 1kg', $foreign->refresh()->name);
    }

    public function test_a_cashier_cannot_update_a_product(): void
    {
        $cashier = User::factory()->create();
        $product = Product::factory()->create(['store_id' => $cashier->store_id]);

        $this->asUser($cashier)->patchJson("/api/v1/products/{$product->id}", $this->validInput())->assertStatus(403);
    }

    /** Stage 2: sale_item snapshots are immutable, so a catalog edit never reaches history. */
    public function test_updating_a_product_never_changes_historical_sale_item_snapshots(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id, 'sku' => 'RIC-001', 'name' => 'Rice 1kg', 'selling_price' => '55.00']);
        $item = SaleItem::factory()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => 'Rice 1kg',
            'sku_snapshot' => 'RIC-001',
            'unit_price_snapshot' => '55.00',
        ]);

        $this->asUser($admin)->patchJson("/api/v1/products/{$product->id}", $this->validInput([
            'sku' => 'RIC-002', 'name' => 'Premium Rice 1kg', 'selling_price' => '99.00',
        ]))->assertOk();

        $item->refresh();
        $this->assertSame('Rice 1kg', $item->product_name_snapshot);
        $this->assertSame('RIC-001', $item->sku_snapshot);
        $this->assertSame('55.00', $item->unit_price_snapshot);
    }

    // ------------------------------------------------- activate / deactivate

    public function test_deactivate_and_activate_are_idempotent_and_never_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id]);

        foreach ([1, 2] as $attempt) {
            $this->asUser($admin)->postJson("/api/v1/products/{$product->id}/deactivate")->assertOk()->assertJson(['active' => false]);
        }
        $this->assertDatabaseHas('products', ['id' => $product->id, 'active' => false]);
        $this->asUser($admin)->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJson(['active' => false]);

        foreach ([1, 2] as $attempt) {
            $this->asUser($admin)->postJson("/api/v1/products/{$product->id}/activate")->assertOk()->assertJson(['active' => true]);
        }
    }

    public function test_a_deactivated_product_drops_out_of_the_active_listing(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['store_id' => $admin->store_id]);
        $this->asUser($admin)->postJson("/api/v1/products/{$product->id}/deactivate")->assertOk();

        $active = $this->asUser($admin)->getJson('/api/v1/products?active=1');
        $inactive = $this->asUser($admin)->getJson('/api/v1/products?active=0');

        $this->assertFalse(collect($active->json('data'))->pluck('id')->contains($product->id));
        $this->assertTrue(collect($inactive->json('data'))->pluck('id')->contains($product->id));
    }

    public function test_activation_is_capability_gated_and_store_scoped(): void
    {
        $cashier = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $ownProduct = Product::factory()->create(['store_id' => $cashier->store_id]);
        $foreign = Product::factory()->create();

        $this->asUser($cashier)->postJson("/api/v1/products/{$ownProduct->id}/deactivate")->assertStatus(403);
        $this->asUser($admin)->postJson("/api/v1/products/{$foreign->id}/deactivate")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PRODUCT_NOT_FOUND']]);
        $this->assertTrue($foreign->refresh()->active);
    }

    // ------------------------------------------------------------------ list

    public function test_per_page_is_clamped_to_the_contract_maximum(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->asUser($admin)->getJson('/api/v1/products?per_page=1000');

        $response->assertOk();
        $this->assertSame(100, $response->json('meta.per_page'));
    }

    // ------------------------------------------------------ categories/brands

    public function test_categories_are_listed_by_name_for_any_user_and_scoped_to_the_store(): void
    {
        $cashier = User::factory()->create();
        Category::factory()->create(['store_id' => $cashier->store_id, 'name' => 'Snacks']);
        Category::factory()->create(['store_id' => $cashier->store_id, 'name' => 'Beverages']);
        Category::factory()->create(['name' => 'Foreign']);

        $response = $this->asUser($cashier)->getJson('/api/v1/categories');

        $response->assertOk();
        $this->assertSame(['Beverages', 'Snacks'], collect($response->json('data'))->pluck('name')->all());
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }

    public function test_only_catalog_managers_can_create_categories_and_the_name_is_required(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create();

        $created = $this->asUser($admin)->postJson('/api/v1/categories', ['name' => 'Beverages']);
        $created->assertStatus(201);
        $created->assertJson(['name' => 'Beverages']);
        $this->assertSame($admin->store_id, Category::findOrFail($created->json('id'))->store_id);

        $this->asUser($admin)->postJson('/api/v1/categories', [])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->asUser($cashier)->postJson('/api/v1/categories', ['name' => 'Nope'])->assertStatus(403);
    }

    public function test_brands_follow_the_same_rules_as_categories(): void
    {
        $admin = User::factory()->admin()->create();
        $cashier = User::factory()->create(['store_id' => $admin->store_id]);
        Brand::factory()->create(['name' => 'Foreign Brand']);

        $this->asUser($admin)->postJson('/api/v1/brands', ['name' => 'Datu Puti'])->assertStatus(201)->assertJson(['name' => 'Datu Puti']);
        $list = $this->asUser($cashier)->getJson('/api/v1/brands');

        $list->assertOk();
        $this->assertSame(['Datu Puti'], collect($list->json('data'))->pluck('name')->all());
        $this->asUser($cashier)->postJson('/api/v1/brands', ['name' => 'Nope'])->assertStatus(403);
        $this->asUser($admin)->postJson('/api/v1/brands', [])->assertStatus(422);
    }
}
