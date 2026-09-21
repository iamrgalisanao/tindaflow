<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 29 packaging: an alternate barcode may now also be a named pack that holds N single units ("Case", 24), and a
 * pack needs no barcode at all. Stock stays in the product's one base unit; this only records how to convert. The
 * plain alias of stage 26 (a barcode alone, one unit) behaves exactly as before.
 */
class ProductPackagingHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function url(Product $product, string $suffix = ''): string
    {
        return "/api/v1/products/{$product->id}/barcodes{$suffix}";
    }

    /** @param  array<string, mixed>  $body */
    private function add(array $w, Product $product, array $body): TestResponse
    {
        return $this->asUser($w['manager'])->postJson($this->url($product), $body);
    }

    private function product(array $w, array $attributes = []): Product
    {
        return Product::factory()->create(['store_id' => $w['storeId']] + $attributes);
    }

    public function test_a_named_pack_with_a_size_and_a_barcode_can_be_added(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        $response = $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24', 'barcode' => '4800000000123'])->assertStatus(201);

        $response->assertJson(['product_id' => $product->id, 'name' => 'Case', 'units_per_base' => '24.000', 'barcode' => '4800000000123', 'can_receive' => true]);
        $this->assertDatabaseHas('product_barcodes', ['id' => $response->json('id'), 'can_sell' => false]);
        $this->assertArrayNotHasKey('can_sell', $response->json(), 'selling by the pack is off and not exposed');
    }

    public function test_a_pack_without_a_barcode_is_allowed_and_the_list_shows_it(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        $id = $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24'])->assertStatus(201)->assertJson(['barcode' => null])->json('id');
        $this->add($w, $product, ['name' => 'Tray', 'units_per_base' => '12'])->assertStatus(201);

        $rows = $this->asUser($w['cashier'])->getJson($this->url($product))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Case', 'Tray'], array_column($rows, 'name'));
        $this->assertSame([null, null], array_column($rows, 'barcode'));
        $this->assertSame(2, ProductBarcode::where('product_id', $product->id)->whereNull('barcode')->count(), 'NULL barcodes never collide');
        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);
    }

    public function test_a_plain_alias_is_one_unit_and_is_audited_exactly_as_before(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        $row = $this->add($w, $product, ['barcode' => 'ALT-1'])->assertStatus(201)->assertJson(['name' => null, 'units_per_base' => '1.000', 'can_receive' => true])->json();

        $audit = AuditEvent::where('event_type', 'PRODUCT_BARCODE_ADDED')->firstOrFail();
        $this->assertEquals(['sku' => $product->sku, 'barcode' => 'ALT-1'], $audit->after_metadata, 'no name or size keys for a plain alias');
        $this->assertSame('ALT-1', $row['barcode']);
    }

    public function test_adding_and_removing_a_pack_records_its_name_and_size(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        $id = $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24', 'barcode' => 'CASE-1'])->json('id');
        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);

        $this->assertEquals(['sku' => $product->sku, 'barcode' => 'CASE-1', 'name' => 'Case', 'units_per_base' => '24.000'], AuditEvent::where('event_type', 'PRODUCT_BARCODE_ADDED')->firstOrFail()->after_metadata);
        $this->assertEquals(['sku' => $product->sku, 'barcode' => 'CASE-1', 'name' => 'Case', 'units_per_base' => '24.000'], AuditEvent::where('event_type', 'PRODUCT_BARCODE_REMOVED')->firstOrFail()->before_metadata);
    }

    public function test_a_row_needs_a_barcode_or_a_name(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        foreach ([[], ['units_per_base' => '6'], ['barcode' => null, 'name' => null], ['barcode' => '  ', 'name' => '  ']] as $body) {
            $this->add($w, $product, $body)->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        }
        $this->assertSame(0, ProductBarcode::count());
    }

    public function test_the_pack_size_and_name_are_validated(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        foreach (['0', '-1', '1.2345', 'abc', '10000000', '0.000'] as $bad) {
            $this->add($w, $product, ['name' => 'Case', 'units_per_base' => $bad])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['units_per_base']]]);
        }
        $this->add($w, $product, ['name' => str_repeat('n', 61)])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['name']]]);
        $this->add($w, $product, ['name' => 'Case', 'can_receive' => 'maybe'])->assertStatus(422);
        $this->add($w, $product, ['name' => str_repeat('n', 60), 'units_per_base' => '0.5'])->assertStatus(201);
        $this->assertSame(1, ProductBarcode::count());
    }

    public function test_a_product_cannot_have_two_packs_with_the_same_name_whatever_the_capitals(): void
    {
        $w = $this->world();
        $product = $this->product($w);
        $other = $this->product($w);
        $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24'])->assertStatus(201);

        foreach (['Case', 'case', 'CASE'] as $name) {
            $this->add($w, $product, ['name' => $name, 'units_per_base' => '12'])->assertStatus(422)->assertJsonPath('error.details.name.0', 'This product already has a pack with this name.');
        }
        $this->add($w, $other, ['name' => 'Case', 'units_per_base' => '12'])->assertStatus(201);
        $this->assertSame(1, ProductBarcode::where('product_id', $product->id)->count());
    }

    public function test_a_pack_can_be_marked_not_for_receiving(): void
    {
        $w = $this->world();
        $product = $this->product($w);

        $this->add($w, $product, ['name' => 'Display', 'units_per_base' => '6', 'can_receive' => false])->assertStatus(201)->assertJson(['can_receive' => false]);
    }

    public function test_barcode_uniqueness_still_holds_beside_barcode_less_packs(): void
    {
        $w = $this->world();
        $product = $this->product($w);
        $other = $this->product($w);
        $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24'])->assertStatus(201);
        $this->add($w, $product, ['name' => 'Tray', 'units_per_base' => '12', 'barcode' => 'TRAY-1'])->assertStatus(201);

        $this->add($w, $other, ['barcode' => 'TRAY-1'])->assertStatus(422)->assertJsonPath('error.details.barcode.0', 'This barcode is already assigned to another product.');
        $this->add($w, $other, ['name' => 'Case', 'units_per_base' => '6'])->assertStatus(201);
    }

    public function test_the_database_refuses_a_row_that_is_nothing_or_holds_no_units(): void
    {
        $w = $this->world();
        $product = $this->product($w);
        $row = ['product_id' => $product->id, 'store_id' => $w['storeId'], 'is_primary' => false];

        foreach ([['barcode' => null, 'name' => null], ['barcode' => 'X-1', 'units_per_base' => '0'], ['barcode' => 'X-2', 'units_per_base' => '-3']] as $bad) {
            try {
                DB::transaction(fn () => ProductBarcode::create($row + $bad));
                $this->fail('the database must refuse '.json_encode($bad));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getPrevious()->errorInfo[0], 'a check constraint fired');
            }
        }
    }

    public function test_a_csv_import_still_works_when_the_store_has_barcode_less_packs(): void
    {
        $w = $this->world();
        $product = $this->product($w, ['sku' => 'IMP-1', 'barcode' => null]);
        $this->add($w, $product, ['name' => 'Case', 'units_per_base' => '24'])->assertStatus(201);

        $response = $this->asUser($w['manager'])->call('POST', '/api/v1/products/import', [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], "sku,barcode\nIMP-1,IMPORTED-1\n");

        $this->assertSame(0, $response->json('failed'));
        $this->assertSame('IMPORTED-1', $product->refresh()->barcode);
    }
}
