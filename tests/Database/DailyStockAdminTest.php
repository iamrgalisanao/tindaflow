<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Services\Menu\DishAlaCarteService;
use App\Services\Menu\DishService;
use Carbon\CarbonImmutable;
use Database\Seeders\MealMenuSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsDailyStockScenario;

/**
 * ADR-017 slice B, invariants.md STK-002, STK-004 and STK-008 (client decisions 2, 6, 7 and 8): the configuration around daily stock. `stock_mode` kept equal
 * to `track_inventory`, a dish's hidden pool, the "Create ala carte items" action, the daily leftover and waste report with its CSV, the manager switching
 * the stock rules and the cutover hour, the running views leaving daily items out, the rebuild command covering the daily cache, and the canteen loader's one-time
 * defaults.
 */
class DailyStockAdminTest extends PostgresSchemaTestCase
{
    use BuildsDailyStockScenario;

    private const TODAY = '2026-10-27';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000, 'tindaflow.credit_override.throttle_attempts' => 1000]);
        $this->at('2026-10-27 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $manila): void
    {
        $instant = CarbonImmutable::parse($manila, 'Asia/Manila');
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function canteen(): array
    {
        $w = $this->world();

        return [$w, $this->dailyMenu($w)];
    }

    // ------------------------------------------------------------------ stock_mode and track_inventory

    public function test_stock_mode_and_track_inventory_are_kept_equal_by_the_model_and_the_database(): void
    {
        $store = Store::factory()->create();

        $tracked = Product::factory()->create(['store_id' => $store->id, 'track_inventory' => true]);
        $untracked = Product::factory()->create(['store_id' => $store->id, 'track_inventory' => false]);
        $daily = Product::factory()->create(['store_id' => $store->id, 'stock_mode' => 'DAILY']);
        $this->assertSame(['RUNNING', 'NONE', 'DAILY'], [$tracked->fresh()->stock_mode, $untracked->fresh()->stock_mode, $daily->fresh()->stock_mode]);
        $this->assertTrue($daily->fresh()->track_inventory);

        $tracked->update(['track_inventory' => false]);
        $this->assertSame('NONE', $tracked->fresh()->stock_mode);
        $tracked->update(['track_inventory' => true]);
        $this->assertSame('RUNNING', $tracked->fresh()->stock_mode);
        $daily->update(['stock_mode' => 'NONE']);
        $this->assertFalse($daily->fresh()->track_inventory);

        // The database refuses a row that says two things.
        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $tracked->id)->update(['stock_mode' => 'NONE']);
    }

    public function test_the_migration_backfills_running_for_tracked_products_and_none_for_the_rest(): void
    {
        $store = Store::factory()->create();
        $tracked = Product::factory()->create(['store_id' => $store->id, 'track_inventory' => true]);
        $untracked = Product::factory()->create(['store_id' => $store->id, 'track_inventory' => false]);
        $migration = require database_path('migrations/2026_10_27_100000_add_stock_mode_and_dish_link_to_products.php');

        // Back to the shape before slice B (no stock_mode), then forward again: the backfill reads track_inventory.
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('products', 'stock_mode'));
        $migration->up();

        $this->assertSame(['RUNNING', 'NONE'], [DB::table('products')->where('id', $tracked->id)->value('stock_mode'), DB::table('products')->where('id', $untracked->id)->value('stock_mode')]);
    }

    public function test_a_product_changes_stock_mode_only_at_a_zero_position(): void
    {
        $w = $this->world();
        $cola = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '30.00']);
        app(StockLedger::class)->record(['product_id' => $cola->id, 'location_id' => $w['location']->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => '4', 'created_by' => $w['admin']->id]);
        $change = fn (string $mode) => $this->asUser($w['admin'])->patchJson("/api/v1/products/{$cola->id}", $this->productBody($cola, ['stock_mode' => $mode]));

        $change('DAILY')->assertStatus(409)->assertJsonPath('error.code', 'STOCK_MODE_CHANGE_BLOCKED')->assertJsonPath('error.details.position', '4.000');
        $change('NONE')->assertStatus(409)->assertJsonPath('error.code', 'STOCK_MODE_CHANGE_BLOCKED');
        $this->assertSame('RUNNING', $cola->fresh()->stock_mode);

        app(StockLedger::class)->record(['product_id' => $cola->id, 'location_id' => $w['location']->id, 'movement_type' => 'DAMAGE', 'quantity' => '4', 'reason' => 'gone', 'created_by' => $w['admin']->id]);
        $change('DAILY')->assertOk()->assertJsonPath('stock_mode', 'DAILY')->assertJsonPath('track_inventory', true);
        $this->assertSame('DAILY', $cola->fresh()->stock_mode);
        $change('NONE')->assertOk()->assertJsonPath('track_inventory', false);
    }

    /** @return array<string, mixed> */
    private function productBody(Product $product, array $override = []): array
    {
        return $override + [
            'sku' => $product->sku, 'name' => $product->name, 'unit_of_measure' => $product->unit_of_measure, 'selling_price' => (string) $product->selling_price,
            'tax_class' => $product->tax_class, 'barcode' => $product->barcode, 'cost' => null,
        ];
    }

    public function test_a_daily_product_cannot_be_received_counted_or_transferred_as_running_stock(): void
    {
        $w = $this->world();
        $siopao = Product::factory()->create(['store_id' => $w['storeId'], 'stock_mode' => 'DAILY']);

        $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/inventory/receipts', ['product_id' => $siopao->id, 'quantity' => '5', 'movement_type' => 'PURCHASE_RECEIPT'], $this->key())
            ->assertStatus(422);
        $this->assertSame(0, StockMovement::where('product_id', $siopao->id)->count());
    }

    // ------------------------------------------------------------------ a dish's hidden pool

    public function test_a_dish_gets_one_hidden_pool_product_that_follows_its_name_and_cannot_be_switched_off_while_it_holds_portions(): void
    {
        [$w, $m] = $this->canteen();
        $pool = Product::findOrFail($m['nilaga']->fresh()->stock_product_id);

        $this->assertSame([true, false, true, 'DAILY'], [$pool->menu_hidden, $pool->active, $pool->track_inventory, $pool->stock_mode]);
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['nilaga']->id}", ['name' => 'Beef nilaga special'])->assertOk()->assertJsonPath('stock_mode', 'DAILY');
        $this->assertSame('Beef nilaga special', $pool->fresh()->name);

        // Switching it off and on again reuses the same pool.
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['adobo']->id}", ['stock_mode' => 'NONE'])->assertOk();
        $poolId = $m['adobo']->fresh()->stock_product_id;
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['adobo']->id}", ['stock_mode' => 'DAILY'])->assertOk();
        $this->assertSame($poolId, $m['adobo']->fresh()->stock_product_id);

        $this->enterCooked($w, [$m['adobo']->id => '3'])->assertStatus(201);
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['adobo']->id}", ['stock_mode' => 'NONE'])->assertStatus(409)
            ->assertJsonPath('error.code', 'STOCK_MODE_CHANGE_BLOCKED')->assertJsonPath('error.details.position', '3.000');
        // An ala carte item that still draws from the dish also holds it.
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['nilaga']->id}", ['stock_mode' => 'NONE'])->assertStatus(409);
    }

    public function test_the_hidden_pools_never_leak_into_the_running_views_the_product_list_or_the_preflight(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '3'])->assertStatus(201);
        $plain = Product::factory()->create(['store_id' => $w['storeId'], 'sku' => 'COLA-1', 'name' => 'Cola', 'reorder_level' => null]);
        $siopao = Product::factory()->create(['store_id' => $w['storeId'], 'sku' => 'SIO-1', 'name' => 'Siopao', 'stock_mode' => 'DAILY']);
        app(StockLedger::class)->record(['product_id' => $plain->id, 'location_id' => $w['location']->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => '2', 'created_by' => $w['admin']->id]);
        $poolName = Product::findOrFail($m['nilaga']->fresh()->stock_product_id)->name;
        $admin = fn () => $this->asUser($w['admin']);

        $stockRows = collect($admin()->getJson('/api/v1/inventory/stock')->assertOk()->json('data'));
        $this->assertSame([$plain->id], $stockRows->pluck('product_id')->all(), 'only running stock');
        $this->assertSame([$plain->id], collect($admin()->getJson('/api/v1/inventory/low-stock')->json('data'))->pluck('product_id')->all());
        $this->assertNotContains($poolName.'-', collect($admin()->getJson('/api/v1/products?per_page=100')->json('data'))->pluck('sku')->all());
        $this->assertNotContains('DISH-'.strtoupper(str_replace(' ', '-', $poolName)), collect($admin()->getJson('/api/v1/products?per_page=100')->json('data'))->pluck('sku')->all());
        $onHand = collect($admin()->getJson('/api/v1/reports/inventory-on-hand')->json('rows'));
        $this->assertSame([$plain->id], $onHand->pluck('product_id')->all());
        $this->assertSame([$plain->id], collect($admin()->getJson('/api/v1/reports/low-stock')->json('rows'))->pluck('product_id')->all());
        $this->assertStringNotContainsString('DISH-', $admin()->get('/api/v1/products/export', ['Accept' => 'text/csv'])->streamedContent());

        // Blocking's pre-flight counts running products only: a daily item is entered each morning, it is not "out of stock" the day before.
        $rules = $admin()->getJson('/api/v1/stock-rules')->assertOk()->json('preflight');
        $names = array_column($rules['sample'], 'sku');
        $this->assertNotContains($siopao->sku, $names);
        $this->assertNotContains('SIO-1', $names);
    }

    // ------------------------------------------------------------------ the ala carte action

    public function test_the_action_creates_one_ala_carte_product_per_daily_dish_idempotently_and_audited(): void
    {
        [$w, $m] = $this->canteen();
        $group = $m['alaGroup'];
        $body = [
            'menu_group_id' => $group->id,
            'category_prices' => [$m['beef']->id => '120.00'],
            'items' => [['dish_id' => $m['adobo']->id], ['dish_id' => $m['nilaga']->id, 'price' => '999.00']],
        ];

        $first = $this->asUser($w['admin'])->postJson('/api/v1/dishes/ala-carte', $body)->assertStatus(201);

        // Beef nilaga already has its item (the scenario made one): skipped. Beef adobo is new, priced by its category.
        $this->assertSame(['ALREADY_EXISTS'], array_column($first->json('skipped'), 'reason'));
        $this->assertCount(1, $first->json('created'));
        $product = Product::findOrFail($first->json('created.0.product_id'));
        $this->assertSame(['Beef adobo (ala carte)', 'ALC-BEEF-ADOBO', '120.00', '2.000', 'DAILY', $m['adobo']->id, $group->id, true, 'order'],
            [$product->name, $product->sku, (string) $product->selling_price, (string) $product->stock_portion, $product->stock_mode, $product->dish_id, $product->menu_group_id, $product->active, $product->unit_of_measure]);
        $this->assertTrue($product->track_inventory);
        $this->assertNull($product->cost);

        $audit = AuditEvent::where('event_type', 'ALA_CARTE_ITEMS_CREATED')->get()->first(fn ($event) => ($event->after_metadata['created'][0]['product_id'] ?? null) === $product->id);
        $this->assertSame($w['admin']->id, $audit->actor_user_id);
        $this->assertSame($product->id, $audit->after_metadata['created'][0]['product_id']);
        $this->assertSame(1, AuditEvent::where('event_type', 'PRODUCT_CREATED')->where('entity_id', $product->id)->count());

        // Running it again changes nothing.
        $count = Product::count();
        $again = $this->asUser($w['admin'])->postJson('/api/v1/dishes/ala-carte', $body)->assertStatus(201);
        $this->assertSame([], $again->json('created'));
        $this->assertSame($count, Product::count());

        // It is an ordinary visible product on the till grid, sold at 2.0 portions.
        $this->assertContains($product->id, collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/products?active=1&per_page=100')->json('data'))->pluck('id')->all());
    }

    public function test_the_action_needs_a_price_skips_dishes_without_daily_stock_makes_unique_skus_and_is_for_catalog_managers(): void
    {
        [$w, $m] = $this->canteen();
        $notDaily = app(DishService::class)->create($w['admin'], ['name' => 'Beef adobo!', 'dish_category_id' => $m['beef']->id]);
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['adobo']->id}", ['stock_mode' => 'NONE'])->assertOk();

        // No price anywhere for a daily dish that has no item yet: refused, nothing is created.
        $fresh = app(DishService::class)->create($w['admin'], ['name' => 'Beef sinigang', 'dish_category_id' => $m['beef']->id]);
        app(DishService::class)->update($w['admin'], $fresh->id, ['stock_mode' => 'DAILY']);
        $this->asUser($w['admin'])->postJson('/api/v1/dishes/ala-carte', ['items' => [['dish_id' => $fresh->id]]])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['items.0.price']]]);
        $this->assertSame(0, Product::where('dish_id', $fresh->id)->count());

        $skipped = $this->asUser($w['admin'])->postJson('/api/v1/dishes/ala-carte', ['items' => [['dish_id' => $notDaily->id, 'price' => '100.00']]])->assertStatus(201)->json('skipped');
        $this->assertSame('DISH_NOT_DAILY', $skipped[0]['reason']);

        // Two dishes whose names make the same code get -2.
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$notDaily->id}", ['stock_mode' => 'DAILY'])->assertOk();
        $this->asUser($w['admin'])->patchJson("/api/v1/dishes/{$m['adobo']->id}", ['stock_mode' => 'DAILY'])->assertOk();
        $made = $this->asUser($w['admin'])->postJson('/api/v1/dishes/ala-carte', ['items' => [['dish_id' => $m['adobo']->id, 'price' => '100.00'], ['dish_id' => $notDaily->id, 'price' => '100.00', 'name' => 'Adobo plate']]])->assertStatus(201)->json('created');
        $this->assertSame(['ALC-BEEF-ADOBO', 'ALC-BEEF-ADOBO-2'], array_column($made, 'sku'));
        $this->assertSame('Adobo plate', $made[1]['name']);

        $this->asUser($w['cashier'])->postJson('/api/v1/dishes/ala-carte', ['items' => [['dish_id' => $m['adobo']->id, 'price' => '1.00']]])->assertStatus(403);
        $this->asUser($w['manager'])->postJson('/api/v1/dishes/ala-carte', ['items' => [['dish_id' => $m['adobo']->id, 'price' => '1.00']]])->assertStatus(201);
    }

    // ------------------------------------------------------------------ the leftover and waste report

    public function test_the_daily_report_reconciles_with_the_ledger_for_a_scripted_day_and_flags_oversold(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '20', $m['pinakbet']->id => '2'])->assertStatus(201);
        $this->enterCooked($w, [$m['nilaga']->id => '18'], ['reason' => 'Two burnt'])->assertStatus(201);
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption']), $this->alaItem($m['alaNilaga'])])->assertStatus(201); // 3 of nilaga
        $this->sellItems($w, [$this->comboItem($m), $this->alaItem($m['alaPinakbet'], '1')])->assertStatus(201);   // 1 nilaga, 1 + 2 pinakbet = 3 of 2 -> oversold
        $sale = $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(201)->json(); // 2 more
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Walked out'], $this->key())->assertStatus(201);

        $report = $this->asUser($w['admin'])->getJson('/api/v1/reports/daily-stock?from='.self::TODAY.'&to='.self::TODAY)->assertOk();
        $rows = collect($report->json('rows'))->keyBy('item_name');

        $nilaga = $rows['Beef nilaga'];
        $this->assertSame(['DISH', 'Beef', '20.000', '2.000', '6.000', '0.000'], [$nilaga['item_type'], $nilaga['category'], $nilaga['cooked'], $nilaga['removed'], $nilaga['sold'], $nilaga['returned']]);
        $this->assertSame('12.000', $nilaga['leftover'], '20 - 2 burnt - (1+2+1+2) sold: the voided order was not put back');
        $this->assertSame('false', $nilaga['oversold']);
        $pinakbet = $rows['Pinakbet'];
        $this->assertSame(['2.000', '3.000', '-1.000', 'true'], [$pinakbet['cooked'], $pinakbet['sold'], $pinakbet['leftover'], $pinakbet['oversold']]);
        $this->assertSame(1, $report->json('summary.oversold_count'));

        // The report equals the ledger and the cache.
        $ledger = DB::table('stock_movements')->where('product_id', $m['nilaga']->fresh()->stock_product_id)->selectRaw("SUM(CASE WHEN movement_type IN ('SALE','DAILY_PORTIONS_REMOVED') THEN -quantity ELSE quantity END) AS net")->value('net');
        $this->assertSame($nilaga['leftover'], bcadd((string) $ledger, '0', 3));
    }

    public function test_the_report_spans_days_and_a_sale_before_the_cutover_lands_on_the_earlier_day(): void
    {
        [$w, $m] = $this->canteen();
        $this->stockRules($w, ['stock_day_cutover_hour' => 5]);
        $this->enterCooked($w, [$m['nilaga']->id => '10'])->assertStatus(201);
        $this->at('2026-10-28 04:30:00');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);
        $this->at('2026-10-28 09:00:00');
        $this->enterCooked($w, [$m['nilaga']->id => '8'])->assertStatus(201);

        $rows = $this->asUser($w['admin'])->getJson('/api/v1/reports/daily-stock?from=2026-10-27&to=2026-10-28')->assertOk()->json('rows');
        $byDate = collect($rows)->where('item_name', 'Beef nilaga')->keyBy('stock_date');

        $this->assertSame(['10.000', '1.000', '9.000'], [$byDate['2026-10-27']['cooked'], $byDate['2026-10-27']['sold'], $byDate['2026-10-27']['leftover']]);
        $this->assertSame(['8.000', '0.000', '8.000'], [$byDate['2026-10-28']['cooked'], $byDate['2026-10-28']['sold'], $byDate['2026-10-28']['leftover']]);
        $this->assertSame(['2026-10-27', '2026-10-28'], collect($rows)->pluck('stock_date')->unique()->values()->all());
    }

    public function test_the_report_csv_has_the_contract_columns_and_the_report_is_for_report_viewers(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '4'])->assertStatus(201);

        $csv = $this->asUser($w['admin'])->get('/api/v1/reports/daily-stock?from='.self::TODAY.'&to='.self::TODAY, ['Accept' => 'text/csv']);
        $csv->assertOk();
        $lines = array_values(array_filter(explode("\n", $csv->getContent())));

        $this->assertSame('stock_date,item_type,item_name,category,cooked,removed,sold,returned,leftover,oversold', $lines[0]);
        $this->assertSame('2026-10-27,DISH,"Beef nilaga",Beef,4.000,0.000,0.000,0.000,4.000,false', $lines[1]);
        $this->asUser($w['cashier'])->getJson('/api/v1/reports/daily-stock')->assertStatus(403);
    }

    // ------------------------------------------------------------------ the stock rules, by the manager

    public function test_a_manager_sets_the_cutover_hour_and_it_is_audited_and_validated(): void
    {
        $w = $this->world();
        $body = ['block_sale_without_stock' => false, 'stock_override_enabled' => true, 'low_stock_default_threshold' => 3];

        $this->asUser($w['manager'])->putJson('/api/v1/stock-rules', $body + ['stock_day_cutover_hour' => 5])->assertOk()->assertJsonPath('stock_day_cutover_hour', 5);
        $event = AuditEvent::where('event_type', 'STOCK_RULES_CHANGED')->sole();
        $this->assertSame([0, 5], [$event->before_metadata['stock_day_cutover_hour'], $event->after_metadata['stock_day_cutover_hour']]);
        $this->assertSame($w['manager']->id, $event->actor_user_id);

        $this->asUser($w['manager'])->putJson('/api/v1/stock-rules', $body + ['stock_day_cutover_hour' => 7])->assertStatus(422);
        $this->asUser($w['cashier'])->putJson('/api/v1/stock-rules', $body + ['stock_day_cutover_hour' => 2])->assertStatus(403);
        // Left out, the stored hour is kept.
        $this->asUser($w['manager'])->putJson('/api/v1/stock-rules', $body)->assertOk()->assertJsonPath('stock_day_cutover_hour', 5);
    }

    // ------------------------------------------------------------------ the rebuild command

    public function test_the_rebuild_command_covers_the_daily_cache_too(): void
    {
        [$w, $m] = $this->canteen();
        $this->enterCooked($w, [$m['nilaga']->id => '10'])->assertStatus(201);
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);
        $pool = $m['nilaga']->fresh()->stock_product_id;

        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->expectsOutputToContain('already equals')->assertSuccessful();

        DB::table('daily_stock_balances')->where('product_id', $pool)->update(['quantity_on_hand' => '77.000']);
        $this->artisan('inventory:rebuild-balances', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('77.000', $this->left($m['nilaga']), 'a dry run changes nothing');
        $this->artisan('inventory:rebuild-balances')->assertSuccessful();
        $this->assertSame('9.000', $this->left($m['nilaga']));
        $this->artisan('inventory:rebuild-balances')->expectsOutputToContain('already equals')->assertSuccessful();
    }

    // ------------------------------------------------------------------ the canteen loader

    public function test_the_canteen_loader_sets_daily_dishes_portions_and_the_five_am_day_once_and_never_overwrites_a_managers_change(): void
    {
        $store = Store::factory()->create();
        User::factory()->admin()->create(['store_id' => $store->id]);

        $this->assertSame(0, Artisan::call('tindaflow:load-canteen-menu'), Artisan::output());

        $this->assertSame(5, (int) DB::table('store_settings')->where('store_id', $store->id)->value('stock_day_cutover_hour'));
        $this->assertSame(21, Dish::where('store_id', $store->id)->where('stock_mode', 'DAILY')->count());
        $this->assertSame(21, DB::table('products')->where('store_id', $store->id)->where('menu_hidden', true)->where('stock_mode', 'DAILY')->count());
        $portions = DB::table('meal_options')->whereNotNull('dish_id')->pluck('stock_portion')->map(fn ($p) => (string) $p)->unique()->all();
        $this->assertSame(['1.000'], $portions, 'Standard viand, Combo main and Combo side are all 1.0');
        $this->assertSame(0, Product::where('store_id', $store->id)->whereNotNull('dish_id')->count(), 'no ala carte item is invented: the prices are the manager\'s');

        // The manager changes the day, a portion and one dish; loading the menu again undoes none of it.
        DB::table('store_settings')->where('store_id', $store->id)->update(['stock_day_cutover_hour' => 0]);
        DB::table('meal_options')->whereNotNull('dish_id')->limit(1)->update(['stock_portion' => '2.500']);
        $dish = Dish::where('store_id', $store->id)->where('name', 'Laing')->firstOrFail();
        app(DishService::class)->update(User::where('store_id', $store->id)->firstOrFail(), $dish->id, ['stock_mode' => 'NONE']);

        $this->assertSame(0, Artisan::call('tindaflow:load-canteen-menu'));
        $this->assertSame(0, (int) DB::table('store_settings')->where('store_id', $store->id)->value('stock_day_cutover_hour'));
        $this->assertSame('NONE', $dish->fresh()->stock_mode);
        $this->assertSame(1, DB::table('meal_options')->where('stock_portion', '2.500')->count());
        $this->assertSame(20, Dish::where('store_id', $store->id)->where('stock_mode', 'DAILY')->count());
    }

    public function test_the_default_ala_carte_portion_is_two(): void
    {
        $this->assertSame('2.000', DishAlaCarteService::DEFAULT_PORTION);
        $this->assertSame('2.000', MealMenuSeeder::PORTIONS['ala_carte']);
    }
}
