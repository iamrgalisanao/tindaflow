<?php

namespace Tests\Database;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Catalog\ProductCsv;
use App\Services\Catalog\ProductImportService;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * openapi.yaml productExport (any authenticated user) and productImport (CATALOG_MANAGE). Both use the
 * ProductCsv column layout, so an export imports straight back.
 */
class ProductCsvHttpTest extends PostgresSchemaTestCase
{
    private const HEADER = 'sku,barcode,name,description,category,brand,unit_of_measure,cost,selling_price,tax_class,track_inventory,reorder_level,active';

    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $cookie = collect($this->login($user)->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function importCsv(User $user, string $csv, bool $dryRun = false): TestResponse
    {
        return $this->asUser($user)->call(
            'POST',
            '/api/v1/products/import'.($dryRun ? '?dry_run=true' : ''),
            [], [], [],
            ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'],
            $csv,
        );
    }

    private function exportCsv(User $user): string
    {
        $response = $this->asUser($user)->get('/api/v1/products/export');
        $response->assertOk();

        return $response->streamedContent();
    }

    /** @return list<list<string|null>> */
    private function rowsOf(string $csv): array
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function product(User $admin, array $attributes = []): Product
    {
        return Product::factory()->create(array_merge(['store_id' => $admin->store_id], $attributes));
    }

    /** @return array{created: int, updated: int, unchanged: int, failed: int, errors: list<array{row: int, message: string}>} */
    private function resultOf(TestResponse $response): array
    {
        $response->assertStatus(202);

        return $response->json();
    }

    // ---------------------------------------------------------------- export

    public function test_the_export_lists_every_product_in_the_pinned_columns_ordered_by_sku(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id, 'name' => 'Grocery']);
        $brand = Brand::factory()->create(['store_id' => $admin->store_id, 'name' => 'Sunrise']);
        $this->product($admin, ['sku' => 'B-2', 'barcode' => null, 'name' => 'Inactive item', 'unit_of_measure' => 'pc', 'description' => null, 'cost' => null, 'selling_price' => '9.50', 'tax_class' => 'NON_VAT', 'track_inventory' => false, 'reorder_level' => 0, 'active' => false]);
        $this->product($admin, ['sku' => 'A-1', 'barcode' => '4800016001234', 'name' => 'Rice, "premium"', 'description' => "Long\ngrain", 'category_id' => $category->id, 'brand_id' => $brand->id, 'unit_of_measure' => 'kg', 'cost' => '40.00', 'selling_price' => '55.00', 'tax_class' => 'VATABLE', 'reorder_level' => 12, 'active' => true]);
        $this->product(User::factory()->admin()->create(), ['sku' => 'OTHER-STORE']);

        $csv = $this->exportCsv($admin);
        $rows = $this->rowsOf($csv);

