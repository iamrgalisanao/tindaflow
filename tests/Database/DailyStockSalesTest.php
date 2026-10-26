<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsDailyStockScenario;

/**
 * ADR-017 slice B, invariants.md STK-002 and STK-003 (client decisions 1, 3, 4, 9 and 10): daily dish stock at the till. The client's portions (a Standard
 * viand 1.0, a Combo main 1.0, the Combo vegetable side 1.0, an ALA CARTE sale of a dish 2.0), the demand pooled per dish across the whole cart, blocking
 * with daily stock on and off, a missing entry counting as zero, the 05:00 cutover, yesterday's portions not counting today, and cooked food never being
 * put back by a void or a refund (running stock still is).
 */
class DailyStockSalesTest extends PostgresSchemaTestCase
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

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the world and the daily menu, with nilaga 10 and pinakbet 10 cooked */
    private function canteen(array $rules = [], bool $stocked = true): array
    {
        $w = $this->world();
        $m = $this->dailyMenu($w);
        $this->stockRules($w, $rules);
        if ($stocked) {
            $this->enterCooked($w, [$m['nilaga']->id => '10', $m['pinakbet']->id => '10', $m['adobo']->id => '10'])->assertStatus(201);
        }

        return [$w, $m];
    }

    // ------------------------------------------------------------------ the client's portions

    public function test_a_standard_meal_viand_takes_one_portion_and_rice_takes_none(): void
    {
        [$w, $m] = $this->canteen();

        $sale = $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'], withRice: true)])->assertStatus(201);

        $this->assertSame('9.000', $this->left($m['nilaga']));
        $this->assertSame('10.000', $this->left($m['pinakbet']));
        // Two sale lines (the plate and the rice) but only ONE daily draw: the plate's chosen dish.
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->whereNotNull('stock_date')->count());
        $draw = StockMovement::where('movement_type', 'SALE')->whereNotNull('stock_date')->sole();
        $this->assertSame('1.000', (string) $draw->quantity);
        $this->assertSame('sale_item_selection', $draw->reference_type);
        $this->assertSame(self::TODAY, $draw->stock_date->toDateString());
        $this->assertSame($m['nilaga']->fresh()->stock_product_id, $draw->product_id);
        $this->assertSame($w['cashier']->id, $draw->created_by);
        $this->assertNull(DB::table('sale_item_selections')->where('group_name_snapshot', 'Extra rice')->value('stock_portion_snapshot'));
        $this->assertSame('1.000', DB::table('sale_item_selections')->where('dish_id', $m['nilaga']->id)->value('stock_portion_snapshot'));
        $this->assertCount(2, $sale->json('items'));
    }

    public function test_a_combo_takes_one_portion_of_its_main_and_one_of_its_vegetable_side(): void
    {
        [$w, $m] = $this->canteen();

        $this->sellItems($w, [$this->comboItem($m)])->assertStatus(201);

        $this->assertSame('9.000', $this->left($m['nilaga']));
        $this->assertSame('9.000', $this->left($m['pinakbet']), 'the vegetable side is a whole portion (the earlier half was withdrawn)');
        $snapshots = DB::table('sale_item_selections')->whereNotNull('dish_id')->pluck('stock_portion_snapshot', 'dish_id');
        $this->assertSame(['1.000', '1.000'], [$snapshots[$m['nilaga']->id], $snapshots[$m['pinakbet']->id]]);
    }

    public function test_an_ala_carte_sale_of_a_dish_takes_two_portions_from_the_same_pool(): void
    {
        [$w, $m] = $this->canteen();

        $sale = $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(201);

        $this->assertSame('8.000', $this->left($m['nilaga']));
        $movement = StockMovement::where('movement_type', 'SALE')->whereNotNull('stock_date')->sole();
        $this->assertSame('2.000', (string) $movement->quantity);
        $this->assertSame($m['nilaga']->fresh()->stock_product_id, $movement->product_id);
        $this->assertSame('sale_item', $movement->reference_type);
        $this->assertSame('2.000', $sale->json('items.0.quantity') === '1.000' ? DB::table('sale_items')->value('stock_portion_snapshot') : null);
        // The ala carte product's own balance is never written.
        $this->assertSame(0, DB::table('stock_movements')->where('product_id', $m['alaNilaga']->id)->count());
        $this->assertSame(0, DB::table('daily_stock_balances')->where('product_id', $m['alaNilaga']->id)->count());

        $this->sellItems($w, [$this->alaItem($m['alaNilaga'], '2')])->assertStatus(201);
        $this->assertSame('4.000', $this->left($m['nilaga']));
    }

    public function test_the_demand_of_a_meal_an_ala_carte_portion_and_a_combo_side_is_pooled_per_dish_across_the_cart(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '4', $m['pinakbet']->id => '2'])->assertStatus(201);

        // Standard nilaga (1) + Combo main nilaga (1) + ala carte nilaga (2) = 4: exactly the pool. The Combo's side is pinakbet (1) of its 2.
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption']), $this->comboItem($m), $this->alaItem($m['alaNilaga'])])->assertStatus(201);
        $this->assertSame('0.000', $this->left($m['nilaga']));
        $this->assertSame('1.000', $this->left($m['pinakbet']));

        // One more portion of any kind is refused, and the answer names the dish with its pool.
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('error.details.shortfalls.0.kind', 'DISH')->assertJsonPath('error.details.shortfalls.0.name', 'Beef nilaga')
            ->assertJsonPath('error.details.shortfalls.0.required', '1.000')->assertJsonPath('error.details.shortfalls.0.available', '0.000')
            ->assertJsonPath('error.details.shortfalls.0.mode', 'DAILY')->assertJsonPath('error.details.shortfalls.0.stock_date', self::TODAY)
            ->assertJsonPath('error.details.shortfalls.0.dish_id', $m['nilaga']->id);
    }

    public function test_one_cart_that_needs_more_in_total_than_is_left_is_refused_even_though_each_line_alone_would_fit(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '2'])->assertStatus(201);

        // Standard (1) fits, ala carte (2) fits; together they need 3 of 2.
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption']), $this->alaItem($m['alaNilaga'])])->assertStatus(409)
            ->assertJsonCount(1, 'error.details.shortfalls')->assertJsonPath('error.details.shortfalls.0.required', '3.000')->assertJsonPath('error.details.shortfalls.0.available', '2.000');
        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame('2.000', $this->left($m['nilaga']), 'a refused sale writes nothing');
    }

    public function test_a_menu_change_of_the_portion_applies_to_the_next_sale_only_the_snapshot_keeps_the_old_one(): void
    {
        [$w, $m] = $this->canteen();
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);

        $this->asUser($w['admin'])->patchJson("/api/v1/meal-options/{$m['nilagaOption']->id}", ['stock_portion' => '1.5'])->assertOk()->assertJsonPath('stock_portion', '1.500');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);

        $this->assertSame('7.500', $this->left($m['nilaga']));
        $this->assertSame(['1.000', '1.500'], DB::table('sale_item_selections')->where('dish_id', $m['nilaga']->id)->orderBy('stock_portion_snapshot')->pluck('stock_portion_snapshot')->map(fn ($v) => (string) $v)->all());
    }

    public function test_a_rice_add_on_cannot_be_given_a_portion(): void
    {
        [$w, $m] = $this->canteen();

        $this->asUser($w['admin'])->patchJson("/api/v1/meal-options/{$m['wholeRice']->id}", ['stock_portion' => '2'])->assertStatus(422);
        $this->asUser($w['admin'])->patchJson("/api/v1/meal-options/{$m['nilagaOption']->id}", ['stock_portion' => '0'])->assertStatus(422);
    }

    // ------------------------------------------------------------------ blocking

    public function test_with_blocking_on_no_entry_means_no_sale_and_nothing_is_written(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);

        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(409)->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('error.details.shortfalls.0.available', '0.000');
        $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(409)
            ->assertJsonPath('error.details.shortfalls.0.required', '2.000');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'SALE')->count());
        $this->assertSame(0, DB::table('daily_stock_balances')->count(), 'the zero row made to lock was rolled back');
    }

    public function test_with_blocking_on_the_last_portions_sell_and_the_next_is_refused_with_what_is_left(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '3'])->assertStatus(201);

        $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(201); // 2 of 3
        $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(409)
            ->assertJsonPath('error.details.shortfalls.0.available', '1.000')->assertJsonPath('error.details.shortfalls.0.required', '2.000'); // 1 left < 2
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201); // the one left fits a plate
        $this->assertSame('0.000', $this->left($m['nilaga']));
    }

    public function test_with_blocking_off_daily_stock_is_still_recorded_and_may_go_negative(): void
    {
        [$w, $m] = $this->canteen(stocked: false);

        $this->sellItems($w, [$this->alaItem($m['alaNilaga'])])->assertStatus(201);

        $this->assertSame('-2.000', $this->left($m['nilaga']));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE')->whereNotNull('stock_date')->count());
    }

    public function test_a_plain_daily_product_is_its_own_pool_entered_sold_and_blocked_like_a_dish(): void
    {
        $w = $this->world();
        $this->stockRules($w, ['block_sale_without_stock' => true]);
        $siopao = Product::factory()->create(['store_id' => $w['storeId'], 'name' => 'Siopao', 'selling_price' => '25.00', 'stock_mode' => 'DAILY', 'reorder_level' => null]);
        $this->assertSame('DAILY', $siopao->fresh()->stock_mode);
        $this->assertTrue($siopao->fresh()->track_inventory);

        $this->sellItems($w, [['product_id' => $siopao->id, 'quantity' => '1']])->assertStatus(409)
            ->assertJsonPath('error.details.shortfalls.0.kind', 'PRODUCT')->assertJsonPath('error.details.shortfalls.0.mode', 'DAILY');

        $this->enterCooked($w, [$siopao->id => '2'])->assertStatus(201);
        $this->sellItems($w, [['product_id' => $siopao->id, 'quantity' => '2']])->assertStatus(201);
        $this->sellItems($w, [['product_id' => $siopao->id, 'quantity' => '1']])->assertStatus(409);

        $movement = StockMovement::where('product_id', $siopao->id)->where('movement_type', 'SALE')->sole();
        $this->assertSame(self::TODAY, $movement->stock_date->toDateString());
        $this->assertSame('0.000', bcadd((string) DB::table('daily_stock_balances')->where('product_id', $siopao->id)->value('quantity_on_hand'), '0', 3));
        $this->assertSame(0, DB::table('stock_balances')->where('product_id', $siopao->id)->count(), 'daily stock never touches the running balance');
    }

    public function test_untracked_items_and_rice_are_never_blocked_when_daily_stock_runs_out(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $untracked = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '10.00', 'track_inventory' => false]);
        $this->assertSame('NONE', $untracked->fresh()->stock_mode);

        $this->sellItems($w, [['product_id' => $untracked->id, 'quantity' => '5']])->assertStatus(201);
    }

    public function test_a_manager_can_approve_a_short_meal_and_the_dish_goes_below_zero_as_oversold(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->managerCode($w['manager']);
        $cart = [$this->standardItem($m, $m['nilagaOption'])];

        // The grant request carries the meal exactly as the sale does; the server finds the dish's shortfall itself.
        $grant = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/stock-overrides', ['approver_id' => $w['manager']->id, 'code' => self::MANAGER_CODE, 'items' => $cart])
            ->assertStatus(201)->assertJsonPath('shortfalls.0.name', 'Beef nilaga')->assertJsonPath('shortfalls.0.required', '1.000');

        $this->sellItems($w, $cart, ['stock_override_token' => $grant->json('token')])->assertStatus(201);
        $this->assertSame('-1.000', $this->left($m['nilaga']));
        $this->assertSame(1, AuditEvent::where('event_type', 'STOCK_OVERRIDE_USED')->count());
    }

    // ------------------------------------------------------------------ the stock day

    public function test_the_stock_day_runs_from_five_to_five_a_sale_at_459_uses_yesterdays_stock_and_one_at_500_does_not(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true, 'stock_day_cutover_hour' => 5], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '5'])->assertStatus(201); // 27 Oct 10:00 -> the stock day of 27 Oct

        $this->at('2026-10-28 04:59:00');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(201);
        $this->assertSame('4.000', $this->left($m['nilaga'], '2026-10-27'), 'a sale before 5 AM draws from the day that began the morning before');
        $this->assertSame(self::TODAY, StockMovement::where('movement_type', 'SALE')->whereNotNull('stock_date')->sole()->stock_date->toDateString());

        $this->at('2026-10-28 05:00:00');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(409)->assertJsonPath('error.details.shortfalls.0.stock_date', '2026-10-28')
            ->assertJsonPath('error.details.shortfalls.0.available', '0.000');
    }

    public function test_with_the_default_midnight_cutover_a_sale_after_midnight_belongs_to_the_new_day(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '5'])->assertStatus(201);

        $this->at('2026-10-28 00:30:00');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(409)->assertJsonPath('error.details.shortfalls.0.stock_date', '2026-10-28');
    }

    public function test_yesterdays_portions_do_not_count_today_and_the_unsold_ones_stay_as_yesterdays_leftover(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '5'])->assertStatus(201);

        $this->at('2026-10-28 09:00:00');
        $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption'])])->assertStatus(409);
        $this->assertSame('5.000', $this->left($m['nilaga'], self::TODAY), 'nothing resets it and nothing carries it over');

        $availability = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk();
        $this->assertSame('2026-10-28', $availability->json('stock_date'));
        $this->assertSame('0.000', collect($availability->json('dishes'))->firstWhere('dish_id', $m['nilaga']->id)['remaining']);
        $this->assertFalse($availability->json('daily_entered'));
    }

    // ------------------------------------------------------------------ what the till reads

    public function test_availability_lists_daily_dishes_and_products_with_no_last_three_warning(): void
    {
        [$w, $m] = $this->canteen(['block_sale_without_stock' => true], stocked: false);
        $this->enterCooked($w, [$m['nilaga']->id => '5'])->assertStatus(201);

        $body = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/stock/availability')->assertOk()->json();

        $this->assertTrue($body['daily_configured']);
        $this->assertTrue($body['daily_entered']);
        $this->assertSame(self::TODAY, $body['stock_date']);
        $dish = collect($body['dishes'])->firstWhere('dish_id', $m['nilaga']->id);
        $this->assertSame(['dish_id', 'name', 'remaining', 'sold_out'], array_keys($dish));
        $this->assertSame(['5.000', false], [$dish['remaining'], $dish['sold_out']]);
        $this->assertTrue(collect($body['dishes'])->firstWhere('dish_id', $m['pinakbet']->id)['sold_out'], 'no entry yet: sold out');

        // The ala carte product reads the same pool: 5 portions at 2.0 each cover 2 orders. Never "low".
        $ala = collect($body['items'])->firstWhere('product_id', $m['alaNilaga']->id);
        $this->assertSame(['DAILY', $m['nilaga']->id, '2.000', '2.000', false, false], [$ala['mode'], $ala['dish_id'], $ala['stock_portion'], $ala['remaining'], $ala['low'], $ala['sold_out']]);
        $this->assertTrue(collect($body['items'])->firstWhere('product_id', $m['alaPinakbet']->id)['sold_out']);

        // The hidden pool and backing products never reach the tiles.
        $ids = array_column($body['items'], 'product_id');
        $this->assertNotContains($m['nilaga']->fresh()->stock_product_id, $ids);
        $this->assertNotContains($m['standard']->fresh()->backing_product_id, $ids);
    }

    public function test_the_till_menu_carries_each_options_portion_for_the_picker(): void
    {
        [$w, $m] = $this->canteen();

        $menu = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/menu')->assertOk();
        $options = collect($menu->json('meals'))->flatMap(fn ($meal) => collect($meal['option_groups'])->flatMap(fn ($group) => $group['options']))->keyBy('id');

        $this->assertSame('1.000', $options[$m['nilagaOption']->id]['stock_portion']);
        $this->assertSame('1.000', $options[$m['comboPinakbet']->id]['stock_portion']);
        $this->assertNull($options[$m['wholeRice']->id]['stock_portion'] ?? null);
    }

    // ------------------------------------------------------------------ void and refund: cooked food is never put back

    public function test_a_void_does_not_put_cooked_portions_back_but_still_returns_running_stock(): void
    {
        [$w, $m] = $this->canteen();
        $cola = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '30.00', 'reorder_level' => null]);
        app(StockLedger::class)->record(['product_id' => $cola->id, 'location_id' => $w['location']->id, 'movement_type' => 'OPENING_STOCK', 'quantity' => '10', 'created_by' => $w['admin']->id]);

        $sale = $this->sellItems($w, [$this->standardItem($m, $m['nilagaOption']), $this->alaItem($m['alaNilaga']), ['product_id' => $cola->id, 'quantity' => '2']])->assertStatus(201)->json();
        $this->assertSame('7.000', $this->left($m['nilaga']));
        $this->assertSame('8.000', (string) bcadd((string) DB::table('stock_balances')->where('product_id', $cola->id)->value('quantity_on_hand'), '0', 3));

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Customer changed mind'], $this->key())->assertStatus(201);

        $this->assertSame('7.000', $this->left($m['nilaga']), 'served food is not put back');
        $this->assertSame('10.000', (string) bcadd((string) DB::table('stock_balances')->where('product_id', $cola->id)->value('quantity_on_hand'), '0', 3), 'running stock behaves as before');
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->whereNotNull('stock_date')->count());
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count(), 'only the cola came back');
    }

    public function test_a_refund_of_a_daily_line_is_recorded_as_disposed_and_writes_no_stock_movement(): void
    {
        [$w, $m] = $this->canteen();
        $cola = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '30.00', 'reorder_level' => null]);
        $sale = $this->sellItems($w, [$this->alaItem($m['alaNilaga']), $this->standardItem($m, $m['pinakbetOption']), ['product_id' => $cola->id, 'quantity' => '1']])->assertStatus(201)->json();
        $items = collect($sale['items']);
        $ala = $items->firstWhere('product_name_snapshot', $m['alaNilaga']->name);
        $plate = $items->first(fn ($i) => str_starts_with($i['product_name_snapshot'], 'Standard Meal'));
        $colaLine = $items->firstWhere('product_id', $cola->id);

        $refund = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [
                ['sale_item_id' => $ala['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK'],
                ['sale_item_id' => $plate['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK'],
                ['sale_item_id' => $colaLine['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK'],
            ],
            'settlements' => [['payment_method' => 'CASH', 'amount' => bcadd(bcadd($ala['net_line_amount'], $plate['net_line_amount'], 2), $colaLine['net_line_amount'], 2)]],
            'reason' => 'Customer returned the food',
        ], $this->key())->assertStatus(201);

        $dispositions = collect($refund->json('items'))->pluck('disposition', 'sale_item_id');
        $this->assertSame('DISPOSED', $dispositions[$ala['id']], 'back to stock is recorded as disposed for cooked food');
        $this->assertSame('DISPOSED', $dispositions[$plate['id']]);
        $this->assertSame('RETURN_TO_STOCK', $dispositions[$colaLine['id']], 'running stock is unchanged');
        $this->assertSame('8.000', $this->left($m['nilaga']));
        $this->assertSame('9.000', $this->left($m['pinakbet']));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count(), 'only the cola');
        $this->assertSame(0, StockMovement::where('movement_type', 'SALE_RETURN')->whereNotNull('stock_date')->count());
    }
}
