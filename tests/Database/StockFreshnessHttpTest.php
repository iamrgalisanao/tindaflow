<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-017 slice C, invariants.md STK-006: shelf life is advisory. `check_freshness` is derived on read: a shelf life is set, stock is on hand and
 * the latest OPENING_STOCK or PURCHASE_RECEIPT is more than `shelf_life_days` Manila calendar days old. It never blocks a sale, never changes a
 * balance, and a newer receipt clears it (the accepted weakness: the system does not know which units are old).
 *
 * Every date is Manila (APP_TIMEZONE). The clock is moved only BACKWARDS from the real now so the signed-in session never looks expired.
 */
class StockFreshnessHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $manila): CarbonImmutable
    {
        return CarbonImmutable::parse($manila, 'Asia/Manila');
    }

    /** Puts stock on the shelf through the ledger as a movement that happened at $when. */
    private function move(array $w, Product $product, string $quantity, string $type, string $when): void
    {
        Carbon::setTestNow($this->at($when));
        CarbonImmutable::setTestNow($this->at($when));
        app(StockLedger::class)->record([
            'product_id' => $product->id, 'location_id' => $w['location']->id, 'movement_type' => $type, 'quantity' => $quantity,
            'reference_type' => 'test', 'reference_id' => (string) Str::uuid(), 'created_by' => $w['admin']->id, 'reason' => 'test',
        ]);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function now(string $manila): void
    {
        Carbon::setTestNow($this->at($manila));
        CarbonImmutable::setTestNow($this->at($manila));
    }

    /** @return array<string, mixed> */
    private function row(array $w, Product $product, string $view = 'stock'): array
    {
        $path = $view === 'low' ? 'low-stock' : 'stock';
        $rows = $this->asUser($w['manager'])->getJson("/api/v1/inventory/{$path}?product_id={$product->id}")->assertOk()->json('data');
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    /** A shelf-lived running product with `$quantity` received at `$received`, then the clock moved to `$checkedAt`; the session is opened first. */
    private function shelved(array $w, int $shelfLife, string $received, string $checkedAt, string $quantity = '5', string $type = 'PURCHASE_RECEIPT'): Product
    {
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => $shelfLife, 'reorder_level' => null]);
        $this->move($w, $product, $quantity, $type, $received);
        $this->now($checkedAt);

        return $product;
    }

    public function test_the_flag_turns_on_at_the_first_day_older_than_the_shelf_life_by_calendar_date(): void
    {
        $w = $this->world();
        // Received Monday 26 Oct, shelf life 2: Tuesday and Wednesday are fine, Thursday is not (3 days old). The hour of receipt does not matter.
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 2]);
        $this->move($w, $product, '5', 'PURCHASE_RECEIPT', '2026-10-26 00:01:00');

        $this->now('2026-10-28 23:59:59');
        $row = $this->row($w, $product);
        $this->assertFalse($row['check_freshness'], '2 calendar days old is within a 2 day shelf life, even at 23:59');
        $this->assertSame('2026-10-26', $row['last_received_on']);
        $this->assertNull($row['days_old'] ?? null);

        $this->now('2026-10-29 00:00:00');
        $row = $this->row($w, $product);
        $this->assertTrue($row['check_freshness'], '3 calendar days old is more than 2');
        $this->assertSame(3, $row['days_old']);
        $this->assertSame(2, $row['shelf_life_days']);
        $this->assertSame('2026-10-26', $row['last_received_on']);
    }

    public function test_a_late_evening_receipt_counts_by_its_manila_date_not_by_24_hours(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 1, '2026-10-26 23:30:00', '2026-10-27 23:59:00'); // 24h28m later but one calendar day

        $this->assertFalse($this->row($w, $product)['check_freshness']);
        $this->now('2026-10-28 00:00:00'); // 24h30m later but two calendar days
        $this->assertTrue($this->row($w, $product)['check_freshness']);
    }

    public function test_stock_of_zero_is_never_flagged_and_stock_returning_is(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 1, '2026-10-20 09:00:00', '2026-10-27 09:00:00');
        $this->move($w, $product, '5', 'EXPIRED', '2026-10-27 08:00:00'); // the manager removed the spoiled units
        $this->now('2026-10-27 09:00:00');

        $row = $this->row($w, $product);
        $this->assertSame('0.000', $row['quantity_on_hand']);
        $this->assertFalse($row['check_freshness']);
        $this->assertSame('2026-10-20', $row['last_received_on'], 'the receipt date is still reported');
    }

    public function test_stock_that_never_came_by_a_receipt_has_no_date_to_measure_and_is_not_flagged(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 1, '2026-10-01 09:00:00', '2026-10-27 09:00:00', '4', 'STOCK_ADJUSTMENT_IN');

        $row = $this->row($w, $product);
        $this->assertSame('4.000', $row['quantity_on_hand']);
        $this->assertNull($row['last_received_on']);
        $this->assertFalse($row['check_freshness']);
    }

    public function test_opening_stock_counts_as_a_receipt(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 3, '2026-10-20 09:00:00', '2026-10-27 09:00:00', '4', 'OPENING_STOCK');

        $this->assertTrue($this->row($w, $product)['check_freshness']);
    }

    public function test_a_newer_receipt_clears_the_flag_even_though_old_units_remain_the_known_weakness(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 2, '2026-10-20 09:00:00', '2026-10-27 09:00:00');
        $this->assertTrue($this->row($w, $product)['check_freshness']);

        $this->move($w, $product, '3', 'PURCHASE_RECEIPT', '2026-10-27 09:30:00');
        $this->now('2026-10-27 10:00:00');

        $row = $this->row($w, $product);
        $this->assertSame('8.000', $row['quantity_on_hand'], 'the 5 old units are still there');
        $this->assertFalse($row['check_freshness'], 'documented weakness: the system cannot tell which units are old');
        $this->assertSame('2026-10-27', $row['last_received_on']);
    }

    public function test_a_transfer_in_or_a_return_or_an_adjustment_is_not_a_receipt(): void
    {
        $w = $this->world();
        $product = $this->shelved($w, 2, '2026-10-20 09:00:00', '2026-10-27 09:00:00');
        foreach (['TRANSFER_IN', 'SALE_RETURN', 'STOCK_ADJUSTMENT_IN'] as $type) {
            $this->move($w, $product, '1', $type, '2026-10-27 08:00:00');
        }
        $this->now('2026-10-27 09:00:00');

        $row = $this->row($w, $product);
        $this->assertTrue($row['check_freshness']);
        $this->assertSame('2026-10-20', $row['last_received_on']);
    }

    public function test_no_shelf_life_means_never_flagged(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => null]);
        $this->move($w, $product, '5', 'PURCHASE_RECEIPT', '2025-01-01 09:00:00');
        $this->now('2026-10-27 09:00:00');

        $row = $this->row($w, $product);
        $this->assertFalse($row['check_freshness']);
        $this->assertNull($row['shelf_life_days']);
    }

    public function test_the_low_stock_view_carries_the_flag_too_and_the_filter_narrows_both_views_to_the_flagged_rows(): void
    {
        $w = $this->world();
        $stale = $this->shelved($w, 1, '2026-10-20 09:00:00', '2026-10-27 09:00:00', '2');
        $fresh = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 5]);
        $this->move($w, $fresh, '2', 'PURCHASE_RECEIPT', '2026-10-26 09:00:00');
        $plain = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => null]);
        $this->move($w, $plain, '2', 'PURCHASE_RECEIPT', '2026-10-01 09:00:00');
        $empty = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 1]);
        $this->move($w, $empty, '2', 'PURCHASE_RECEIPT', '2026-10-01 09:00:00');
        $this->move($w, $empty, '2', 'EXPIRED', '2026-10-02 09:00:00');
        $this->now('2026-10-27 09:00:00');

        $this->assertTrue($this->row($w, $stale, 'low')['check_freshness']);

        $client = $this->asUser($w['manager']);
        foreach (['stock', 'low-stock'] as $path) {
            $all = collect($client->getJson("/api/v1/inventory/{$path}?per_page=100")->assertOk()->json('data'));
            $flaggedInAll = $all->where('check_freshness', true)->pluck('product_id')->sort()->values()->all();
            $filtered = $client->getJson("/api/v1/inventory/{$path}?per_page=100&check_freshness=true")->assertOk()->json();
            $this->assertSame([$stale->id], $flaggedInAll, $path);
            $this->assertSame([$stale->id], collect($filtered['data'])->pluck('product_id')->all(), $path);
            $this->assertSame(1, $filtered['meta']['total'], $path);
        }
    }

    public function test_the_stock_list_costs_the_same_number_of_queries_for_three_products_or_thirty(): void
    {
        $w = $this->world();
        $count = function () use ($w): int {
            $client = $this->asUser($w['manager']);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $client->getJson('/api/v1/inventory/stock?per_page=100')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        foreach (range(1, 3) as $n) {
            $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 2]);
            $this->move($w, $product, '2', 'PURCHASE_RECEIPT', '2026-10-01 09:00:00');
        }
        $small = $count();
        foreach (range(1, 27) as $n) {
            $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 2]);
            $this->move($w, $product, '2', 'PURCHASE_RECEIPT', '2026-10-01 09:00:00');
        }

        $this->assertSame($small, $count());
    }

    public function test_the_till_availability_carries_the_flag_and_it_never_blocks_a_sale_even_with_blocking_on(): void
    {
        $w = $this->world();
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'created_at' => now(), 'updated_at' => now(), 'block_sale_without_stock' => true,
        ]);
        $product = $w['product'];
        $product->update(['shelf_life_days' => 1]);
        $this->move($w, $product, '5', 'PURCHASE_RECEIPT', now()->subDays(10)->format('Y-m-d H:i:s'));
        $fresh = $w['product2'];
        $fresh->update(['shelf_life_days' => 30]);
        $this->move($w, $fresh, '5', 'PURCHASE_RECEIPT', now()->subDays(10)->format('Y-m-d H:i:s'));

        $items = collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk()->json('items'));
        $this->assertTrue($items->firstWhere('product_id', $product->id)['check_freshness']);
        $this->assertFalse($items->firstWhere('product_id', $fresh->id)['check_freshness']);
        $this->assertStringNotContainsString('shelf_life', json_encode($items->all()), 'the till gets a flag, not the product settings');

        $this->ring($w, [[$product, '2']]); // blocking is on, the stock is stale, and the sale goes through
        $this->assertSame('3.000', DB::table('stock_balances')->where('product_id', $product->id)->value('quantity_on_hand'));
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('movement_type', 'SALE')->count());
    }

    public function test_a_daily_product_and_an_untracked_product_are_never_flagged(): void
    {
        $w = $this->world();
        $daily = Product::factory()->create(['store_id' => $w['storeId'], 'stock_mode' => 'DAILY', 'shelf_life_days' => 1]);
        $untracked = Product::factory()->create(['store_id' => $w['storeId'], 'stock_mode' => 'NONE', 'track_inventory' => false, 'shelf_life_days' => 1]);

        $items = collect($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk()->json('items'));
        $this->assertNotContains($untracked->id, $items->pluck('product_id')->all());
        $this->assertNotTrue($items->firstWhere('product_id', $daily->id)['check_freshness'] ?? false);

        $list = collect($this->asUser($w['manager'])->getJson('/api/v1/inventory/stock?per_page=100')->assertOk()->json('data'))->pluck('product_id')->all();
        $this->assertNotContains($daily->id, $list);
        $this->assertNotContains($untracked->id, $list);
    }

    // ------------------------------------------------------------------ who may set it, and what is accepted

    /** @return array<string, mixed> */
    private function input(Product $product, array $overrides = []): array
    {
        return array_merge([
            'sku' => $product->sku, 'name' => $product->name, 'unit_of_measure' => $product->unit_of_measure,
            'selling_price' => (string) $product->selling_price, 'tax_class' => $product->tax_class,
        ], $overrides);
    }

    public function test_a_manager_and_an_admin_can_set_change_and_clear_a_shelf_life_and_it_is_audited(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => null]);

        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => 3]))->assertOk()->assertJsonPath('shelf_life_days', 3);
        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product))->assertOk()->assertJsonPath('shelf_life_days', 3);
        $this->asUser($w['admin'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => 2]))->assertOk()->assertJsonPath('shelf_life_days', 2);
        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => null]))->assertOk()->assertJsonPath('shelf_life_days', null);
        $this->asUser($w['admin'])->getJson("/api/v1/products/{$product->id}")->assertOk()->assertJsonPath('shelf_life_days', null);

        $events = AuditEvent::where('event_type', 'PRODUCT_UPDATED')->where('entity_id', $product->id)->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(3, $events, 'the unchanged save wrote nothing');
        $this->assertSame([[null, 3], [3, 2], [2, null]], $events->map(fn ($e) => [$e->before_metadata['shelf_life_days'], $e->after_metadata['shelf_life_days']])->all());
        $this->assertSame($w['manager']->id, $events[0]->actor_user_id);
    }

    public function test_a_new_product_can_be_created_with_a_shelf_life(): void
    {
        $w = $this->world();

        $this->asUser($w['manager'])->postJson('/api/v1/products', [
            'sku' => 'BRW-1', 'name' => 'Brownies', 'unit_of_measure' => 'pc', 'selling_price' => '35.00', 'tax_class' => 'VATABLE', 'shelf_life_days' => 3,
        ])->assertStatus(201)->assertJsonPath('shelf_life_days', 3);
        $this->asUser($w['manager'])->postJson('/api/v1/products', [
            'sku' => 'BRW-2', 'name' => 'Plain', 'unit_of_measure' => 'pc', 'selling_price' => '35.00', 'tax_class' => 'VATABLE',
        ])->assertStatus(201)->assertJsonPath('shelf_life_days', null);
    }

    public function test_a_cashier_cannot_set_a_shelf_life(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => null]);

        $this->asUser($w['cashier'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => 3]))->assertStatus(403);
        $this->assertNull($product->refresh()->shelf_life_days);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badShelfLives(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'too large' => [3651], 'decimal' => [2.5], 'text' => ['abc']];
    }

    #[DataProvider('badShelfLives')]
    public function test_a_shelf_life_must_be_a_whole_number_of_days_from_1_to_3650(mixed $bad): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId'], 'shelf_life_days' => 2]);

        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => $bad]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => ['shelf_life_days']]]);
        $this->assertSame(2, $product->refresh()->shelf_life_days);
    }

    public function test_the_edges_1_and_3650_are_accepted_and_the_database_refuses_anything_else(): void
    {
        $w = $this->world();
        $product = Product::factory()->create(['store_id' => $w['storeId']]);

        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => 1]))->assertOk();
        $this->asUser($w['manager'])->patchJson("/api/v1/products/{$product->id}", $this->input($product, ['shelf_life_days' => 3650]))->assertOk()->assertJsonPath('shelf_life_days', 3650);

        $this->expectException(QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['shelf_life_days' => 0]);
    }
}