        $this->assertStringStartsWith(self::HEADER."\n", $csv);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $csv);
        $this->assertSame(explode(',', self::HEADER), $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['A-1', '4800016001234', 'Rice, "premium"', "Long\ngrain", 'Grocery', 'Sunrise', 'kg', '40.00', '55.00', 'VATABLE', 'true', '12', 'true'], $rows[1]);
        $this->assertSame(['B-2', '', 'Inactive item', '', '', '', $rows[2][6], '', '9.50', 'NON_VAT', 'false', '0', 'false'], $rows[2]);
    }

    public function test_any_signed_in_user_can_export(): void
    {
        $cashier = User::factory()->create();
        $this->product($cashier, ['sku' => 'X-1']);

        $this->assertStringContainsString('X-1', $this->exportCsv($cashier));
    }

    public function test_the_export_rejects_an_unauthenticated_request(): void
    {
        $this->getJson('/api/v1/products/export')->assertStatus(401);
    }

    public function test_the_export_neutralises_text_a_spreadsheet_would_run_as_a_formula(): void
    {
        $admin = User::factory()->admin()->create();
        $this->product($admin, ['sku' => '-5', 'name' => '=HYPERLINK("http://x")', 'description' => '+1', 'barcode' => null]);
        $this->product($admin, ['sku' => 'S-2', 'name' => "'=already quoted", 'description' => '@sum', 'barcode' => null]);
        $this->product($admin, ['sku' => 'S-3', 'name' => "'plain", 'description' => '-', 'barcode' => null]);

        $rows = $this->rowsOf($this->exportCsv($admin));
        $bySku = collect($rows)->skip(1)->keyBy(0);

        $this->assertSame("'-5", $bySku["'-5"][0]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $bySku["'-5"][2]);
        $this->assertSame("'+1", $bySku["'-5"][3]);
        $this->assertSame("''=already quoted", $bySku['S-2'][2]);
        $this->assertSame("'@sum", $bySku['S-2'][3]);
        $this->assertSame("'plain", $bySku['S-3'][2]);
        $this->assertSame("'-", $bySku['S-3'][3]);
    }

    public function test_the_formula_guard_reverses_exactly(): void
    {
        foreach (['=1+1', '+x', '-y', '@z', "'=q", "''=q", "'plain", 'plain', '', '-'] as $value) {
            $this->assertSame($value, ProductCsv::unguard(ProductCsv::guard($value)), "round trip of [{$value}]");
        }
    }

    // ---------------------------------------------------------------- round trip

    public function test_an_exported_catalog_imports_straight_back_with_nothing_changed(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id, 'name' => 'Grocery']);
        $this->product($admin, ['sku' => 'A-1', 'name' => '=danger', 'description' => "Line one\nLine two, with comma", 'category_id' => $category->id]);
        $this->product($admin, ['sku' => 'B-2', 'barcode' => null, 'cost' => null, 'active' => false, 'track_inventory' => false]);
        $before = Product::where('store_id', $admin->store_id)->orderBy('sku')->get()->map->getAttributes()->all();

        $result = $this->resultOf($this->importCsv($admin, $this->exportCsv($admin)));

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'failed' => 0, 'errors' => []], $result);
        $this->assertEquals($before, Product::where('store_id', $admin->store_id)->orderBy('sku')->get()->map->getAttributes()->all());
    }

    // ---------------------------------------------------------------- import: create and update

    public function test_an_import_creates_new_products_and_updates_existing_ones(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id, 'name' => 'Grocery']);
        $brand = Brand::factory()->create(['store_id' => $admin->store_id, 'name' => 'Sunrise']);
        $existing = $this->product($admin, ['sku' => 'OLD-1', 'name' => 'Old name', 'selling_price' => '10.00']);

        $csv = self::HEADER."\r\n".
            "NEW-1,4800016001234,\"Rice, 1kg\",Long grain,grocery,SUNRISE,kg,40,55.5,vatable,true,5,true\r\n".
            "OLD-1,{$existing->barcode},New name,,,,{$existing->unit_of_measure},,12.00,vat-exempt,,,\r\n";

        $result = $this->resultOf($this->importCsv($admin, $csv));

        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'failed' => 0, 'errors' => []], $result);

        $new = Product::where('store_id', $admin->store_id)->where('sku', 'NEW-1')->firstOrFail();
        $this->assertSame('Rice, 1kg', $new->name);
        $this->assertSame('4800016001234', $new->barcode);
        $this->assertSame($category->id, $new->category_id);
        $this->assertSame($brand->id, $new->brand_id);
        $this->assertSame('40.00', $new->cost);
        $this->assertSame('55.50', $new->selling_price);
        $this->assertSame('VATABLE', $new->tax_class);
        $this->assertTrue($new->track_inventory);
        $this->assertSame(5, $new->reorder_level);
        $this->assertTrue($new->active);

        $existing->refresh();
        $this->assertSame('New name', $existing->name);
        $this->assertSame('12.00', $existing->selling_price);
        $this->assertSame('VAT_EXEMPT', $existing->tax_class);
        $this->assertNull($existing->cost, 'a blank cost clears it');
        $this->assertTrue($existing->active, 'a blank active leaves it alone');
    }

    public function test_a_file_with_only_some_columns_changes_only_those_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin, ['sku' => 'P-1', 'name' => 'Keep me', 'selling_price' => '10.00', 'cost' => '4.00', 'reorder_level' => 7]);

        $result = $this->resultOf($this->importCsv($admin, "sku,selling_price\nP-1,15\n"));

        $this->assertSame(1, $result['updated']);
        $product->refresh();
        $this->assertSame('15.00', $product->selling_price);
        $this->assertSame('Keep me', $product->name);
        $this->assertSame('4.00', $product->cost);
        $this->assertSame(7, $product->reorder_level);
    }

    public function test_a_new_sku_needs_the_columns_that_create_a_product(): void
    {
        $admin = User::factory()->admin()->create();

        $result = $this->resultOf($this->importCsv($admin, "sku,selling_price\nNEW-1,15.00\n"));

        $this->assertSame(1, $result['failed']);
        $this->assertSame(2, $result['errors'][0]['row']);
        $this->assertStringContainsString('"name" column', $result['errors'][0]['message']);
        $this->assertSame(0, Product::where('store_id', $admin->store_id)->count());
    }

    public function test_active_can_deactivate_and_reactivate_and_a_blank_leaves_it(): void
    {
        $admin = User::factory()->admin()->create();
        $on = $this->product($admin, ['sku' => 'ON', 'active' => true]);
        $off = $this->product($admin, ['sku' => 'OFF', 'active' => false]);
        $same = $this->product($admin, ['sku' => 'SAME', 'active' => true]);

        $result = $this->resultOf($this->importCsv($admin, "sku,active\nON,false\nOFF,yes\nSAME,\n"));

        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertFalse($on->refresh()->active);
        $this->assertTrue($off->refresh()->active);
        $this->assertTrue($same->refresh()->active);
    }

    public function test_a_blank_optional_cell_clears_the_field_and_a_blank_required_cell_is_an_error(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::factory()->create(['store_id' => $admin->store_id]);
        $product = $this->product($admin, ['sku' => 'P-1', 'description' => 'Something', 'category_id' => $category->id]);
        $other = $this->product($admin, ['sku' => 'P-2', 'name' => 'Untouched']);

        $result = $this->resultOf($this->importCsv($admin, "sku,barcode,name,description,category\nP-1,,Renamed,,\nP-2,,,,\n"));

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([['row' => 3, 'message' => 'The name is blank.']], $result['errors']);
        $product->refresh();
        $this->assertNull($product->barcode);
        $this->assertNull($product->description);
        $this->assertNull($product->category_id);
        $this->assertSame('Renamed', $product->name);
        $this->assertSame('Untouched', $other->refresh()->name);
    }

    public function test_a_byte_order_mark_a_missing_final_newline_blank_rows_and_empty_headings_are_tolerated(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = "\xEF\xBB\xBFSKU , Name,unit_of_measure,selling_price,tax_class,\n\n,,,,,\nA-1,Alpha,pc,1.00,VATABLE,ignored\n\nB-2,Beta,pc,2.00,VATABLE,";
        $result = $this->resultOf($this->importCsv($admin, $csv));

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'errors' => []], $result);
    }

    // ---------------------------------------------------------------- import: row errors

    public function test_good_rows_are_applied_when_others_fail_and_each_error_names_its_row(): void
    {
        $admin = User::factory()->admin()->create();

        $csv = "sku,name,unit_of_measure,selling_price,tax_class\n".
            "GOOD-1,One,pc,1.00,VATABLE\n".
            "BAD-PRICE,Two,pc,1.005,VATABLE\n".
            "\n".
            "BAD-TAX,Three,pc,3.00,GST\n".
            "GOOD-2,Four,pc,4.00,NON_VAT\n".
            "GOOD-1,Five,pc,5.00,VATABLE\n".
            "SHORT,Six,pc\n";

        $result = $this->resultOf($this->importCsv($admin, $csv));

        $this->assertSame(2, $result['created']);
        $this->assertSame(4, $result['failed']);
        $this->assertEquals([
            ['row' => 3, 'message' => 'The selling price "1.005" is not a valid amount. Use digits with up to two decimal places, such as 55.00.'],
            ['row' => 5, 'message' => 'The tax class "GST" is not one of VATABLE, VAT_EXEMPT, ZERO_RATED, NON_VAT.'],
            ['row' => 7, 'message' => 'The SKU GOOD-1 already appears on row 2.'],
            ['row' => 8, 'message' => 'Expected 5 values but found 3. Check for a stray comma or an unclosed quote.'],
        ], $result['errors']);
        $this->assertEqualsCanonicalizing(['GOOD-1', 'GOOD-2'], Product::where('store_id', $admin->store_id)->pluck('sku')->all());
        $this->assertSame('One', Product::where('store_id', $admin->store_id)->where('sku', 'GOOD-1')->value('name'));
    }

    public function test_every_problem_in_a_row_is_reported_together(): void
    {
        $admin = User::factory()->admin()->create();

        $result = $this->resultOf($this->importCsv($admin, "sku,name,unit_of_measure,cost,selling_price,tax_class,reorder_level,active\nP-1,,pc,-1,abc,X,-3,maybe\n"));

        $this->assertSame(1, $result['failed']);
        $message = $result['errors'][0]['message'];
        foreach (['The name is blank.', 'The cost "-1"', 'The selling price "abc"', 'The tax class "X"', 'The reorder level "-3"', 'The active value "maybe"'] as $fragment) {
            $this->assertStringContainsString($fragment, $message);
        }
    }

    public function test_barcodes_may_not_collide_with_other_products_alternates_or_earlier_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $taken = $this->product($admin, ['sku' => 'TAKEN', 'barcode' => '1111111111111']);
        $withAlternate = $this->product($admin, ['sku' => 'ALT', 'barcode' => '2222222222222']);
        ProductBarcode::create(['product_id' => $withAlternate->id, 'store_id' => $admin->store_id, 'barcode' => '3333333333333', 'is_primary' => false]);

        $csv = "sku,barcode,name,unit_of_measure,selling_price,tax_class\n".
            "N-1,1111111111111,A,pc,1.00,VATABLE\n".
            "N-2,3333333333333,B,pc,1.00,VATABLE\n".
            "N-3,4444444444444,C,pc,1.00,VATABLE\n".
            "N-4,4444444444444,D,pc,1.00,VATABLE\n".
            "TAKEN,1111111111111,Same barcode,pc,1.00,VATABLE\n".
            "ALT,3333333333333,Own alternate,pc,1.00,VATABLE\n";

        $result = $this->resultOf($this->importCsv($admin, $csv));

        $this->assertSame([2, 3, 5], array_column($result['errors'], 'row'));
        $this->assertStringContainsString('already assigned to another product', $result['errors'][0]['message']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, $result['updated'] + $result['unchanged']);
        $this->assertSame('N-3', Product::where('store_id', $admin->store_id)->where('barcode', '4444444444444')->value('sku'));
        $this->assertSame('1111111111111', $taken->refresh()->barcode);
    }

    public function test_a_barcode_may_move_to_another_product_once_it_is_free(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->product($admin, ['sku' => 'A', 'barcode' => '5555555555555']);
        $b = $this->product($admin, ['sku' => 'B', 'barcode' => '6666666666666']);

        $result = $this->resultOf($this->importCsv($admin, "sku,barcode\nA,\nB,5555555555555\n"));

        $this->assertSame(['updated' => 2, 'failed' => 0], ['updated' => $result['updated'], 'failed' => $result['failed']]);
        $this->assertNull($a->refresh()->barcode);
        $this->assertSame('5555555555555', $b->refresh()->barcode);
    }

    public function test_a_barcode_that_excel_turned_into_scientific_notation_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $result = $this->resultOf($this->importCsv($admin, "sku,barcode,name,unit_of_measure,selling_price,tax_class\nP-1,4.8E+12,Thing,pc,1.00,VATABLE\n"));

        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('scientific notation', $result['errors'][0]['message']);
    }

    public function test_categories_and_brands_match_by_name_and_are_never_created(): void
    {
        $admin = User::factory()->admin()->create();
        Category::factory()->create(['store_id' => User::factory()->admin()->create()->store_id, 'name' => 'Elsewhere']);
        Category::factory()->create(['store_id' => $admin->store_id, 'name' => 'Twin']);
        Category::factory()->create(['store_id' => $admin->store_id, 'name' => 'twin']);
        $categoriesBefore = Category::count();
        $brandsBefore = Brand::count();

        $csv = "sku,name,unit_of_measure,selling_price,tax_class,category,brand\n".
            "P-1,A,pc,1.00,VATABLE,Missing,\n".
            "P-2,B,pc,1.00,VATABLE,Elsewhere,\n".
            "P-3,C,pc,1.00,VATABLE,TWIN,\n".
            "P-4,D,pc,1.00,VATABLE,,No such brand\n";

        $result = $this->resultOf($this->importCsv($admin, $csv));

        $this->assertSame(4, $result['failed']);
        $this->assertStringContainsString('There is no category named "Missing"', $result['errors'][0]['message']);
        $this->assertStringContainsString('There is no category named "Elsewhere"', $result['errors'][1]['message']);
        $this->assertStringContainsString('more than one category named "TWIN"', $result['errors'][2]['message']);
        $this->assertStringContainsString('There is no brand named "No such brand"', $result['errors'][3]['message']);
        $this->assertSame($categoriesBefore, Category::count());
        $this->assertSame($brandsBefore, Brand::count());
    }

    public function test_the_import_only_ever_touches_the_actors_own_store(): void
    {
        $admin = User::factory()->admin()->create();
        $theirs = $this->product(User::factory()->admin()->create(), ['sku' => 'SHARED', 'name' => 'Theirs', 'barcode' => '7777777777777']);

        $result = $this->resultOf($this->importCsv($admin, "sku,barcode,name,unit_of_measure,selling_price,tax_class\nSHARED,7777777777777,Mine,pc,1.00,VATABLE\n"));

        $this->assertSame(1, $result['created'], 'the same SKU and barcode may exist in two stores');
        $this->assertSame('Theirs', $theirs->refresh()->name);
        $this->assertSame(1, Product::where('store_id', $admin->store_id)->count());
    }

    // ---------------------------------------------------------------- dry run

    public function test_a_dry_run_reports_what_an_import_would_do_and_changes_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin, ['sku' => 'P-1', 'selling_price' => '10.00']);
        $csv = "sku,name,unit_of_measure,selling_price,tax_class\nP-1,Renamed,pc,99.00,VATABLE\nNEW-1,Fresh,pc,1.00,VATABLE\nBAD,,pc,1.00,VATABLE\n";

        $dry = $this->resultOf($this->importCsv($admin, $csv, dryRun: true));

        $this->assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0, 'failed' => 1], array_diff_key($dry, ['errors' => 0]));
        $this->assertSame('10.00', $product->refresh()->selling_price);
        $this->assertSame(0, Product::where('store_id', $admin->store_id)->where('sku', 'NEW-1')->count());

        $real = $this->resultOf($this->importCsv($admin, $csv));
        $this->assertSame($dry, $real);
        $this->assertSame('99.00', $product->refresh()->selling_price);
        $this->assertSame(1, Product::where('store_id', $admin->store_id)->where('sku', 'NEW-1')->count());
    }

    // ---------------------------------------------------------------- import: the file as a whole

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableFiles(): array
    {
        return [
            'empty' => ['', 'The file is empty'],
            'only blank lines' => ["\n\n  \n", 'The file is empty'],
            'no sku column' => ["name,selling_price\nRice,1.00\n", 'includes a "sku" column'],
            'semicolon separated' => ["sku;name;selling_price\nA;Rice;1.00\n", 'includes a "sku" column'],
            'unknown column' => ["sku,name,sellingprice\nA,Rice,1.00\n", 'Unrecognised column: sellingprice'],
            'repeated column' => ["sku,name,name\nA,x,y\n", 'lists the same column twice: name'],
            'not utf-8' => ["sku,name\nA,Ni\xF1o\n", 'not UTF-8'],
        ];
    }

    #[DataProvider('unusableFiles')]
    public function test_a_file_that_cannot_be_read_as_a_catalog_is_rejected_whole(string $csv, string $fragment): void
    {
        $admin = User::factory()->admin()->create();
        $this->product($admin, ['sku' => 'KEEP']);

        $response = $this->importCsv($admin, $csv);

        $response->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertStringContainsString($fragment, $response->json('error.details.file.0'));
        $this->assertSame(1, Product::where('store_id', $admin->store_id)->count());
    }

    public function test_a_file_over_the_row_limit_is_rejected_before_anything_is_applied(): void
    {
        $admin = User::factory()->admin()->create();
        $lines = ['sku,name,unit_of_measure,selling_price,tax_class'];
        for ($i = 1; $i <= ProductImportService::MAX_ROWS + 1; $i++) {
            $lines[] = "R-{$i},Item,pc,1.00,VATABLE";
        }

        $response = $this->importCsv($admin, implode("\n", $lines));

        $response->assertStatus(422);
        $this->assertStringContainsString('more than '.ProductImportService::MAX_ROWS.' products', $response->json('error.details.file.0'));
        $this->assertSame(0, Product::where('store_id', $admin->store_id)->count());
    }

    public function test_a_file_at_the_row_limit_imports(): void
    {
        $admin = User::factory()->admin()->create();
        $lines = ['sku,name,unit_of_measure,selling_price,tax_class'];
        for ($i = 1; $i <= ProductImportService::MAX_ROWS; $i++) {
            $lines[] = "R-{$i},Item,pc,1.00,VATABLE";
        }

        $result = $this->resultOf($this->importCsv($admin, implode("\n", $lines)));

        $this->assertSame(ProductImportService::MAX_ROWS, $result['created']);
    }

    // ---------------------------------------------------------------- authorization

    public function test_only_catalog_managers_can_import(): void
    {
        $csv = "sku,name,unit_of_measure,selling_price,tax_class\nA,Rice,pc,1.00,VATABLE\n";

        $this->importCsv(User::factory()->create(), $csv)->assertStatus(403)->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
        $this->assertSame(1, $this->resultOf($this->importCsv(User::factory()->manager()->create(), $csv))['created']);
    }

    public function test_an_unauthenticated_import_is_rejected(): void
    {
        $this->call('POST', '/api/v1/products/import', [], [], [], ['CONTENT_TYPE' => 'text/csv', 'HTTP_ACCEPT' => 'application/json'], "sku\nA\n")->assertStatus(401);
    }

    public function test_the_literal_paths_do_not_collide_with_fetching_a_product_by_id(): void
    {
        $admin = User::factory()->admin()->create();
        $product = $this->product($admin);

        $this->asUser($admin)->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJson(['id' => $product->id]);
    }
}
