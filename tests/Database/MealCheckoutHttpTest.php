<?php

namespace Tests\Database;

use App\Models\Dish;
use App\Models\Invoice;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\User;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 1.3: checkout resolves a meal selection on the server. invariants.md MENU-001 (snapshots, nothing read from
 * the menu afterwards), MENU-002 (the server prices a meal), MENU-003 (a valid selection), MENU-004 (the price in force at the
 * sale instant). Built through the same services the admin screens use, and rung through the real POST /sales.
 */
class MealCheckoutHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /**
     * Standard Meal in Lunch > Standard Meal (Beef 68, Chicken/Pork/Fish 60) with a rice add-on group (whole 15, half 10), and a
     * Combo Meal (main Beef 85 / CPF 80, a vegetable side, the same rice). Pinakbet's category (Vegetable) has no Standard
     * price, so it is offered but unpriced there.
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

        $categories = app(DishCategoryService::class);
        $beef = $categories->create($admin, ['name' => 'Beef']);
        $cpf = $categories->create($admin, ['name' => 'Chicken/Pork/Fish']);
        $veg = $categories->create($admin, ['name' => 'Vegetable']);
        $dishes = app(DishService::class);
        $nilaga = $dishes->create($admin, ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $adobo = $dishes->create($admin, ['name' => 'Chicken adobo', 'dish_category_id' => $cpf->id]);
        $pinakbet = $dishes->create($admin, ['name' => 'Pinakbet', 'dish_category_id' => $veg->id]);

        $options = app(MealOptionService::class);
        $groups = app(MealOptionGroupService::class);

        $standard = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand', 'menu_group_id' => $standardGroup->id]);
        $viand = MealOptionGroup::where('meal_id', $standard->id)->where('prices_the_meal', true)->sole();
        $nilagaOption = $options->create($admin, $viand->id, ['dish_id' => $nilaga->id]);
        $adoboOption = $options->create($admin, $viand->id, ['dish_id' => $adobo->id]);
        $pinakbetOption = $options->create($admin, $viand->id, ['dish_id' => $pinakbet->id]);
        $rice = $groups->create($admin, $standard->id, ['name' => 'Extra rice', 'kind' => 'ADDON', 'min_select' => 0, 'max_select' => 1, 'popup_after_choice' => true]);
        $wholeRice = $options->create($admin, $rice->id, ['label' => 'Whole rice']);
        $halfRice = $options->create($admin, $rice->id, ['label' => 'Half rice']);

        $combo = app(MealService::class)->create($admin, ['name' => 'Combo Meal', 'pricing_group_name' => 'Main', 'menu_group_id' => $comboGroup->id]);
        $main = MealOptionGroup::where('meal_id', $combo->id)->where('prices_the_meal', true)->sole();
        $comboNilaga = $options->create($admin, $main->id, ['dish_id' => $nilaga->id]);
        $comboAdobo = $options->create($admin, $main->id, ['dish_id' => $adobo->id]);
        $side = $groups->create($admin, $combo->id, ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 1, 'max_select' => 1]);
        $comboPinakbet = $options->create($admin, $side->id, ['dish_id' => $pinakbet->id]);

        $prices = app(MenuPriceService::class);
        $set = fn (Meal $meal, array $data) => $prices->set($admin, $meal->id, $data);
        $set($standard, ['dish_category_id' => $beef->id, 'amount' => '68.00']);
        $set($standard, ['dish_category_id' => $cpf->id, 'amount' => '60.00']);
        $set($standard, ['meal_option_id' => $wholeRice->id, 'amount' => '15.00']);
        $set($standard, ['meal_option_id' => $halfRice->id, 'amount' => '10.00']);
        $set($combo, ['dish_category_id' => $beef->id, 'amount' => '85.00']);
        $set($combo, ['dish_category_id' => $cpf->id, 'amount' => '80.00']);

        return compact(
            'lunch', 'standardGroup', 'comboGroup', 'beef', 'cpf', 'veg', 'nilaga', 'adobo', 'pinakbet',
            'standard', 'viand', 'nilagaOption', 'adoboOption', 'pinakbetOption', 'rice', 'wholeRice', 'halfRice',
            'combo', 'main', 'comboNilaga', 'comboAdobo', 'side', 'comboPinakbet', 'set',
        );
    }

    /** @return array<string, mixed> one meal item as the till sends it */
    private function mealItem(array $m, MealOption $viandOption, ?MealOption $addon = null, array $extra = []): array
    {
        return array_replace([
            'meal' => [
                'meal_id' => $m['standard']->id,
                'choices' => [['group_id' => $m['viand']->id, 'option_id' => $viandOption->id]],
                'addons' => $addon === null ? [] : [$addon->id],
            ],
            'quantity' => '1',
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  list<array<string, mixed>>  $items
     */
    private function sell(array $w, array $items, string $total, ?string $key = null, ?User $as = null, $enroll = null): TestResponse
    {
        return $this->asUser($as ?? $w['cashier'], $enroll ?? $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => $items,
            'payments' => [['method' => 'CASH', 'amount' => $total]],
        ], $key === null ? $this->key() : ['Idempotency-Key' => $key]);
    }

    public function test_a_meal_with_rice_becomes_a_meal_line_and_an_add_on_line_priced_by_the_server(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $response = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00');

        $response->assertStatus(201)->assertJson(['grand_total' => '83.00', 'subtotal' => '83.00']);
        $items = $response->json('items');
        $this->assertCount(2, $items);

        $this->assertSame(1, $items[0]['line_number']);
        $this->assertSame('Standard Meal - Beef nilaga', $items[0]['product_name_snapshot']);
        $this->assertSame('68.00', $items[0]['unit_price_snapshot']);
        $this->assertSame('1.000', $items[0]['quantity']);
        $this->assertSame($m['standard']->id, $items[0]['meal_id']);
        $this->assertSame('Standard Meal', $items[0]['meal_name_snapshot']);
        $this->assertSame('Lunch > Standard Meal', $items[0]['menu_group_path_snapshot']);
        $this->assertNull($items[0]['parent_line_number']);
        $this->assertSame([[
            'group_name_snapshot' => 'Viand', 'option_label_snapshot' => 'Beef nilaga', 'dish_id' => $m['nilaga']->id,
            'dish_name_snapshot' => 'Beef nilaga', 'dish_category_snapshot' => 'Beef', 'price_snapshot' => '68.00', 'sort_order' => 0,
        ]], $items[0]['selections']);

        $this->assertSame(2, $items[1]['line_number']);
        $this->assertSame('Whole rice', $items[1]['product_name_snapshot']);
        $this->assertSame('15.00', $items[1]['unit_price_snapshot']);
        $this->assertSame(1, $items[1]['parent_line_number']);
        $this->assertSame($m['standard']->id, $items[1]['meal_id']);
        $this->assertSame('Extra rice', $items[1]['selections'][0]['group_name_snapshot']);
        $this->assertSame('15.00', $items[1]['selections'][0]['price_snapshot']);

        // The invoice is built from the snapshot: the line reads "<meal> - <dish>", rice is its own line.
        $invoiceId = $response->json('invoice.id');
        $html = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$invoiceId}")->assertOk()->json('render_html');
        $this->assertStringContainsString('Standard Meal - Beef nilaga', $html);
        $this->assertStringContainsString('Whole rice', $html);
        $this->assertStringContainsString('PHP 83.00', $html);

        $this->assertDatabaseCount('sale_item_selections', 2);
    }

    public function test_a_meal_in_a_vat_store_is_taxed_like_any_vatable_line(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $this->assertSame('VATABLE', Product::find($m['standard']->backing_product_id)->tax_class);
        $response = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201);

        $response->assertJson(['grand_total' => '83.00', 'tax_summary' => ['taxable_sales' => '74.11', 'vat_amount' => '8.89', 'non_vat_sales' => '0.00']]);
        $this->assertSame('VATABLE', $response->json('items.0.tax_classification_snapshot'));
        $this->assertSame('8.89', bcadd($response->json('items.0.tax_amount'), $response->json('items.1.tax_amount'), 2));
    }

    public function test_a_meal_in_a_non_vat_store_carries_no_tax(): void
    {
        $w = $this->world();
        DB::table('tax_registrations')->where('store_id', $w['storeId'])->update(['registration_type' => 'NON_VAT']);
        $m = $this->menu($w);

        $this->assertSame('NON_VAT', Product::find($m['standard']->backing_product_id)->tax_class);
        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201)
            ->assertJson(['grand_total' => '83.00', 'tax_summary' => ['vat_amount' => '0.00', 'non_vat_sales' => '83.00']]);
    }

    public function test_the_hidden_backing_products_stay_inactive_and_are_the_lines_products(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $response = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['halfRice'])], '78.00')->assertStatus(201);

        $this->assertSame($m['standard']->backing_product_id, $response->json('items.0.product_id'));
        $this->assertSame($m['halfRice']->addon_product_id, $response->json('items.1.product_id'));
        $this->assertFalse(Product::find($m['standard']->backing_product_id)->active);
        $this->assertFalse(Product::find($m['halfRice']->addon_product_id)->active);
    }

    public function test_a_price_sent_by_the_client_is_ignored(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $item = $this->mealItem($m, $m['nilagaOption'], $m['wholeRice'], ['unit_price' => '1.00', 'price' => '1.00', 'selling_price' => '1.00']);
        $item['meal']['price'] = '1.00';
        $item['meal']['unit_price'] = '1.00';
        $item['meal']['choices'][0]['price'] = '1.00';

        $this->sell($w, [$item], '83.00')->assertStatus(201)->assertJson(['grand_total' => '83.00']);
        $this->assertSame('68.00', SaleItem::where('line_number', 1)->latest('id')->first()->unit_price_snapshot);
    }

    public function test_a_payment_below_the_server_price_of_a_tampered_meal_is_insufficient(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], null, ['unit_price' => '1.00'])], '1.00')
            ->assertStatus(422)->assertJson(['error' => ['code' => 'INSUFFICIENT_PAYMENT']]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_meal_needs_its_required_group_and_respects_the_maximum(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $none = ['meal' => ['meal_id' => $m['standard']->id, 'choices' => [], 'addons' => []], 'quantity' => '1'];
        $this->sell($w, [$none], '68.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID', 'details' => ['group_id' => $m['viand']->id, 'selected' => 0]]]);

        $two = $this->mealItem($m, $m['nilagaOption']);
        $two['meal']['choices'][] = ['group_id' => $m['viand']->id, 'option_id' => $m['adoboOption']->id];
        $this->sell($w, [$two], '128.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);

        $bothRice = $this->mealItem($m, $m['nilagaOption'], $m['wholeRice']);
        $bothRice['meal']['addons'][] = $m['halfRice']->id;
        $this->sell($w, [$bothRice], '93.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);

        $twice = $this->mealItem($m, $m['nilagaOption'], $m['wholeRice']);
        $twice['meal']['addons'][] = $m['wholeRice']->id;
        $this->sell($w, [$twice], '98.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_combo_needs_one_main_and_one_side(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $combo = fn (array $choices) => ['meal' => ['meal_id' => $m['combo']->id, 'choices' => $choices, 'addons' => []], 'quantity' => '1'];

        $this->sell($w, [$combo([['group_id' => $m['main']->id, 'option_id' => $m['comboNilaga']->id]])], '85.00')
            ->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID', 'details' => ['group_id' => $m['side']->id]]]);

        $response = $this->sell($w, [$combo([
            ['group_id' => $m['main']->id, 'option_id' => $m['comboNilaga']->id],
            ['group_id' => $m['side']->id, 'option_id' => $m['comboPinakbet']->id],
        ])], '85.00')->assertStatus(201);

        // The side group does not price the meal: the Main's category does.
        $this->assertSame('Combo Meal - Beef nilaga + Pinakbet', $response->json('items.0.product_name_snapshot'));
        $this->assertSame('85.00', $response->json('items.0.unit_price_snapshot'));
        $this->assertSame(['Main', 'Side'], array_column($response->json('items.0.selections'), 'group_name_snapshot'));
        $this->assertSame(['85.00', null], array_column($response->json('items.0.selections'), 'price_snapshot'));
    }

    public function test_an_option_that_does_not_belong_to_the_meal_or_the_group_is_an_invalid_selection(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $this->sell($w, [$this->mealItem($m, $m['comboNilaga'])], '85.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);

        // A rice option sent as a choice, and a dish option sent as an add-on.
        $asChoice = $this->mealItem($m, $m['wholeRice']);
        $this->sell($w, [$asChoice], '15.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);
        $asAddon = $this->mealItem($m, $m['nilagaOption'], $m['adoboOption']);
        $this->sell($w, [$asAddon], '128.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);

        // A made-up id is the same: nothing is looked up outside the meal.
        $made = $this->mealItem($m, $m['nilagaOption']);
        $made['meal']['choices'][0]['option_id'] = '018f0000-0000-7000-8000-000000000000';
        $this->sell($w, [$made], '68.00')->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);
    }

    public function test_a_meal_is_one_plate_so_a_quantity_above_one_is_refused(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], null, ['quantity' => '2'])], '136.00')
            ->assertStatus(422)->assertJson(['error' => ['code' => 'MEAL_SELECTION_INVALID']]);
    }

    public function test_hidden_things_are_not_available(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $unavailable = fn (array $item) => $this->sell($w, [$item], '200.00')->assertStatus(409)->assertJson(['error' => ['code' => 'MEAL_NOT_AVAILABLE']]);

        $m['wholeRice']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption'], $m['wholeRice']));
        $m['wholeRice']->update(['active' => true]);

        $m['nilagaOption']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['nilagaOption']->update(['active' => true]);

        $m['nilaga']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['nilaga']->update(['active' => true]);

        $m['beef']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['beef']->update(['active' => true]);

        $m['rice']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption'], $m['wholeRice']));
        $m['rice']->update(['active' => true]);

        $m['standardGroup']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['standardGroup']->update(['active' => true]);

        // Hiding the top group hides the sub-group's meals without touching the sub-group's own flag.
        $m['lunch']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['lunch']->update(['active' => true]);

        $m['standard']->update(['active' => false]);
        $unavailable($this->mealItem($m, $m['nilagaOption']));
        $m['standard']->update(['active' => true]);

        $this->assertDatabaseCount('sales', 0);
        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201);
    }

    public function test_a_dish_whose_category_has_no_price_is_refused(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $this->sell($w, [$this->mealItem($m, $m['pinakbetOption'])], '60.00')
            ->assertStatus(409)->assertJson(['error' => ['code' => 'MEAL_NOT_AVAILABLE', 'details' => ['dish_category_id' => $m['veg']->id]]]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_price_that_starts_later_is_not_in_force_yet(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $m['set']($m['standard'], ['dish_category_id' => $m['veg']->id, 'amount' => '60.00', 'effective_from' => now()->addHour()->toIso8601String()]);

        $this->sell($w, [$this->mealItem($m, $m['pinakbetOption'])], '60.00')->assertStatus(409)->assertJson(['error' => ['code' => 'MEAL_NOT_AVAILABLE']]);
    }

    public function test_a_meal_of_another_store_is_not_available(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $other = Store::factory()->create();
        $m['standard']->update(['store_id' => $other->id]);

        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'])], '68.00')->assertStatus(409)->assertJson(['error' => ['code' => 'MEAL_NOT_AVAILABLE']]);
    }

    public function test_an_item_is_a_product_or_a_meal_never_both_or_neither(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $both = $this->mealItem($m, $m['nilagaOption'], null, ['product_id' => $w['product']->id]);
        $this->sell($w, [$both], '68.00')->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->sell($w, [['quantity' => '1']], '0.00')->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->sell($w, [['meal' => ['choices' => []], 'quantity' => '1']], '0.00')->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
    }

    public function test_a_backing_product_cannot_be_sold_directly(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        foreach ([$m['standard']->backing_product_id, $m['wholeRice']->addon_product_id] as $productId) {
            $this->sell($w, [['product_id' => $productId, 'quantity' => '1']], '1.00')
                ->assertStatus(422)->assertJson(['error' => ['code' => 'PRODUCT_INACTIVE']]);
        }

        // Even if something reactivated one, the hidden flag keeps it off the plain-product path.
        Product::whereKey($m['standard']->backing_product_id)->update(['active' => true]);
        $this->sell($w, [['product_id' => $m['standard']->backing_product_id, 'quantity' => '1']], '1.00')
            ->assertStatus(422)->assertJson(['error' => ['code' => 'PRODUCT_INACTIVE']]);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_a_replay_returns_the_same_sale_and_a_changed_selection_under_the_same_key_conflicts(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $key = (string) Str::uuid();
        $item = $this->mealItem($m, $m['nilagaOption'], $m['wholeRice']);

        $first = $this->sell($w, [$item], '83.00', $key)->assertStatus(201);
        $replay = $this->sell($w, [$item], '83.00', $key)->assertStatus(201);
        $this->assertSame($first->json('id'), $replay->json('id'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sale_item_selections', 2);

        $changedRice = $this->mealItem($m, $m['nilagaOption'], $m['halfRice']);
        $this->sell($w, [$changedRice], '83.00', $key)->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $changedDish = $this->mealItem($m, $m['adoboOption'], $m['wholeRice']);
        $this->sell($w, [$changedDish], '83.00', $key)->assertStatus(409)->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_renaming_hiding_and_repricing_afterwards_never_change_the_sale(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $sale = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201)->json();

        $before = $this->asUser($w['cashier'])->getJson('/api/v1/invoices/'.$sale['invoice']['id'])->json('render_html');
        $reportBefore = $this->asUser($w['manager'])->getJson('/api/v1/reports/sales-by-product?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->json();

        $this->travel(5)->minutes();
        $admin = $w['admin'];
        app(DishService::class)->update($admin, $m['nilaga']->id, ['name' => 'Beef tapa']);
        $m['set']($m['standard'], ['dish_category_id' => $m['beef']->id, 'amount' => '99.00']);
        $m['set']($m['standard'], ['meal_option_id' => $m['wholeRice']->id, 'amount' => '30.00']);
        app(DishService::class)->setActive($admin, $m['nilaga']->id, false);
        app(DishCategoryService::class)->update($admin, $m['beef']->id, ['name' => 'Prime beef']);
        app(MealService::class)->update($admin, $m['standard']->id, ['name' => 'Regular Meal']);
        app(MealOptionService::class)->update($admin, $m['wholeRice']->id, ['label' => 'Big rice']);

        $after = $this->asUser($w['cashier'])->getJson('/api/v1/sales/'.$sale['id'])->assertOk()->json();
        $this->assertSame($sale['items'], $after['items']);
        $this->assertSame('83.00', $after['grand_total']);
        $this->assertSame('Standard Meal - Beef nilaga', $after['items'][0]['product_name_snapshot']);
        $this->assertSame('Beef nilaga', $after['items'][0]['selections'][0]['dish_name_snapshot']);
        $this->assertSame('Beef', $after['items'][0]['selections'][0]['dish_category_snapshot']);
        $this->assertSame('Whole rice', $after['items'][1]['product_name_snapshot']);

        // A reprint renders from the stored snapshot only.
        $this->assertSame($before, $this->asUser($w['cashier'])->getJson('/api/v1/invoices/'.$sale['invoice']['id'])->json('render_html'));
        $reprint = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/invoices/'.$sale['invoice']['id'].'/reprints', [], $this->key())->assertStatus(201)->json('invoice.render_html');
        $this->assertStringContainsString('Standard Meal - Beef nilaga', $reprint);
        $this->assertStringNotContainsString('Beef tapa', $reprint);
        $this->assertStringNotContainsString('Regular Meal', $reprint);

        $this->assertNotEmpty($reportBefore['rows']);

        // The money reports are computed from the sale lines, so the new prices do not touch the day's figures.
        $reportAfter = $this->asUser($w['manager'])->getJson('/api/v1/reports/sales-by-product?from='.now()->subMinutes(5)->toDateString().'&to='.now()->toDateString())->assertOk()->json();
        $figures = fn (array $report) => array_map(fn ($row) => [$row['sku'], $row['quantity_sold'], $row['gross_sales'], $row['net_sales']], $report['rows']);
        $this->assertSame($figures($reportBefore), $figures($reportAfter));
        $this->assertStringNotContainsString('Beef tapa', json_encode($reportAfter));
    }

    public function test_repricing_mid_day_applies_to_the_next_sale_only(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $first = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'])], '68.00')->assertStatus(201);

        $this->travel(2)->minutes();
        $m['set']($m['standard'], ['dish_category_id' => $m['beef']->id, 'amount' => '70.00']);

        $second = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'])], '70.00')->assertStatus(201);
        $this->assertSame('70.00', $second->json('grand_total'));
        $this->assertSame('68.00', $this->asUser($w['cashier'])->getJson('/api/v1/sales/'.$first->json('id'))->json('grand_total'));
        $this->assertSame('68.00', $this->asUser($w['cashier'])->getJson('/api/v1/sales/'.$first->json('id'))->json('items.0.unit_price_snapshot'));
    }

    public function test_the_rice_add_on_line_can_be_refunded_alone(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $sale = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201)->json();
        $rice = $sale['items'][1];

        $refund = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $rice['id'], 'quantity' => '1', 'disposition' => 'DISPOSED']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '15.00']],
            'reason' => 'Customer did not want rice',
        ], $this->key());

        $refund->assertStatus(201)->assertJson(['status' => 'COMPLETED', 'refund_total' => '15.00']);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'PARTIALLY_REFUNDED']);
        $remaining = collect($refund->json('remaining_refundable'))->keyBy('sale_item_id');
        $this->assertSame('0.00', $remaining[$rice['id']]['remaining_amount']);
        $this->assertSame('68.00', $remaining[$sale['items'][0]['id']]['remaining_amount']);

        // The meal line then refunds as its own line, and the sale is fully refunded.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'DISPOSED']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '68.00']],
            'reason' => 'Dish was cold',
        ], $this->key())->assertStatus(201);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'status' => 'REFUNDED']);
    }

    public function test_a_meal_sale_can_be_voided_and_its_lines_return_to_stock_movements(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $sale = $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201)->json();

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong order'], $this->key())
            ->assertStatus(201)->assertJson(['status' => 'VOIDED']);
        $this->assertSame(4, DB::table('stock_movements')->whereIn('product_id', [$m['standard']->backing_product_id, $m['wholeRice']->addon_product_id])->count());
    }

    public function test_an_order_discount_over_a_meal_sale_still_foots(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $w['storeId'], 'terminal_id' => $w['t2']->id,
            'fiscal_installation_id' => DB::table('terminal_fiscal_installations')->where('terminal_id', $w['t1']->id)->value('fiscal_installation_id'),
            'effective_from' => now()->subYear(), 'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', [
            'items' => [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice']), ['product_id' => $w['product']->id, 'quantity' => '1']],
            'order_level_discount_amount' => '10.00',
            'payments' => [['method' => 'CASH', 'amount' => '173.00']],
        ], $this->key())->assertStatus(201);

        $this->assertSame('173.00', $response->json('grand_total'));
        $net = array_reduce($response->json('items'), fn ($c, $i) => bcadd($c, $i['net_line_amount'], 2), '0.00');
        $this->assertSame('173.00', $net);
        $this->assertSame([1, 2, 3], array_column($response->json('items'), 'line_number'));
    }

    public function test_a_plain_sale_is_unchanged_and_carries_no_meal_fields(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);

        $this->assertSame(
            ['id', 'line_number', 'product_id', 'product_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'unit_of_measure_snapshot', 'quantity', 'unit_price_snapshot', 'gross_line_amount', 'line_discount_amount', 'order_discount_eligible', 'allocated_order_discount_amount', 'net_line_amount', 'tax_classification_snapshot', 'tax_rate_snapshot', 'taxable_base', 'tax_amount'],
            array_keys($sale['items'][0]),
        );
        $item = SaleItem::find($sale['items'][0]['id']);
        $this->assertNull($item->meal_id);
        $this->assertNull($item->meal_name_snapshot);
        $this->assertNull($item->parent_line_number);
        $this->assertNull($item->menu_group_path_snapshot);
        $this->assertDatabaseCount('sale_item_selections', 0);

        $snapshot = Invoice::where('sale_id', $sale['id'])->firstOrFail()->invoice_snapshot_json;
        // jsonb stores keys in its own order, so compare the set: exactly the keys the snapshot always had.
        $keys = array_keys($snapshot['items'][0]);
        sort($keys);
        $this->assertSame(['line_number', 'net_line_amount', 'product_name', 'quantity', 'tax_amount', 'tax_classification', 'unit_price'], $keys);
        $this->assertSame($w['product']->name, $snapshot['items'][0]['product_name']);
    }

    public function test_a_plain_product_in_a_menu_group_records_its_menu_path(): void
    {
        $w = $this->world();
        $lunch = MenuGroup::create(['store_id' => $w['storeId'], 'name' => 'Beverages', 'sort_order' => 1, 'active' => true]);
        $w['product']->update(['menu_group_id' => $lunch->id]);

        $sale = $this->ring($w);

        $this->assertSame('Beverages', $sale['items'][0]['menu_group_path_snapshot']);
        $this->assertArrayNotHasKey('meal_id', $sale['items'][0]);
    }

    public function test_a_meal_can_be_rung_with_ordinary_products_in_one_sale(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $response = $this->sell($w, [
            ['product_id' => $w['product2']->id, 'quantity' => '2'],
            $this->mealItem($m, $m['adoboOption'], $m['halfRice']),
            $this->mealItem($m, $m['nilagaOption']),
        ], '238.00')->assertStatus(201);

        $this->assertSame([1, 2, 3, 4], array_column($response->json('items'), 'line_number'));
        $this->assertSame(['50.00', '60.00', '10.00', '68.00'], array_column($response->json('items'), 'unit_price_snapshot'));
        $this->assertSame([null, null, 2, null], array_map(fn ($item) => $item['parent_line_number'] ?? null, $response->json('items')));
    }

    public function test_the_till_menu_lists_what_is_sellable_with_current_prices(): void
    {
        $w = $this->world();
        $m = $this->menu($w);

        $menu = $this->asUser($w['cashier'])->getJson('/api/v1/menu')->assertOk()->json();

        $this->assertSame(['Beef', 'Chicken/Pork/Fish', 'Vegetable'], array_column($menu['dish_categories'], 'name'));
        $this->assertSame(['Standard Meal', 'Combo Meal'], array_column($menu['meals'], 'name'));
        $standard = $menu['meals'][0];
        $this->assertSame('Lunch > Standard Meal', $standard['menu_group_path']);
        $this->assertSame($m['standardGroup']->id, $standard['menu_group_id']);
        $this->assertEqualsCanonicalizing(
            [[$m['beef']->id, '68.00'], [$m['cpf']->id, '60.00']],
            array_map(fn ($p) => [$p['dish_category_id'], $p['amount']], $standard['prices']),
        );
        $this->assertSame(['Viand', 'Extra rice'], array_column($standard['option_groups'], 'name'));
        // Pinakbet has no Standard price so the server would refuse it: the till is not offered it.
        $this->assertSame(['Beef nilaga', 'Chicken adobo'], array_column($standard['option_groups'][0]['options'], 'label'));
        $this->assertSame(['68.00', '60.00'], array_column($standard['option_groups'][0]['options'], 'price'));
        $this->assertSame($m['nilaga']->id, $standard['option_groups'][0]['options'][0]['dish']['id']);
        $rice = $standard['option_groups'][1];
        $this->assertTrue($rice['popup_after_choice']);
        $this->assertSame([0, 1, 'ADDON'], [$rice['min_select'], $rice['max_select'], $rice['kind']]);
        $this->assertSame([['Whole rice', '15.00'], ['Half rice', '10.00']], array_map(fn ($o) => [$o['label'], $o['price']], $rice['options']));

        $combo = $menu['meals'][1];
        $this->assertSame(['Main', 'Side'], array_column($combo['option_groups'], 'name'));
        $this->assertSame([null], array_column($combo['option_groups'][1]['options'], 'price'));
    }

    public function test_the_till_menu_hides_hidden_and_unpriced_things_and_follows_the_clock(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $labels = fn () => array_column($this->asUser($w['cashier'])->getJson('/api/v1/menu')->json('meals.0.option_groups.0.options'), 'label');

        $m['adoboOption']->update(['active' => false]);
        $this->assertSame(['Beef nilaga'], $labels());

        $m['nilaga']->update(['active' => false]);
        // With nothing left to choose the meal itself is not offered.
        $names = array_column($this->asUser($w['cashier'])->getJson('/api/v1/menu')->json('meals'), 'name');
        $this->assertSame(['Combo Meal'], array_values(array_diff($names, ['Standard Meal'])));
        $this->assertNotContains('Standard Meal', $names);

        $m['standard']->update(['active' => true]);
        $m['lunch']->update(['active' => false]);
        $this->assertSame([], $this->asUser($w['cashier'])->getJson('/api/v1/menu')->json('meals'));
    }

    public function test_the_till_menu_shows_a_new_price_only_once_it_is_in_force(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $beefPrice = fn () => collect($this->asUser($w['cashier'])->getJson('/api/v1/menu')->json('meals.0.prices'))->firstWhere('dish_category_id', $m['beef']->id)['amount'];

        $m['set']($m['standard'], ['dish_category_id' => $m['beef']->id, 'amount' => '75.00', 'effective_from' => now()->addHour()->toIso8601String()]);
        $this->assertSame('68.00', $beefPrice());
        $this->travel(2)->hours();
        $this->assertSame('75.00', $beefPrice());
    }

    public function test_the_till_menu_is_scoped_to_the_store(): void
    {
        $w = $this->world();
        $this->menu($w);
        $other = User::factory()->create(['store_id' => Store::factory()->create()->id]);

        $this->asUser($other)->getJson('/api/v1/menu')->assertOk()->assertJson(['meals' => [], 'dish_categories' => []]);
    }

    public function test_the_till_menu_needs_a_session(): void
    {
        $this->getJson('/api/v1/menu')->assertStatus(401);
    }

    public function test_the_sales_lookup_shows_the_selection(): void
    {
        $w = $this->world();
        $m = $this->menu($w);
        $sale = $this->sell($w, [$this->mealItem($m, $m['adoboOption'], $m['halfRice'])], '70.00')->assertStatus(201)->json();

        $get = $this->asUser($w['cashier'])->getJson('/api/v1/sales/'.$sale['id'])->assertOk()->json();

        $this->assertSame('Chicken adobo', $get['items'][0]['selections'][0]['dish_name_snapshot']);
        $this->assertSame('Half rice', $get['items'][1]['selections'][0]['option_label_snapshot']);
        $this->assertSame(1, $get['items'][1]['parent_line_number']);
    }

    public function test_a_meal_and_its_rice_are_never_blocked_when_the_store_blocks_sales_without_stock(): void
    {
        // ADR-017 slice A: a meal is sold through a hidden, untracked backing product and rice is an untracked add-on, so neither demands stock.
        // Slice B will give a daily dish its own count; until then a meal sells even with nothing entered anywhere.
        $w = $this->world();
        $m = $this->menu($w);
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'block_sale_without_stock' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->sell($w, [$this->mealItem($m, $m['nilagaOption'], $m['wholeRice'])], '83.00')->assertStatus(201)->assertJson(['grand_total' => '83.00']);
        $this->assertSame(0, DB::table('stock_override_grants')->count());
    }
}
