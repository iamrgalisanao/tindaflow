<?php

namespace Tests\Database;

use App\Models\Product;
use App\Models\ProductBarcode;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * openapi.yaml productLookupByBarcode: what a cashier's scanner calls. Session + enrolled terminal, no
 * capability, exact match on the product's own barcode or an alternate barcode, scoped to the store.
 */
class BarcodeLookupHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function scan(array $w, string $barcode, string $as = 'cashier')
    {
        return $this->asUser($w[$as], $w['enroll1'])->getJson('/api/v1/products/by-barcode/'.rawurlencode($barcode));
    }

    public function test_a_products_own_barcode_finds_it_with_its_current_saleable_data(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '4800016001234', 'selling_price' => '65.00', 'name' => 'Coca Cola 1.5L']);

        $response = $this->scan($w, '4800016001234');

        $response->assertOk();
        $response->assertJson(['id' => $product->id, 'name' => 'Coca Cola 1.5L', 'barcode' => '4800016001234', 'selling_price' => '65.00', 'tax_class' => $product->tax_class, 'active' => true]);
        $this->assertSame(['id', 'sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'unit_of_measure', 'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level', 'active'], array_keys($response->json()));

        // The price is read fresh each time, never remembered.
        $product->update(['selling_price' => '70.00']);
        $this->assertSame('70.00', $this->scan($w, '4800016001234')->json('selling_price'));
    }

    public function test_an_alternate_barcode_finds_the_same_product(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '111111111111']);
        ProductBarcode::create(['product_id' => $product->id, 'store_id' => $w['storeId'], 'barcode' => '222222222222', 'is_primary' => false]);

        $this->assertSame($product->id, $this->scan($w, '222222222222')->json('id'));
        $this->assertSame($product->id, $this->scan($w, '111111111111')->json('id'));
    }

    public function test_an_unknown_barcode_is_barcode_not_found(): void
    {
        $w = $this->world();
        Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '4800016001234']);

        foreach (['9999999999999', '480001600123', '48000160012345', '4800016001234 x'] as $barcode) {
            $this->scan($w, $barcode)->assertStatus(404)->assertJson(['error' => ['code' => 'BARCODE_NOT_FOUND']]);
        }
    }

    public function test_the_match_is_exact_not_a_prefix_or_a_case_fold(): void
    {
        $w = $this->world();
        Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => 'AbC-123']);

        $this->scan($w, 'AbC-123')->assertOk();
        $this->scan($w, 'abc-123')->assertStatus(404);
        $this->scan($w, 'AbC-12')->assertStatus(404);
    }

    public function test_a_scanners_trailing_line_break_is_ignored(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '4800016001234']);

        $this->scan($w, "4800016001234\r\n")->assertOk()->assertJson(['id' => $product->id]);
        $this->scan($w, ' 4800016001234 ')->assertOk()->assertJson(['id' => $product->id]);
    }

    public function test_it_only_looks_in_the_actors_own_store(): void
    {
        $w = $this->world();
        $other = $this->world();
        $theirs = Product::factory()->create(['store_id' => $other['storeId'], 'barcode' => '5555555555555']);
        $this->scan($w, '5555555555555')->assertStatus(404)->assertJson(['error' => ['code' => 'BARCODE_NOT_FOUND']]);

        // The same barcode may exist in two stores (uniqueness is per store): each finds its own.
        $mine = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '5555555555555']);
        $this->assertSame($mine->id, $this->scan($w, '5555555555555')->json('id'));
        $this->assertSame($theirs->id, $this->scan($other, '5555555555555')->json('id'));
    }

    public function test_an_inactive_product_is_returned_as_inactive_rather_than_hidden(): void
    {
        $w = $this->world();
        $product = Product::factory()->inactive()->create(['store_id' => $w['storeId'], 'barcode' => '7777777777777']);

        $this->scan($w, '7777777777777')->assertOk()->assertJson(['id' => $product->id, 'active' => false]);
    }

    public function test_it_needs_a_session_and_an_enrolled_terminal_but_no_capability(): void
    {
        $w = $this->world();
        Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '4800016001234']);

        // A cashier holds no catalog capability and can still scan.
        $this->scan($w, '4800016001234', 'cashier')->assertOk();
        $this->scan($w, '4800016001234', 'manager')->assertOk();

        // A signed-in browser that is not an enrolled terminal cannot.
        $this->asUser($w['cashier'])->getJson('/api/v1/products/by-barcode/4800016001234')
            ->assertStatus(403)->assertJson(['error' => ['code' => 'TERMINAL_NOT_ENROLLED']]);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/products/by-barcode/4800016001234')->assertStatus(401);
    }

    public function test_the_lookup_does_not_shadow_fetching_a_product_by_id(): void
    {
        $w = $this->world();

        $this->asUser($w['cashier'])->getJson("/api/v1/products/{$w['product']->id}")->assertOk()->assertJson(['id' => $w['product']->id]);
    }
}
