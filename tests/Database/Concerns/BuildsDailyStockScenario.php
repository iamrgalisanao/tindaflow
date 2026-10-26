<?php

namespace Tests\Database\Concerns;

use App\Models\Dish;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Models\User;
use App\Services\Menu\DishAlaCarteService;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use App\Services\Overrides\OverrideCodeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * A canteen with DAILY dishes for the slice-B tests (ADR-017): Beef nilaga, Chicken adobo and Pinakbet count daily stock; Standard Meal (any dish) and
 * Combo Meal (a main and a vegetable side) draw the client's portions (viand 1.0, Combo main 1.0, Combo side 1.0); rice is an add-on and draws nothing; and each
 * dish has an ala carte product at 2.0 portions. Built through the same services as the admin screens. Needs `world()` from BuildsSalesScenario.
 */
trait BuildsDailyStockScenario
{
    use BuildsSalesScenario;

    private const MANAGER_CODE = '482916';

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function dailyMenu(array $w): array
    {
        $admin = $w['admin'];
        $lunch = MenuGroup::create(['store_id' => $w['storeId'], 'name' => 'Lunch', 'sort_order' => 1, 'active' => true]);
        $standardGroup = MenuGroup::create(['store_id' => $w['storeId'], 'parent_id' => $lunch->id, 'name' => 'Standard Meal', 'sort_order' => 1, 'active' => true]);
        $comboGroup = MenuGroup::create(['store_id' => $w['storeId'], 'parent_id' => $lunch->id, 'name' => 'Combo Meal', 'sort_order' => 2, 'active' => true]);
        $alaGroup = MenuGroup::create(['store_id' => $w['storeId'], 'name' => 'Ala carte', 'sort_order' => 3, 'active' => true]);

        $categories = app(DishCategoryService::class);
        $beef = $categories->create($admin, ['name' => 'Beef']);
        $veg = $categories->create($admin, ['name' => 'Vegetable']);
        $dishes = app(DishService::class);
        $nilaga = $dishes->create($admin, ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $adobo = $dishes->create($admin, ['name' => 'Beef adobo', 'dish_category_id' => $beef->id]);
        $pinakbet = $dishes->create($admin, ['name' => 'Pinakbet', 'dish_category_id' => $veg->id]);
        foreach ([$nilaga, $adobo, $pinakbet] as $dish) {
            $dishes->update($admin, $dish->id, ['stock_mode' => 'DAILY']);
        }

        $options = app(MealOptionService::class);
        $groups = app(MealOptionGroupService::class);
        $standard = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand', 'menu_group_id' => $standardGroup->id]);
        $viand = MealOptionGroup::where('meal_id', $standard->id)->where('prices_the_meal', true)->sole();
        $nilagaOption = $options->create($admin, $viand->id, ['dish_id' => $nilaga->id]);
        $adoboOption = $options->create($admin, $viand->id, ['dish_id' => $adobo->id]);
        $pinakbetOption = $options->create($admin, $viand->id, ['dish_id' => $pinakbet->id]);
        $rice = $groups->create($admin, $standard->id, ['name' => 'Extra rice', 'kind' => 'ADDON', 'min_select' => 0, 'max_select' => 1, 'popup_after_choice' => true]);
        $wholeRice = $options->create($admin, $rice->id, ['label' => 'Whole rice']);

        $combo = app(MealService::class)->create($admin, ['name' => 'Combo Meal', 'pricing_group_name' => 'Main', 'menu_group_id' => $comboGroup->id]);
        $main = MealOptionGroup::where('meal_id', $combo->id)->where('prices_the_meal', true)->sole();
        $comboNilaga = $options->create($admin, $main->id, ['dish_id' => $nilaga->id]);
        $side = $groups->create($admin, $combo->id, ['name' => 'Side', 'kind' => 'CHOICE', 'min_select' => 1, 'max_select' => 1]);
        $comboPinakbet = $options->create($admin, $side->id, ['dish_id' => $pinakbet->id]);

        $prices = app(MenuPriceService::class);
        $prices->set($admin, $standard->id, ['dish_category_id' => $beef->id, 'amount' => '68.00']);
        $prices->set($admin, $standard->id, ['dish_category_id' => $veg->id, 'amount' => '60.00']);
        $prices->set($admin, $standard->id, ['meal_option_id' => $wholeRice->id, 'amount' => '15.00']);
        $prices->set($admin, $combo->id, ['dish_category_id' => $beef->id, 'amount' => '85.00']);

        $made = app(DishAlaCarteService::class)->create($admin, [
            'menu_group_id' => $alaGroup->id,
            'items' => [['dish_id' => $nilaga->id, 'price' => '120.00'], ['dish_id' => $pinakbet->id, 'price' => '80.00']],
        ]);
        $alaNilaga = Product::findOrFail($made['created'][0]['product_id']);
        $alaPinakbet = Product::findOrFail($made['created'][1]['product_id']);

        return compact(
            'beef', 'veg', 'nilaga', 'adobo', 'pinakbet', 'standard', 'viand', 'nilagaOption', 'adoboOption', 'pinakbetOption', 'rice', 'wholeRice',
            'combo', 'main', 'comboNilaga', 'side', 'comboPinakbet', 'alaGroup', 'alaNilaga', 'alaPinakbet',
        );
    }

    /** @return array<string, mixed> a Standard Meal item as the till sends it */
    private function standardItem(array $m, MealOption $viandOption, bool $withRice = false): array
    {
        return ['meal' => [
            'meal_id' => $m['standard']->id,
            'choices' => [['group_id' => $m['viand']->id, 'option_id' => $viandOption->id]],
            'addons' => $withRice ? [$m['wholeRice']->id] : [],
        ], 'quantity' => '1'];
    }

    /** @return array<string, mixed> a Combo Meal item (a main and a vegetable side) */
    private function comboItem(array $m, ?MealOption $main = null, ?MealOption $side = null): array
    {
        return ['meal' => [
            'meal_id' => $m['combo']->id,
            'choices' => [
                ['group_id' => $m['main']->id, 'option_id' => ($main ?? $m['comboNilaga'])->id],
                ['group_id' => $m['side']->id, 'option_id' => ($side ?? $m['comboPinakbet'])->id],
            ],
            'addons' => [],
        ], 'quantity' => '1'];
    }

    /** @return array<string, mixed> */
    private function alaItem(Product $product, string $quantity = '1'): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity];
    }

    /**
     * Rings the cart as the cashier at T1 in CASH. The payment is a generous round figure: the tests here are about stock, and change is fine.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function sellItems(array $w, array $items, array $extra = [], string $who = 'cashier', string $till = 'enroll1'): TestResponse
    {
        return $this->asUser($w[$who], $w[$till])->postJson('/api/v1/sales', [
            'items' => $items,
            'payments' => [['method' => 'CASH', 'amount' => '10000.00']],
        ] + $extra, $this->key());
    }

    /** The store's stock rules row. */
    private function stockRules(array $w, array $values = []): void
    {
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'created_at' => now(), 'updated_at' => now(),
        ] + $values);
    }

    /**
     * The manager enters the day's quantities at their own till.
     *
     * @param  array<string, string>  $cooked  dish (or product) id => total cooked
     */
    private function enterCooked(array $w, array $cooked, array $extra = []): TestResponse
    {
        $lines = [];
        foreach ($cooked as $id => $quantity) {
            $lines[] = (Dish::whereKey($id)->exists() ? ['dish_id' => $id] : ['product_id' => $id]) + ['cooked' => $quantity];
        }

        return $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/daily-stock/entries', ['lines' => $lines] + $extra, $this->key());
    }

    private function managerCode(User $manager, string $code = self::MANAGER_CODE): void
    {
        if (! DB::table('user_override_codes')->where('user_id', $manager->id)->exists()) {
            app(OverrideCodeService::class)->setOwn($manager, 'password', $code);
        }
    }

    /** What is left of a dish today (its pool's daily balance for the given stock date). */
    private function left(Dish $dish, ?string $stockDate = null): ?string
    {
        $poolId = Dish::findOrFail($dish->id)->stock_product_id;
        $query = DB::table('daily_stock_balances')->where('product_id', $poolId);
        if ($stockDate !== null) {
            $query->where('stock_date', $stockDate);
        }
        $value = $query->orderByDesc('stock_date')->value('quantity_on_hand');

        return $value === null ? null : bcadd((string) $value, '0', 3);
    }
}
