<?php

namespace Tests\Database;

use App\Models\Dish;
use App\Models\Employee;
use App\Models\Meal;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Models\ZReading;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use App\Services\Reports\SalesByMealTypeQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 amendment 2026-10-26 (slice 4.4, invariant EOD-001): the end-of-day report of a slip-mode day carries SALES BY MEAL TYPE then by
 * dish / item, frozen into the reading when the day closes; the same figures are available for any business date as a standalone report.
 * Built through the real endpoints on real sales, a void, a partial refund and a credit sale.
 */
class EndOfDayMealTypeTest extends PostgresSchemaTestCase
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
    private function slipMode(array $w): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP', 'slip_footer' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    /** @param  array<string, mixed>  $w */
    private function sale(array $w, array $items, string $total, string $method = 'CASH', ?Employee $employee = null, string $as = 'manager', int $till = 2): array
    {
        $payload = ['items' => $items, 'payments' => [['method' => $method, 'amount' => $total]]];
        if ($employee !== null) {
            $payload['employee_id'] = $employee->id;
        }

        return $this->asUser($w[$as], $w['enroll'.$till])->postJson('/api/v1/sales', $payload, $this->key())->assertStatus(201)->json();
    }

    /** @param  array<string, mixed>  $sale */
    private function refundLine(array $w, array $sale, int $line, string $quantity, string $amount, string $method = 'CASH'): void
    {
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][$line]['id'], 'quantity' => $quantity, 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => $method, 'amount' => $amount]],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);
    }

    /**
     * A slip-mode day on the manager's till (2) with: a Standard Meal with rice (83), a plain Standard Meal (68), a Combo on credit (85), a plain product and a
     * snack (100 + 50), a Standard Meal that is voided (68), then the rice (15) and the snack (50) refunded. Closed through the real endpoints.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ZReading}
     */
    private function closedSlipDay(?callable $afterMenu = null, ?callable $beforeClose = null): array
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->at('2026-10-26 08:00:00');
        $m = $this->menu($w);
        if ($afterMenu !== null) {
            $afterMenu($w, $m);
        }
        $ana = Employee::factory()->create(['store_id' => $w['storeId']]);
        $snack = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '50.00', 'cost' => '10.00', 'menu_group_id' => $m['snacks']->id, 'name' => 'Banana cue']);
        $this->at('2026-10-26 12:00:00');

        $withRice = $this->sale($w, [$this->standardItem($m, true)], '83.00');
        $this->sale($w, [$this->standardItem($m, false)], '68.00');
        $this->sale($w, [$this->comboItem($m)], '85.00', 'CREDIT', $ana);
        $mixed = $this->sale($w, [['product_id' => $w['product']->id, 'quantity' => '1'], ['product_id' => $snack->id, 'quantity' => '1']], '150.00');
        $voided = $this->sale($w, [$this->standardItem($m, false)], '68.00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);
        $this->refundLine($w, $withRice, 1, '1', '15.00');
        $this->refundLine($w, $mixed, 1, '1', '50.00');
        if ($beforeClose !== null) {
            $beforeClose($w);
        }

        $shiftId = $w['managerShift']->id;
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();
        $this->asUser($w['admin'], $w['enroll2'])->postJson("/api/v1/fiscal-days/{$w['managerShift']->fiscal_day_id}/close", [], $this->key())->assertOk();

        return [$w, $m, ZReading::where('fiscal_day_id', $w['managerShift']->fiscal_day_id)->sole()];
    }

    private function print(array $w, ZReading $reading, string $as = 'admin'): TestResponse
    {
        return $this->asUser($w[$as])->postJson("/api/v1/fiscal-days/{$reading->fiscal_day_id}/z-reading/prints")->assertStatus(201);
    }

    /** The rows of the printed SALES BY MEAL TYPE block as [left, right] text pairs, in order. */
    private function printedSection(string $html): array
    {
        $this->assertStringContainsString('SALES BY MEAL TYPE', $html);
        $block = substr($html, strpos($html, 'SALES BY MEAL TYPE'));
        $block = substr($block, 0, strpos($block, 'NET SALES') + 200);
        preg_match_all('#<div class="row[^"]*"><span>(.*?)</span><span>(.*?)</span></div>#', $block, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => [html_entity_decode($m[1]), html_entity_decode($m[2])], $matches);
    }

    private function typeNet(array $section, string $type): ?string
    {
        foreach ($section['meal_types'] as $row) {
            if ($row['meal_type'] === $type) {
                return $row['net_amount'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ reconciliation

    public function test_the_section_reconciles_with_the_days_net_sales_through_a_void_a_refund_and_a_credit_sale(): void
    {
        [, , $reading] = $this->closedSlipDay();
        $totals = $reading->totals_snapshot;
        $section = $totals['sales_by_meal_type'];

        // The readings' own figures: gross excludes the voided sale, refunds are separate, and the credit sale is a sale (and its own non-cash line).
        $this->assertSame('386.00', $totals['gross_sales']);
        $this->assertSame('65.00', $totals['refund_total']);
        $this->assertSame('68.00', $totals['void_total']);
        $this->assertArrayHasKey('CREDIT', (array) $totals['payment_breakdown']);

        $net = bcsub($totals['gross_sales'], $totals['refund_total'], 2);
        $this->assertSame('321.00', $net);
        $this->assertSame($net, $section['net_amount'], 'EOD-001: the section total is the day\'s net sales');
        $this->assertSame($totals['gross_sales'], $section['sold_amount']);
        $this->assertSame($totals['refund_total'], $section['returned_amount']);
        $this->assertSame($totals['void_total'], $section['voided_amount']);
        $typeSum = array_reduce($section['meal_types'], fn (string $carry, array $type) => bcadd($carry, $type['net_amount'], 2), '0.00');
        $this->assertSame($net, $typeSum);
        foreach ($section['meal_types'] as $type) {
            $this->assertSame($type['net_amount'], array_reduce($type['items'], fn (string $carry, array $item) => bcadd($carry, $item['net_amount'], 2), '0.00'), $type['meal_type'].' = its items');
        }
        $this->assertNotContains(SalesByMealTypeQuery::UNALLOCATED, array_column($section['meal_types'], 'meal_type'), 'nothing had to be left unallocated');
    }

    public function test_a_difference_between_the_lines_and_the_ledger_shows_as_unallocated_instead_of_breaking_the_total(): void
    {
        [, , $reading] = $this->closedSlipDay(null, function (array $w): void {
            // Only possible by tampering with the books: a sale whose total no longer equals its lines.
            DB::table('sales')->where('fiscal_day_id', $w['managerShift']->fiscal_day_id)->where('grand_total', '85.00')->update(['grand_total' => '90.00']);
        });
        $totals = $reading->totals_snapshot;
        $section = $totals['sales_by_meal_type'];

        $this->assertSame(bcsub($totals['gross_sales'], $totals['refund_total'], 2), $section['net_amount']);
        $this->assertSame('5.00', $this->typeNet($section, 'Unallocated'));
        $this->assertSame('Unallocated', end($section['meal_types'])['meal_type']);
    }

    public function test_meals_combos_add_ons_and_plain_products_are_grouped_by_meal_type_and_dish(): void
    {
        [, , $reading] = $this->closedSlipDay();
        $section = $reading->totals_snapshot['sales_by_meal_type'];

        $this->assertSame(['Combo Meal', 'Snacks', 'Standard Meal', 'Other items'], array_column($section['meal_types'], 'meal_type'));
        $this->assertSame('85.00', $this->typeNet($section, 'Combo Meal'));
        $this->assertSame('0.00', $this->typeNet($section, 'Snacks'), 'sold 50, all refunded');
        $this->assertSame('136.00', $this->typeNet($section, 'Standard Meal'));
        $this->assertSame('100.00', $this->typeNet($section, 'Other items'));

        $byType = array_column($section['meal_types'], null, 'meal_type');
        $combo = $byType['Combo Meal']['items'][0];
        $this->assertSame(['Beef nilaga + Pinakbet', 'DISH', '1.000', '85.00'], [$combo['item'], $combo['kind'], $combo['quantity_net'], $combo['net_amount']]);
        [$dish, $rice] = $byType['Standard Meal']['items'];
        $this->assertSame(['Beef nilaga', 'DISH', '2.000', '136.00'], [$dish['item'], $dish['kind'], $dish['quantity_net'], $dish['net_amount']], 'the voided meal is not counted');
        $this->assertSame(['Whole rice', 'ADDON', '1.000', '15.00', '1.000', '15.00', '0.000', '0.00'], [$rice['item'], $rice['kind'], $rice['quantity_sold'], $rice['sold_amount'], $rice['quantity_returned'], $rice['returned_amount'], $rice['quantity_net'], $rice['net_amount']]);
        $this->assertSame('Banana cue', $byType['Snacks']['items'][0]['item']);
    }

    // ------------------------------------------------------------------ the printout

    public function test_the_printout_lists_meal_types_and_dishes_compactly_and_ends_with_the_net_sales_bridge(): void
    {
        [$w, , $reading] = $this->closedSlipDay();

        $html = $this->print($w, $reading)->json('render_html');
        $rows = $this->printedSection($html);

        $this->assertStringContainsString('END OF DAY REPORT', $html);
        $this->assertStringContainsString('@page{size:58mm auto', $html);
        $this->assertSame([
            ['Combo Meal', '85.00'],
            ['1 Beef nilaga +...', '85.00'],
            ['Standard Meal', '136.00'],
            ['2 Beef nilaga', '136.00'],
            ['Other items', '100.00'],
            ['1 '.$this->productName($w), '100.00'],
            ['Sales', '386.00'],
            ['Refunds', '-65.00'],
            ['NET SALES', '321.00'],
            ['Voids (excl.)', '68.00'],
        ], $rows, 'zero-net rows (the snack, the refunded rice) stay off the paper');
        $this->assertStringNotContainsString('<br', $html);
    }

    private function productName(array $w): string
    {
        return mb_strimwidth($w['product']->name, 0, 16, '...');
    }

    public function test_no_printed_line_is_wider_than_the_58mm_roll(): void
    {
        [$w, , $reading] = $this->closedSlipDay(function (array $w, array $m): void {
            foreach (Dish::orderBy('name')->get() as $index => $dish) {
                $dish->update(['name' => 'Extraordinarily long dish name number '.$index]);
            }
            Meal::where('name', 'Standard Meal')->update(['name' => 'A very very long meal type name indeed']);
        });

        $rows = $this->printedSection($this->print($w, $reading)->json('render_html'));

        $this->assertNotEmpty($rows);
        $this->assertContains('A very very lon...', array_column($rows, 0), 'a long meal type is cut, with an ellipsis');
        $this->assertContains('2 Extraordinari...', array_column($rows, 0));
        foreach ($rows as [$left, $right]) {
            $this->assertLessThanOrEqual(18, mb_strlen($left), "left label '$left'");
            $this->assertLessThanOrEqual(28, mb_strlen($left) + 1 + mb_strlen($right), "line '$left' '$right'");
        }
    }

    public function test_a_reprint_shows_the_same_figures_as_the_original(): void
    {
        [$w, , $reading] = $this->closedSlipDay();

        $original = $this->print($w, $reading);
        $copy = $this->print($w, $reading, 'manager');

        $this->assertFalse($original->json('is_reprint'));
        $this->assertTrue($copy->json('is_reprint'));
        $this->assertSame($this->printedSection($original->json('render_html')), $this->printedSection($copy->json('render_html')));
    }

    public function test_renaming_a_dish_or_a_meal_after_the_sale_changes_nothing_on_a_closed_day(): void
    {
        [$w, $m, $reading] = $this->closedSlipDay();
        $before = $this->printedSection($this->print($w, $reading)->json('render_html'));
        $stored = $reading->totals_snapshot;

        foreach (Dish::orderBy('name')->get() as $index => $dish) {
            $dish->update(['name' => 'Renamed dish '.$index]);
        }
        foreach (Meal::orderBy('name')->get() as $index => $meal) {
            $meal->update(['name' => 'Renamed meal '.$index]);
        }
        DB::table('products')->update(['name' => 'Renamed product']);
        DB::table('sales')->update(['grand_total' => '1.00']);

        $after = $this->printedSection($this->print($w, $reading, 'manager')->json('render_html'));

        $this->assertSame($before, $after, 'the printout comes from the frozen section');
        $this->assertEquals($stored, $reading->refresh()->totals_snapshot);
    }

    public function test_a_day_closed_before_the_section_existed_still_prints_without_it(): void
    {
        [$w, , $reading] = $this->closedSlipDay();
        $legacy = $reading->totals_snapshot;
        unset($legacy['sales_by_meal_type']);
        $reading->update(['totals_snapshot' => $legacy]);

        $html = $this->print($w, $reading)->json('render_html');

        $this->assertStringContainsString('END OF DAY REPORT', $html);
        $this->assertStringContainsString('PAYMENTS', $html);
        $this->assertStringNotContainsString('SALES BY MEAL TYPE', $html);
    }

    public function test_a_slip_day_with_no_sales_has_no_section(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $shiftId = $w['cashierShift']->id;
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '0.00'], $this->key())->assertOk();
        $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
        $reading = ZReading::where('fiscal_day_id', $w['cashierShift']->fiscal_day_id)->sole();

        $html = $this->print($w, $reading)->json('render_html');

        $this->assertArrayNotHasKey('sales_by_meal_type', $reading->totals_snapshot);
        $this->assertStringNotContainsString('SALES BY MEAL TYPE', $html);
        $this->assertStringContainsString('END OF DAY REPORT', $html);
    }

    public function test_an_invoice_mode_day_keeps_exactly_its_old_snapshot_and_its_80mm_z_reading(): void
    {
        $w = $this->world();
        $this->ring($w);
        $this->ring($w, [[$w['product2'], '1']], 'GCASH');
        $shiftId = $w['cashierShift']->id;
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$shiftId}/close", ['declared_cash' => '1200.00'], $this->key())->assertOk();
        $this->asUser($w['admin'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
        $reading = ZReading::where('fiscal_day_id', $w['cashierShift']->fiscal_day_id)->sole();

        $keys = array_keys($reading->totals_snapshot);
        sort($keys);
        $expected = [
            'accumulated_grand_total_sales_before', 'accumulated_grand_total_sales_after', 'gross_sales', 'taxable_sales', 'vat_exempt_sales',
            'zero_rated_sales', 'vat_amount', 'non_vat_sales', 'payment_breakdown', 'void_total', 'refund_total', 'z_counter', 'reset_counter',
        ];
        sort($expected);
        $this->assertSame($expected, $keys, 'the stored snapshot of an invoice-mode day has exactly its old keys');
        $html = $this->print($w, $reading)->json('render_html');
        $this->assertStringContainsString('Z-READING', $html);
        $this->assertStringContainsString('@page{size:80mm auto;margin:3mm}', $html);
        $this->assertStringNotContainsString('SALES BY MEAL TYPE', $html);
    }

    // ------------------------------------------------------------------ the standalone report

    private function report(array $w, array $params, string $as = 'manager', string $accept = 'application/json'): TestResponse
    {
        return $this->asUser($w[$as])->withHeaders(['Accept' => $accept])->get('/api/v1/reports/sales-by-meal-type?'.http_build_query($params));
    }

    public function test_the_standalone_report_matches_the_printout_for_the_same_day(): void
    {
        [$w, , $reading] = $this->closedSlipDay();
        $date = $reading->business_date->toDateString();
        $section = $reading->totals_snapshot['sales_by_meal_type'];

        $response = $this->report($w, ['from' => $date, 'to' => $date])->assertOk();

        $this->assertSame(['from' => $date, 'to' => $date], $response->json('filters_applied'));
        $this->assertSame($section['net_amount'], $response->json('summary.net_sales'));
        $this->assertSame($section['sold_amount'], $response->json('summary.sales'));
        $this->assertSame($section['returned_amount'], $response->json('summary.refunds'));
        $this->assertSame($section['voided_amount'], $response->json('summary.voids'));
        $expected = [];
        foreach ($section['meal_types'] as $type) {
            foreach ($type['items'] as $item) {
                $expected[] = [$type['meal_type'], $item['item'], $item['kind'], $item['quantity_net'], $item['net_amount']];
            }
        }
        $this->assertSame($expected, array_map(fn (array $row) => [$row['meal_type'], $row['item'], $row['item_type'], $row['quantity_net'], $row['net_amount']], $response->json('rows')));
        $this->assertSame($section['net_amount'], array_reduce($response->json('rows'), fn (string $carry, array $row) => bcadd($carry, $row['net_amount'], 2), '0.00'));
    }

    public function test_the_report_is_computed_on_read_so_it_also_shows_a_day_that_is_still_open(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->at('2026-10-26 08:00:00');
        $m = $this->menu($w);
        $this->at('2026-10-26 12:00:00');
        $this->sale($w, [$this->standardItem($m, true)], '83.00');
        $date = $w['managerShift']->fiscalDay->business_date->toDateString();

        $response = $this->report($w, ['from' => $date, 'to' => $date])->assertOk();

        $this->assertSame('83.00', $response->json('summary.net_sales'));
        $this->assertSame(['Standard Meal'], array_column($response->json('summary.meal_types'), 'meal_type'));
    }

    public function test_the_report_covers_every_till_of_the_store_in_a_date_range_and_never_another_store(): void
    {
        [$w, $m, $reading] = $this->closedSlipDay();
        // The other till (1) sold a Standard Meal in its own business day, the same date.
        $this->sale($w, [$this->standardItem($m, false)], '68.00', 'CASH', null, 'cashier', 1);
        $date = $reading->business_date->toDateString();

        $both = $this->report($w, ['from' => $date, 'to' => $date])->assertOk();
        $this->assertSame('389.00', $both->json('summary.net_sales'), 'the closed day (321) plus the other till (68)');

        $none = $this->report($w, ['from' => '1999-01-01', 'to' => '1999-01-02'])->assertOk();
        $this->assertSame([], $none->json('rows'));
        $this->assertSame('0.00', $none->json('summary.net_sales'));

        $other = $this->world();
        $this->slipMode($other);
        $this->assertSame([], $this->report($other, ['from' => $date, 'to' => $date])->assertOk()->json('rows'), 'another store sees none of this');
    }

    public function test_the_report_needs_report_view_a_cashier_is_refused_and_a_manager_is_not(): void
    {
        [$w, , $reading] = $this->closedSlipDay();
        $date = $reading->business_date->toDateString();

        $this->report($w, ['from' => $date, 'to' => $date], 'cashier')->assertStatus(403);
        $this->report($w, ['from' => $date, 'to' => $date], 'manager')->assertOk();
        $this->report($w, ['from' => $date, 'to' => $date], 'admin')->assertOk();
    }

    public function test_the_csv_export_has_the_pinned_columns_and_one_row_per_item(): void
    {
        [$w, , $reading] = $this->closedSlipDay();
        $date = $reading->business_date->toDateString();

        $response = $this->report($w, ['from' => $date, 'to' => $date], 'manager', 'text/csv')->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", trim($response->getContent()))));
        $this->assertSame('meal_type,item,item_type,quantity_sold,sold_amount,quantity_returned,returned_amount,quantity_net,net_amount', $lines[0]);
        $this->assertContains('"Combo Meal","Beef nilaga + Pinakbet",DISH,1.000,85.00,0.000,0.00,1.000,85.00', array_map('trim', $lines));
        $this->assertContains('"Standard Meal","Whole rice",ADDON,1.000,15.00,1.000,15.00,0.000,0.00', array_map('trim', $lines));
        $this->assertCount(1 + 5, $lines, 'Combo, Snacks, Standard (dish and rice), Other items: the rows are items, not subtotals');
    }
}
