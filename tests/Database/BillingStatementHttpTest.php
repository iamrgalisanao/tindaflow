<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Models\Meal;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 4.1 (invariants.md BILL-001..BILL-005): the billing statement and the saved billing run, built through the real
 * endpoints on a real ledger. The race for run numbers is BillingRunConcurrencyTest; the hardened-role proof is in AppendOnlyPrivilegesTest.
 */
class BillingStatementHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
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
        CarbonImmutable::setTestNow($instant);
        Carbon::setTestNow(Carbon::instance($instant));
    }

    /** @param  array<string, mixed>  $w */
    private function employee(array $w, string $type = 'direct', array $attributes = []): Employee
    {
        $factory = Employee::factory();

        return ($type === 'sub' ? $factory->subcontractor() : $factory)->create(['store_id' => $w['storeId']] + $attributes);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed> the sale
     */
    private function creditSale(array $w, Employee $employee, array $items, string $total): array
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => $items,
            'payments' => [['method' => 'CREDIT', 'amount' => $total]],
            'employee_id' => $employee->id,
        ], $this->key())->assertStatus(201)->json();
    }

    /** @param  array<string, mixed>  $w */
    private function plainSale(array $w, Employee $employee, ?Product $product = null, string $quantity = '1'): array
    {
        $product ??= $w['product'];

        return $this->creditSale($w, $employee, [['product_id' => $product->id, 'quantity' => $quantity]], bcmul((string) $product->selling_price, $quantity, 2));
    }

    /** @param  array<string, mixed>  $sale */
    private function refund(array $w, array $sale, string $quantity, string $amount, int $line = 0): TestResponse
    {
        return $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][$line]['id'], 'quantity' => $quantity, 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => $amount]],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);
    }

    /** @param  array<string, mixed>  $sale */
    private function void(array $w, array $sale): TestResponse
    {
        return $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);
    }

    /** @param  array<string, mixed>  $params */
    private function statement(array $w, array $params, string $as = 'manager'): TestResponse
    {
        return $this->asUser($w[$as])->getJson('/api/v1/billing/statement?'.http_build_query($params));
    }

    /** @return array<string, string> */
    private function october1(): array
    {
        return ['cutoff_month' => '2026-10', 'cutoff_half' => '1'];
    }

    /** @param  array<string, mixed>  $statement */
    private function account(array $statement, Employee $employee): array
    {
        foreach ($statement['accounts'] as $account) {
            if ($account['employee_id'] === $employee->id) {
                return $account;
            }
        }
        $this->fail("{$employee->employee_code} is not on the statement");
    }

    private function ledgerNet(?string $employeeId = null): string
    {
        $query = DB::table('employee_credit_entries');
        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        return bcadd((string) $query->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'CHARGE' THEN amount ELSE -amount END), 0) AS net")->value('net'), '0', 2);
    }

    /**
     * Standard Meal (Lunch > Standard Meal) with rice add-ons, and Combo Meal; same shape as MealCheckoutHttpTest.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function menu(array $w): array
    {
        $admin = $w['admin'];
        $storeId = $w['storeId'];
        $lunch = MenuGroup::create(['store_id' => $storeId, 'name' => 'Lunch', 'sort_order' => 1, 'active' => true]);
        $standardGroup = MenuGroup::create(['store_id' => $storeId, 'parent_id' => $lunch->id, 'name' => 'Standard Meal', 'sort_order' => 1, 'active' => true]);
        $comboGroup = MenuGroup::create(['store_id' => $storeId, 'parent_id' => $lunch->id, 'name' => 'Combo Meal', 'sort_order' => 2, 'active' => true]);
        $snacks = MenuGroup::create(['store_id' => $storeId, 'name' => 'Snacks', 'sort_order' => 3, 'active' => true]);

        $categories = app(DishCategoryService::class);
        $beef = $categories->create($admin, ['name' => 'Beef']);
        $veg = $categories->create($admin, ['name' => 'Vegetable']);
        $dishes = app(DishService::class);
        $nilaga = $dishes->create($admin, ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $pinakbet = $dishes->create($admin, ['name' => 'Pinakbet', 'dish_category_id' => $veg->id]);

        $options = app(MealOptionService::class);
        $groups = app(MealOptionGroupService::class);
        $standard = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand', 'menu_group_id' => $standardGroup->id]);
        $viand = MealOptionGroup::where('meal_id', $standard->id)->where('prices_the_meal', true)->sole();
        $nilagaOption = $options->create($admin, $viand->id, ['dish_id' => $nilaga->id]);
        $rice = $groups->create($admin, $standard->id, ['name' => 'Extra rice', 'kind' => 'ADDON', 'min_select' => 0, 'max_select' => 1, 'popup_after_choice' => true]);
        $wholeRice = $options->create($admin, $rice->id, ['label' => 'Whole rice']);

        $combo = app(MealService::class)->create($admin, ['name' => 'Combo Meal', 'pricing_group_name' => 'Main', 'menu_group_id' => $comboGroup->id]);
        $main = MealOptionGroup::where('meal_id', $combo->id)->where('prices_the_meal', true)->sole();
        $comboNilaga = $options->create($admin, $main->id, ['dish_id' => $nilaga->id]);
        $side = $groups->create($admin, $combo->id, ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 1, 'max_select' => 1]);
        $comboPinakbet = $options->create($admin, $side->id, ['dish_id' => $pinakbet->id]);

        $prices = app(MenuPriceService::class);
        $set = fn (Meal $meal, array $data) => $prices->set($admin, $meal->id, $data);
        $set($standard, ['dish_category_id' => $beef->id, 'amount' => '68.00']);
        $set($standard, ['meal_option_id' => $wholeRice->id, 'amount' => '15.00']);
        $set($combo, ['dish_category_id' => $beef->id, 'amount' => '85.00']);

        return compact('snacks', 'nilaga', 'standard', 'viand', 'nilagaOption', 'wholeRice', 'combo', 'main', 'side', 'comboNilaga', 'comboPinakbet', 'set', 'beef');
    }

    /** @param  array<string, mixed>  $m */
    private function standardItem(array $m, bool $withRice): array
    {
        return ['meal' => [
            'meal_id' => $m['standard']->id,
            'choices' => [['group_id' => $m['viand']->id, 'option_id' => $m['nilagaOption']->id]],
            'addons' => $withRice ? [$m['wholeRice']->id] : [],
        ], 'quantity' => '1'];
    }

    /** @param  array<string, mixed>  $m */
    private function comboItem(array $m): array
    {
        return ['meal' => [
            'meal_id' => $m['combo']->id,
            'choices' => [['group_id' => $m['main']->id, 'option_id' => $m['comboNilaga']->id], ['group_id' => $m['side']->id, 'option_id' => $m['comboPinakbet']->id]],
            'addons' => [],
        ], 'quantity' => '1'];
    }

    // ------------------------------------------------------------------ totals equal the ledger

    public function test_the_statement_total_equals_the_ledger_net_with_charges_a_void_and_a_partial_refund(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $ben = $this->employee($w, 'sub');
        $this->at('2026-10-05 12:00:00');

        $this->plainSale($w, $ana, quantity: '5');
        $voided = $this->plainSale($w, $ana, quantity: '3');
        $partial = $this->plainSale($w, $ana, quantity: '4');
        $this->plainSale($w, $ben, $w['product2'], '2');
        $this->at('2026-10-06 09:00:00');
        $this->void($w, $voided);
        $this->refund($w, $partial, '1', '100.00');

        $statement = $this->statement($w, $this->october1())->assertOk()->json('data');

        $this->assertSame('1200.00', $this->account($statement, $ana)['sold_amount']);
        $this->assertSame('400.00', $this->account($statement, $ana)['returned_amount']);
        $this->assertSame('800.00', $this->account($statement, $ana)['net_amount']);
        $this->assertSame('100.00', $this->account($statement, $ben)['net_amount']);
        $this->assertSame(['sold_amount' => '1300.00', 'returned_amount' => '400.00', 'net_amount' => '900.00'], array_intersect_key($statement['totals'], array_flip(['sold_amount', 'returned_amount', 'net_amount'])));
        $this->assertSame($this->ledgerNet(), $statement['totals']['net_amount'], 'BILL-001: the statement equals the ledger net');
        $this->assertSame($this->ledgerNet($ana->id), $this->account($statement, $ana)['net_amount']);
        $this->assertSame(['ledger_net' => '900.00', 'statement_net' => '900.00', 'items_net' => '900.00', 'matches' => true], $statement['reconciliation']);
        $this->assertSame(['DIRECT' => ['employee_count' => 1, 'net_amount' => '800.00'], 'SUBCONTRACTOR' => ['employee_count' => 1, 'net_amount' => '100.00']], $statement['totals']['by_employee_type']);

        // The same sales read as a custom range give the same figure.
        $custom = $this->statement($w, ['from' => '2026-10-01', 'to' => '2026-10-15'])->assertOk()->json('data');
        $this->assertSame($statement['totals']['net_amount'], $custom['totals']['net_amount']);

        // Voids and refunds are listed as flagged adjustments with their own lines.
        $this->assertSame(['VOID', 'REFUND'], array_column($statement['adjustments'], 'type'));
        $this->assertSame(['300.00', '100.00'], array_column($statement['adjustments'], 'amount'));
        $this->assertSame('Wrong order', $statement['adjustments'][0]['reason']);
        $this->assertSame('2026-10-05', $statement['adjustments'][0]['sold_on']);
        $item = $this->account($statement, $ana)['meal_types'][0]['items'][0];
        $this->assertSame(['12.000', '1200.00', '4.000', '400.00', '8.000', '800.00'], [$item['quantity_sold'], $item['sold_amount'], $item['quantity_returned'], $item['returned_amount'], $item['quantity_net'], $item['net_amount']]);
    }

    // ------------------------------------------------------------------ grouping

    public function test_the_statement_groups_account_then_meal_type_then_item_with_rice_counted_under_its_meal(): void
    {
        $w = $this->world();
        $this->at('2026-10-01 08:00:00'); // the menu prices are in force from here
        $m = $this->menu($w);
        $ana = $this->employee($w, attributes: ['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $snack = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '25.00', 'cost' => '10.00', 'menu_group_id' => $m['snacks']->id, 'name' => 'Banana cue']);
        $plain = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '30.00', 'cost' => '10.00', 'name' => 'Bottled water']);
        $this->at('2026-10-05 12:00:00');

        $this->creditSale($w, $ana, [$this->standardItem($m, true)], '83.00');
        $this->creditSale($w, $ana, [$this->standardItem($m, true), $this->comboItem($m)], '168.00');
        $this->creditSale($w, $ana, [['product_id' => $snack->id, 'quantity' => '2'], ['product_id' => $plain->id, 'quantity' => '1']], '80.00');

        $statement = $this->statement($w, $this->october1())->assertOk()->json('data');
        $account = $this->account($statement, $ana);

        $this->assertSame(['TSC', 'Ana Reyes'], [substr($account['employee_code'], 0, 3), $account['employee_name']]);
        $this->assertSame('DIRECT', $account['employee_type']);
        // Meal types sorted by name with "Other items" last.
        $this->assertSame(['Combo Meal', 'Snacks', 'Standard Meal', 'Other items'], array_column($account['meal_types'], 'meal_type'));

        $byType = collect($account['meal_types'])->keyBy('meal_type');
        $this->assertSame([['Beef nilaga + Pinakbet', 'DISH', '1.000', '85.00']], $byType['Combo Meal']['items'] === [] ? [] : [[$byType['Combo Meal']['items'][0]['item'], $byType['Combo Meal']['items'][0]['kind'], $byType['Combo Meal']['items'][0]['quantity_sold'], $byType['Combo Meal']['items'][0]['sold_amount']]]);
        $this->assertSame('85.00', $byType['Combo Meal']['net_amount']);

        $standard = $byType['Standard Meal'];
        $this->assertSame(
            [['Beef nilaga', 'DISH', '2.000', '136.00'], ['Whole rice', 'ADDON', '2.000', '30.00']],
            array_map(fn ($i) => [$i['item'], $i['kind'], $i['quantity_sold'], $i['sold_amount']], $standard['items']),
            'a plain add-on shows under its own label and is counted as an item of the meal it was bought with',
        );
        $this->assertSame('166.00', $standard['net_amount']);
        $this->assertSame([['Banana cue', 'PRODUCT', '2.000', '50.00']], array_map(fn ($i) => [$i['item'], $i['kind'], $i['quantity_sold'], $i['sold_amount']], $byType['Snacks']['items']));
        $this->assertSame([['Bottled water', 'PRODUCT', '1.000', '30.00']], array_map(fn ($i) => [$i['item'], $i['kind'], $i['quantity_sold'], $i['sold_amount']], $byType['Other items']['items']));
        $this->assertSame('331.00', $account['net_amount']);
        $this->assertSame('331.00', $statement['totals']['net_amount']);
        $this->assertSame(3, $account['sale_count']);
    }

    public function test_a_renamed_dish_or_repriced_meal_after_the_sale_changes_neither_an_old_statement_nor_a_saved_run(): void
    {
        $w = $this->world();
        $this->at('2026-10-01 08:00:00'); // the menu prices are in force from here
        $m = $this->menu($w);
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $this->creditSale($w, $ana, [$this->standardItem($m, true)], '83.00');
        $before = $this->statement($w, $this->october1())->assertOk()->json('data');
        $run = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(201)->json('data');

        // The menu moves on: the dish and the meal are renamed, the meal is repriced, the meal group renamed.
        $this->at('2026-10-06 12:00:00');
        DB::table('dishes')->where('id', $m['nilaga']->id)->update(['name' => 'Beef sinigang']);
        DB::table('meals')->where('id', $m['standard']->id)->update(['name' => 'Regular Meal']);
        DB::table('menu_groups')->where('name', 'Snacks')->update(['name' => 'Merienda']);
        $m['set']($m['standard'], ['dish_category_id' => $m['beef']->id, 'amount' => '99.00']);

        $after = $this->statement($w, $this->october1())->assertOk()->json('data');
        $this->assertSame($before['accounts'], $after['accounts'], 'MENU-001: the statement is built from the sale snapshots');
        $this->assertSame('Standard Meal', $after['accounts'][0]['meal_types'][0]['meal_type']);
        $this->assertSame('Beef nilaga', $after['accounts'][0]['meal_types'][0]['items'][0]['item']);

        $saved = $this->asUser($w['manager'])->getJson("/api/v1/billing-runs/{$run['id']}")->assertOk()->json('data');
        $this->assertEquals($run['statement']['accounts'], $saved['statement']['accounts']);
        $this->assertTrue($saved['statement_intact']);
    }

    // ------------------------------------------------------------------ re-runs and adjustments

    public function test_a_rerun_after_a_later_refund_shows_the_adjustment_and_the_earlier_saved_run_stays_unchanged(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $sale = $this->plainSale($w, $ana, quantity: '3');

        $first = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(201)->json('data');
        $this->assertSame('BR-000001', $first['run_number']);
        $this->assertSame('300.00', $first['net_amount']);
        $this->assertNull($first['statement']['comparison']);

        $this->at('2026-10-20 10:00:00'); // the next cut-off; the fiscal day is still open
        $this->refund($w, $sale, '1', '100.00');

        $live = $this->statement($w, $this->october1())->assertOk()->json('data');
        $this->assertSame('200.00', $live['totals']['net_amount']);
        $this->assertSame('BR-000001', $live['comparison']['previous_run']['run_number']);
        $this->assertTrue($live['comparison']['changed']);
        $this->assertSame('-100.00', $live['comparison']['net_change']);
        $this->assertSame(['REFUND'], array_column($live['comparison']['added_adjustments'], 'type'));
        $this->assertSame('2026-10-05', $live['adjustments'][0]['sold_on'], 'the reversal belongs to the ORIGINAL period');
        $this->assertSame([], $this->statement($w, ['cutoff_month' => '2026-10', 'cutoff_half' => '2'])->json('data.adjustments'), 'the refund does not appear in the period it was recorded in');

        $second = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(201)->json('data');
        $this->assertSame('BR-000002', $second['run_number']);
        $this->assertSame('200.00', $second['net_amount']);
        $this->assertSame('-100.00', $second['statement']['comparison']['net_change']);

        $reread = $this->asUser($w['manager'])->getJson("/api/v1/billing-runs/{$first['id']}")->assertOk()->json('data');
        $this->assertSame('300.00', $reread['net_amount']);
        $this->assertSame([], $reread['statement']['adjustments'], 'the saved run is exactly what was printed');
        $this->assertTrue($reread['statement_intact']);
        $this->assertSame($first['statement_sha256'], $reread['statement_sha256']);

        $list = $this->asUser($w['manager'])->getJson('/api/v1/billing-runs')->assertOk();
        $this->assertSame(['BR-000002', 'BR-000001'], array_column($list->json('data'), 'run_number'));
        $this->assertArrayNotHasKey('statement', $list->json('data.0'));
    }

    public function test_the_as_of_instant_reproduces_a_statement_as_it_stood_before_a_later_void(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $sale = $this->plainSale($w, $ana, quantity: '2');
        $this->at('2026-10-07 12:00:00');
        $this->void($w, $sale);

        $now = $this->statement($w, $this->october1())->json('data');
        $this->assertSame(['0.00', 1], [$now['totals']['net_amount'], count($now['adjustments'])]);
        $account = $this->account($now, $ana);
        $this->assertSame('0.00', $account['net_amount'], 'a fully voided account stays listed at zero with its adjustment');

        $earlier = $this->statement($w, $this->october1() + ['as_of' => '2026-10-06T12:00:00+08:00'])->json('data');
        $this->assertSame(['200.00', 0], [$earlier['totals']['net_amount'], count($earlier['adjustments'])]);
        $this->assertSame($earlier['totals']['net_amount'], $earlier['reconciliation']['ledger_net']);
    }

    // ------------------------------------------------------------------ ranges

    public function test_an_arbitrary_range_follows_manila_dates_across_the_cut_off_and_the_month_end(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        foreach (['2026-10-15 23:30:00', '2026-10-16 00:30:00', '2026-10-31 23:59:00', '2026-11-01 00:05:00'] as $when) {
            $this->at($when);
            $this->plainSale($w, $ana, $w['product2']);
        }
        $net = fn (array $params) => $this->statement($w, $params)->assertOk()->json('data.totals.net_amount');

        $this->assertSame('50.00', $net(['from' => '2026-10-15', 'to' => '2026-10-15']), '23:30 on the 15th');
        $this->assertSame('50.00', $net(['from' => '2026-10-16', 'to' => '2026-10-16']), '00:30 on the 16th in Manila (the 15th in UTC)');
        $this->assertSame('100.00', $net(['from' => '2026-10-15', 'to' => '2026-10-16']));
        $this->assertSame('100.00', $net(['cutoff_month' => '2026-10', 'cutoff_half' => '2']), 'the 16th to the 31st: two sales');
        $this->assertSame('50.00', $net(['from' => '2026-10-31', 'to' => '2026-10-31']), '23:59 on the 31st');
        $this->assertSame('50.00', $net(['from' => '2026-11-01', 'to' => '2026-11-01']), '00:05 on the 1st');
        $this->assertSame('200.00', $net(['from' => '2026-10-01', 'to' => '2026-11-15']));
        $this->assertSame('200.00', $net(['from' => '2026-10-15', 'to' => '2026-11-01']), 'a range may cross a cut-off and a month end');
        $this->assertSame('0.00', $net(['from' => '2026-12-01', 'to' => '2026-12-15']));
    }

    public function test_a_named_cut_off_ends_on_the_real_last_day_of_the_month(): void
    {
        $w = $this->world();
        $expected = ['2026-02' => '28', '2028-02' => '29', '2026-04' => '30', '2026-10' => '31'];
        foreach ($expected as $month => $last) {
            $criteria = $this->statement($w, ['cutoff_month' => $month, 'cutoff_half' => '2'])->assertOk()->json('data.criteria');
            $this->assertSame(["{$month}-16", "{$month}-{$last}"], [$criteria['from'], $criteria['to']]);
        }
        $first = $this->statement($w, ['cutoff_month' => '2026-10', 'cutoff_half' => '1'])->json('data.criteria');
        $this->assertSame(['2026-10-01', '2026-10-15', 'Oct 1-15, 2026'], [$first['from'], $first['to'], $first['period_label']]);

        // A sale at 23:00 on 29 February 2028 belongs to the second half of that month.
        $ana = $this->employee($w);
        $this->at('2028-02-29 23:00:00');
        $this->plainSale($w, $ana, $w['product2']);
        $this->assertSame('50.00', $this->statement($w, ['cutoff_month' => '2028-02', 'cutoff_half' => '2'])->json('data.totals.net_amount'));
        $this->assertSame('0.00', $this->statement($w, ['cutoff_month' => '2028-03', 'cutoff_half' => '1'])->json('data.totals.net_amount'));
    }

    public function test_both_employee_types_are_on_one_bill_and_can_be_filtered_and_an_idle_employee_is_not_listed(): void
    {
        $w = $this->world();
        $direct = $this->employee($w);
        $sub = $this->employee($w, 'sub');
        $idle = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $this->plainSale($w, $direct);
        $this->plainSale($w, $sub, $w['product2']);

        $both = $this->statement($w, $this->october1())->json('data');
        $this->assertEqualsCanonicalizing([$direct->id, $sub->id], array_column($both['accounts'], 'employee_id'));
        $this->assertNotContains($idle->id, array_column($both['accounts'], 'employee_id'));
        $this->assertSame(1, $both['totals']['by_employee_type']['SUBCONTRACTOR']['employee_count']);

        $onlySub = $this->statement($w, $this->october1() + ['employee_type' => 'SUBCONTRACTOR'])->json('data');
        $this->assertSame([$sub->id], array_column($onlySub['accounts'], 'employee_id'));
        $this->assertSame('50.00', $onlySub['totals']['net_amount']);
        $this->assertSame($this->ledgerNet($sub->id), $onlySub['reconciliation']['ledger_net']);

        $one = $this->statement($w, $this->october1() + ['employee_id' => $direct->id])->json('data');
        $this->assertSame([$direct->id], array_column($one['accounts'], 'employee_id'));
        $this->assertSame($direct->employee_code, $one['criteria']['employee_code']);

        $none = $this->statement($w, $this->october1() + ['employee_id' => $idle->id])->assertOk()->json('data');
        $this->assertSame([[], '0.00'], [$none['accounts'], $none['totals']['net_amount']]);
    }

    // ------------------------------------------------------------------ access and scoping

    public function test_a_cashier_is_forbidden_and_a_manager_and_an_admin_are_allowed(): void
    {
        $w = $this->world();
        $this->at('2026-10-05 12:00:00');
        $params = $this->october1();

        $this->statement($w, $params, 'cashier')->assertStatus(403);
        $this->asUser($w['cashier'])->postJson('/api/v1/billing-runs', $params)->assertStatus(403);
        $this->asUser($w['cashier'])->getJson('/api/v1/billing-runs')->assertStatus(403);
        $this->statement($w, $params, 'manager')->assertOk();
        $this->statement($w, $params, 'admin')->assertOk();

        $run = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $params)->assertStatus(201)->json('data');
        $this->asUser($w['cashier'])->getJson("/api/v1/billing-runs/{$run['id']}")->assertStatus(403);
        $this->asUser($w['admin'])->getJson("/api/v1/billing-runs/{$run['id']}")->assertOk();
        $this->asUser($w['cashier'])->getJson('/api/v1/billing/statement?'.http_build_query($params), ['Accept' => 'text/csv'])->assertStatus(403);
    }

    public function test_another_stores_employees_and_runs_never_appear(): void
    {
        $mine = $this->world();
        $theirs = $this->world();
        $stranger = $this->employee($theirs);
        $ana = $this->employee($mine);
        $this->at('2026-10-05 12:00:00');
        $this->plainSale($theirs, $stranger, quantity: '4');
        $this->plainSale($mine, $ana);

        $statement = $this->statement($mine, $this->october1())->assertOk()->json('data');
        $this->assertSame([$ana->id], array_column($statement['accounts'], 'employee_id'));
        $this->assertSame('100.00', $statement['totals']['net_amount']);

        $this->statement($mine, $this->october1() + ['employee_id' => $stranger->id])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $theirRun = $this->asUser($theirs['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(201)->json('data');
        $this->assertSame('BR-000001', $theirRun['run_number']);
        $myRun = $this->asUser($mine['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(201)->json('data');
        $this->assertSame('BR-000001', $myRun['run_number'], 'every store counts its own runs');
        $this->asUser($mine['manager'])->getJson("/api/v1/billing-runs/{$theirRun['id']}")->assertStatus(404)->assertJsonPath('error.code', 'BILLING_RUN_NOT_FOUND');
        $this->assertSame([$myRun['id']], array_column($this->asUser($mine['manager'])->getJson('/api/v1/billing-runs')->json('data'), 'id'));
    }

    // ------------------------------------------------------------------ runs

    public function test_run_numbers_are_gap_free_and_a_refused_request_uses_none(): void
    {
        $w = $this->world();
        $params = $this->october1();

        $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', ['from' => '2026-10-20', 'to' => '2026-10-01'])->assertStatus(422);
        $numbers = [];
        foreach (range(1, 3) as $ignored) {
            $numbers[] = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $params)->assertStatus(201)->json('data.run_number');
        }
        $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', ['cutoff_month' => '2026-13', 'cutoff_half' => '1'])->assertStatus(422);
        $numbers[] = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', ['from' => '2026-10-01', 'to' => '2026-10-31'])->json('data.run_number');

        $this->assertSame(['BR-000001', 'BR-000002', 'BR-000003', 'BR-000004'], $numbers);
        $this->assertSame(4, (int) DB::table('billing_run_counters')->where('store_id', $w['storeId'])->value('last_number'));
    }

    public function test_saving_a_run_is_audited_and_a_tampered_snapshot_is_detected(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $this->plainSale($w, $ana);

        $run = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1() + ['employee_type' => 'DIRECT'])->assertStatus(201)->json('data');
        $this->assertSame($w['manager']->id, $run['generated_by']['id']);
        $this->assertSame('DIRECT', $run['employee_type']);

        $event = DB::table('audit_events')->where('event_type', 'BILLING_RUN_CREATED')->sole();
        $this->assertSame([$w['manager']->id, 'billing_run', $run['id']], [$event->actor_user_id, $event->entity_type, $event->entity_id]);
        $after = json_decode($event->after_metadata, true);
        $this->assertSame(['BR-000001', '2026-10-01', '2026-10-15', '100.00', $run['statement_sha256']], [$after['run_number'], $after['from'], $after['to'], $after['net_amount'], $after['statement_sha256']]);

        DB::table('billing_runs')->where('id', $run['id'])->update(['statement' => json_encode(['version' => 1, 'accounts' => []])]);
        $this->assertFalse($this->asUser($w['manager'])->getJson("/api/v1/billing-runs/{$run['id']}")->json('data.statement_intact'), 'BILL-004: any change to a saved statement is detectable');
    }

    // ------------------------------------------------------------------ export

    public function test_the_csv_export_has_the_statement_rows_and_is_audited(): void
    {
        $w = $this->world();
        $this->at('2026-10-01 08:00:00'); // the menu prices are in force from here
        $m = $this->menu($w);
        $ana = $this->employee($w, attributes: ['first_name' => '=SUM(A1)', 'last_name' => 'Reyes']);
        $this->at('2026-10-05 12:00:00');
        $this->creditSale($w, $ana, [$this->standardItem($m, true)], '83.00');
        $sale = $this->plainSale($w, $ana, quantity: '2');
        $this->refund($w, $sale, '1', '100.00');

        $response = $this->asUser($w['manager'])->get('/api/v1/billing/statement?'.http_build_query($this->october1()), ['Accept' => 'text/csv'])->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $rows = array_map('str_getcsv', array_filter(explode("\n", $response->getContent())));
        $header = array_shift($rows);
        $this->assertSame(['row_type', 'employee_code', 'employee_name'], array_slice($header, 0, 3));
        $by = fn (string $type) => array_values(array_filter($rows, fn ($row) => $row[0] === $type));

        $items = $by('ITEM');
        $this->assertSame(['Beef nilaga', 'DISH', '1.000', '68.00'], [$items[0][5], $items[0][6], $items[0][7], $items[0][8]]);
        $this->assertSame('Whole rice', $items[1][5]);
        $this->assertSame("'=SUM(A1) Reyes", $items[0][2], 'a name that looks like a formula is neutralised');
        $this->assertCount(1, $by('ADJUSTMENT'));
        $this->assertSame(['REFUND', '-100.00'], [$by('ADJUSTMENT')[0][13], $by('ADJUSTMENT')[0][12]]);
        $this->assertCount(1, $by('EMPLOYEE_TOTAL'));
        $this->assertSame('183.00', $by('GRAND_TOTAL')[0][12]);
        $this->assertCount(2, $by('MEAL_TYPE_TOTAL'));

        $event = DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->sole();
        $after = json_decode($event->after_metadata, true);
        $this->assertSame(['csv', 'live', '183.00'], [$after['format'], $after['source'], $after['net_amount']]);

        $run = $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1())->json('data');
        $saved = $this->asUser($w['manager'])->get("/api/v1/billing-runs/{$run['id']}", ['Accept' => 'text/csv'])->assertOk();
        $this->assertSame($response->getContent(), $saved->getContent());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->count());
        $this->assertSame('saved_run', json_decode(DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->where('entity_type', 'billing_run')->value('after_metadata'), true)['source']);
    }

    public function test_a_statement_that_does_not_reconcile_with_the_ledger_is_refused_and_never_saved(): void
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $sale = $this->plainSale($w, $ana, quantity: '2');

        // Corrupt a snapshot line behind the application's back (only a superuser could): the lines no longer add up to the charge.
        DB::table('sale_items')->where('sale_id', $sale['id'])->update(['net_line_amount' => '150.00']);

        $this->statement($w, $this->october1())->assertStatus(500)->assertJsonPath('error.code', 'BILLING_RECONCILIATION_FAILED');
        $this->asUser($w['manager'])->postJson('/api/v1/billing-runs', $this->october1())->assertStatus(500);
        $this->assertSame(0, DB::table('billing_runs')->count(), 'BILL-001: a statement that disagrees with the ledger is never saved');
    }

    // ------------------------------------------------------------------ validation

    public function test_the_range_is_validated(): void
    {
        $w = $this->world();
        $reject = fn (array $params, string $field) => $this->statement($w, $params)->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['details' => [$field]]]);

        $reject(['from' => '2026-10-20', 'to' => '2026-10-01'], 'from');
        $reject(['from' => '2026-01-01', 'to' => '2027-01-02'], 'to'); // 367 days
        $reject([], 'from');
        $reject(['from' => '2026-10-01'], 'to');
        $reject(['cutoff_month' => '2026-10', 'from' => '2026-10-01', 'to' => '2026-10-02', 'cutoff_half' => '1'], 'from');
        $reject(['cutoff_month' => '2026-10', 'cutoff_half' => '3'], 'cutoff_half');
        $reject(['cutoff_month' => '2026-10'], 'cutoff_half');
        $reject(['from' => '2026-02-30', 'to' => '2026-03-01'], 'from');
        $reject(['from' => '2026-10-01', 'to' => '2026-10-02', 'employee_type' => 'BOSS'], 'employee_type');
        $reject(['from' => '2026-10-01', 'to' => '2026-10-02', 'employee_id' => 'not-a-uuid'], 'employee_id');

        $this->statement($w, ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertOk(); // 365 days
        $this->statement($w, ['from' => '2028-01-01', 'to' => '2028-12-31'])->assertOk(); // 366 days, a leap year
        $this->statement($w, ['from' => '2026-10-05', 'to' => '2026-10-05'])->assertOk();
    }
}
