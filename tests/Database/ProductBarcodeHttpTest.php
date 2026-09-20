<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Stage 26 alternate barcodes (productBarcodeList/Create/Delete): the extra codes a product can be scanned by.
 * Reading needs the session, adding and removing need CATALOG_MANAGE; a code is unique per store across a product's
 * main barcode and every alternate, and a duplicate is the catalog's usual VALIDATION_FAILED field error (no new
 * error code). The Stage 20 lookup already honours alternates, so these tests scan through it.
 */
class ProductBarcodeHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private function url(Product $product, string $suffix = ''): string
    {
        return "/api/v1/products/{$product->id}/barcodes{$suffix}";
    }

    private function add(array $w, Product $product, ?string $barcode, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $w['manager'])->postJson($this->url($product), ['barcode' => $barcode]);
    }

    private function scan(array $w, string $barcode): TestResponse
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/products/by-barcode/'.rawurlencode($barcode));
    }

    private function messageOf(TestResponse $response): string
    {
        return $response->json('error.details.barcode.0');
    }

    public function test_an_alternate_barcode_can_be_added_and_the_product_is_then_found_by_scanning_it(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => '4800000000017']);

        $response = $this->add($w, $product, '4800000000024')->assertStatus(201);

        $response->assertJson(['product_id' => $product->id, 'barcode' => '4800000000024']);
        $this->assertNotNull($response->json('id'));
        $this->assertNotNull($response->json('created_at'));
        $this->assertDatabaseHas('product_barcodes', ['product_id' => $product->id, 'store_id' => $w['storeId'], 'barcode' => '4800000000024', 'is_primary' => false]);
        $this->scan($w, '4800000000024')->assertOk()->assertJson(['id' => $product->id]);
        $this->scan($w, '4800000000017')->assertOk()->assertJson(['id' => $product->id]);
    }

    public function test_a_product_without_a_main_barcode_can_still_have_alternates(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => null]);

        $this->add($w, $product, 'ALT-1')->assertStatus(201);
        $this->add($w, $product, 'ALT-2')->assertStatus(201);

        $this->scan($w, 'ALT-2')->assertOk()->assertJson(['id' => $product->id]);
    }

    public function test_the_barcode_is_stored_exactly_as_scanned_apart_from_surrounding_whitespace(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        $this->add($w, $product, "  Code-128_AbC\r\n")->assertStatus(201)->assertJson(['barcode' => 'Code-128_AbC']);

        $this->scan($w, 'Code-128_AbC')->assertOk();
        $this->scan($w, 'code-128_abc')->assertStatus(404);
    }

    public function test_an_inactive_product_can_have_alternates_and_a_scan_still_finds_it(): void
    {
        $w = $this->world();
        $product = Product::factory()->inactive()->create(['store_id' => $w['storeId']]);

        $this->add($w, $product, 'OLD-STOCK-1')->assertStatus(201);

        $this->scan($w, 'OLD-STOCK-1')->assertOk()->assertJson(['id' => $product->id, 'active' => false]);
    }

    public function test_adding_and_removing_are_audited(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        $id = $this->add($w, $product, '4800000000024')->assertStatus(201)->json('id');
        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);

        $added = AuditEvent::where('event_type', 'PRODUCT_BARCODE_ADDED')->firstOrFail();
        $this->assertSame('product', $added->entity_type);
        $this->assertSame($product->id, $added->entity_id);
        $this->assertSame($w['manager']->id, $added->actor_user_id);
        $this->assertEquals(['sku' => $product->sku, 'barcode' => '4800000000024'], $added->after_metadata);
        $removed = AuditEvent::where('event_type', 'PRODUCT_BARCODE_REMOVED')->firstOrFail();
        $this->assertSame($product->id, $removed->entity_id);
        $this->assertEquals(['sku' => $product->sku, 'barcode' => '4800000000024'], $removed->before_metadata);
    }

    // ------------------------------------------------------------------- reads

    public function test_the_list_holds_only_that_products_alternates_oldest_first_and_any_signed_in_user_can_read_it(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);
        $other = Product::factory()->create(['store_id' => $w['storeId']]);
        foreach (['B-2' => 9, 'A-1' => 8, 'C-3' => 7] as $code => $minutesAgo) {
            $id = $this->add($w, $product, $code)->assertStatus(201)->json('id');
            ProductBarcode::whereKey($id)->update(['created_at' => now()->subMinutes($minutesAgo)]); // B added first, then A, then C
        }
        $this->add($w, $other, 'OTHER-1')->assertStatus(201);

        $list = $this->asUser($w['cashier'])->getJson($this->url($product))->assertOk();

        $this->assertSame(['B-2', 'A-1', 'C-3'], array_column($list->json('data'), 'barcode'), 'in the order they were added');
        $this->assertSame(3, $list->json('meta.total'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson($this->url(Product::factory()->create(['store_id' => $w['storeId']])))->json('data'));
        $this->assertSame(['A-1'], array_column($this->asUser($w['manager'])->getJson($this->url($product).'?per_page=1&page=2')->json('data'), 'barcode'));
    }

    public function test_a_product_of_another_store_or_an_unknown_one_is_not_found_by_every_operation(): void
    {
        $w = $this->world();
        $foreign = Product::factory()->create();
        $foreignAlternate = ProductBarcode::create(['product_id' => $foreign->id, 'store_id' => $foreign->store_id, 'barcode' => 'THEIRS-1', 'is_primary' => false]);
        $missing = ['error' => ['code' => 'PRODUCT_NOT_FOUND']];

        $this->asUser($w['manager'])->getJson($this->url($foreign))->assertStatus(404)->assertJson($missing);
        $this->add($w, $foreign, 'MINE-1')->assertStatus(404)->assertJson($missing);
        $this->asUser($w['manager'])->deleteJson($this->url($foreign, "/{$foreignAlternate->id}"))->assertStatus(404)->assertJson($missing);
        $this->asUser($w['manager'])->getJson('/api/v1/products/'.Str::uuid().'/barcodes')->assertStatus(404)->assertJson($missing);

        $this->assertSame(1, ProductBarcode::where('product_id', $foreign->id)->count(), 'nothing changed in the other store');
        $this->assertSame(0, ProductBarcode::where('barcode', 'MINE-1')->count());
    }

    // -------------------------------------------------------------- validation

    public function test_the_barcode_is_required_and_at_most_255_characters(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        foreach ([null, '', '   ', str_repeat('9', 256)] as $bad) {
            $this->add($w, $product, $bad)->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']])->assertJsonPath('error.details.barcode.0', fn ($m) => is_string($m));
        }
        $this->asUser($w['manager'])->postJson($this->url($product), [])->assertStatus(422);
        $this->add($w, $product, str_repeat('9', 255))->assertStatus(201);
        $this->assertSame(1, ProductBarcode::count());
    }

    public function test_a_code_already_in_use_in_the_store_is_a_field_error_and_nothing_is_created(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => 'MAIN-1']);
        $other = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => 'OTHER-MAIN']);
        $this->add($w, $product, 'ALT-1')->assertStatus(201);
        $this->add($w, $other, 'OTHER-ALT')->assertStatus(201);

        $this->assertSame("This is already the product's main barcode.", $this->messageOf($this->add($w, $product, 'MAIN-1')->assertStatus(422)));
        $this->assertSame('This product already has this barcode.', $this->messageOf($this->add($w, $product, 'ALT-1')->assertStatus(422)));
        $this->assertSame('This barcode is already assigned to another product.', $this->messageOf($this->add($w, $product, 'OTHER-MAIN')->assertStatus(422)));
        $this->assertSame('This barcode is already assigned to another product.', $this->messageOf($this->add($w, $product, 'OTHER-ALT')->assertStatus(422)));

        $this->assertSame(1, ProductBarcode::where('product_id', $product->id)->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'PRODUCT_BARCODE_ADDED')->where('entity_id', $product->id)->where('after_metadata->barcode', 'OTHER-MAIN')->count());
    }

    public function test_the_same_code_may_exist_in_another_store(): void
    {
        $w = $this->world();
        $theirs = Product::factory()->create(['barcode' => 'SHARED-1']);
        ProductBarcode::create(['product_id' => $theirs->id, 'store_id' => $theirs->store_id, 'barcode' => 'SHARED-2', 'is_primary' => false]);
        $mine = Product::factory()->create(['store_id' => $w['storeId']]);

        $this->add($w, $mine, 'SHARED-1')->assertStatus(201);
        $this->add($w, $mine, 'SHARED-2')->assertStatus(201);

        $this->scan($w, 'SHARED-1')->assertOk()->assertJson(['id' => $mine->id]);
    }

    // ----------------------------------------------------------------- removal

    public function test_removing_an_alternate_stops_the_product_being_found_by_it(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => 'MAIN-1']);
        $id = $this->add($w, $product, 'ALT-1')->assertStatus(201)->json('id');
        $this->scan($w, 'ALT-1')->assertOk();

        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);

        $this->scan($w, 'ALT-1')->assertStatus(404)->assertJson(['error' => ['code' => 'BARCODE_NOT_FOUND']]);
        $this->scan($w, 'MAIN-1')->assertOk();
        $this->add($w, $product, 'ALT-1')->assertStatus(201);
    }

    public function test_removing_something_that_is_not_there_is_not_an_error_and_never_touches_another_product(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);
        $other = Product::factory()->create(['store_id' => $w['storeId']]);
        $otherAlternate = $this->add($w, $other, 'OTHER-ALT')->assertStatus(201)->json('id');
        $id = $this->add($w, $product, 'ALT-1')->assertStatus(201)->json('id');

        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);
        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(204);
        $this->asUser($w['manager'])->deleteJson($this->url($product, '/'.Str::uuid()))->assertStatus(204);
        $this->asUser($w['manager'])->deleteJson($this->url($product, "/{$otherAlternate}"))->assertStatus(204);

        $this->assertTrue(ProductBarcode::whereKey($otherAlternate)->exists(), 'an id from another product cannot be removed through this one');
        $this->assertSame(1, AuditEvent::where('event_type', 'PRODUCT_BARCODE_REMOVED')->count(), 'only the real removal is recorded');
    }

    // ------------------------------------------------ access and the main barcode

    public function test_adding_and_removing_need_catalog_manage_and_a_session(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);
        $id = $this->add($w, $product, 'ALT-1')->assertStatus(201)->json('id');

        $this->add($w, $product, 'ALT-2', $w['cashier'])->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->asUser($w['cashier'])->deleteJson($this->url($product, "/{$id}"))->assertStatus(403);
        $this->add($w, $product, 'ALT-3', $w['admin'])->assertStatus(201);

        $this->unencryptedCookies = [];
        $this->defaultCookies = [];
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->getJson($this->url($product))->assertStatus(401);
        $this->postJson($this->url($product), ['barcode' => 'X'])->assertStatus(401);
        $this->deleteJson($this->url($product, "/{$id}"))->assertStatus(401);

        $this->assertSame(['ALT-1', 'ALT-3'], ProductBarcode::where('product_id', $product->id)->orderBy('barcode')->pluck('barcode')->all());
    }

    public function test_making_an_alternate_the_main_barcode_moves_it_instead_of_keeping_it_in_both_places(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'barcode' => 'OLD-MAIN']);
        $this->add($w, $product, 'NEW-MAIN')->assertStatus(201);

        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", [
            'sku' => $product->sku, 'name' => $product->name, 'unit_of_measure' => $product->unit_of_measure,
            'selling_price' => $product->selling_price, 'tax_class' => $product->tax_class, 'barcode' => 'NEW-MAIN',
        ])->assertOk()->assertJson(['barcode' => 'NEW-MAIN']);

        $this->assertSame(0, ProductBarcode::where('product_id', $product->id)->count(), 'the code is the main one now, not also an alternate');
        $this->scan($w, 'NEW-MAIN')->assertOk()->assertJson(['id' => $product->id]);
        $this->scan($w, 'OLD-MAIN')->assertStatus(404);
    }

    public function test_a_product_cannot_be_given_a_main_barcode_that_is_another_products_alternate(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);
        $other = Product::factory()->create(['store_id' => $w['storeId']]);
        $this->add($w, $other, 'TAKEN-1')->assertStatus(201);
        $body = fn (Product $p, string $barcode) => [
            'sku' => $p->sku, 'name' => $p->name, 'unit_of_measure' => $p->unit_of_measure,
            'selling_price' => $p->selling_price, 'tax_class' => $p->tax_class, 'barcode' => $barcode,
        ];

        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $body($product, 'TAKEN-1'))->assertStatus(422)->assertJsonPath('error.details.barcode.0', 'This barcode is already assigned to another product.');
        $this->asUser($w['manager'])->postJson('/api/v1/products', $body($product, 'TAKEN-1') + ['sku' => 'NEW-SKU'])->assertStatus(422);

        $this->assertNotSame('TAKEN-1', $product->refresh()->barcode);
    }

    public function test_a_csv_import_cannot_take_another_products_alternate_and_moves_its_own(): void
    {
        $w = $this->world();
        $mine = Product::factory()->create(['store_id' => $w['storeId'], 'sku' => 'MINE', 'barcode' => 'OLD']);
        $other = Product::factory()->create(['store_id' => $w['storeId'], 'sku' => 'OTHER']);
        $this->add($w, $other, 'OTHER-ALT')->assertStatus(201);
        $this->add($w, $mine, 'MINE-ALT')->assertStatus(201);
        $import = fn (string $barcode) => $this->asUser($w['manager'])->call('POST', '/api/v1/products/import', [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], "sku,barcode\nMINE,{$barcode}\n");

        $blocked = $import('OTHER-ALT');
        $this->assertSame(1, $blocked->json('failed'));
        $this->assertSame('OLD', $mine->refresh()->barcode);

        $import('MINE-ALT');
        $this->assertSame('MINE-ALT', $mine->refresh()->barcode);
        $this->assertSame(0, ProductBarcode::where('product_id', $mine->id)->count(), 'promoted, not duplicated');
    }

    public function test_the_barcode_of_a_product_in_another_store_is_unaffected_by_its_own_stores_rules(): void
    {
        $w = $this->world();
        $stranger = User::factory()->admin()->create(['store_id' => Store::factory()->create()->id]);
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        $this->asUser($stranger)->postJson($this->url($product), ['barcode' => 'NOPE'])->assertStatus(404);
        $this->assertSame(0, ProductBarcode::count());
    }
}
