<?php

namespace Database\Seeders;

use App\Models\Dish;
use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use App\Models\MenuGroup;
use App\Models\MenuPrice;
use App\Models\Store;
use App\Models\User;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;

/**
 * ADR-016 slice 1.2: the canteen's starting meal menu -- dish categories, the client's dishes, Standard Meal and Combo Meal
 * with their option groups, the rice add-ons, and the opening prices.
 *
 * Demo/canteen data only. It is NOT called by DemoDataSeeder, and it refuses to run in production; run it on demand with
 * `php artisan db:seed --class=MealMenuSeeder`. A real store builds and prices the same menu on the Menu admin screens, which
 * are how the client changes any of it. It goes through the same services as the screens, so every rule (one pricing group
 * per meal, prices only for categories the meal offers) applies and the audit trail shows an actor.
 *
 * Idempotent: anything that already exists (matched by name, hidden or not) is left exactly as it is, and an opening price is
 * written only for a price key that has no price row yet -- so re-seeding never undoes an admin's hide, reorder, re-placement
 * or price change. Matching is by name, so a seeded dish or meal that was RENAMED is not found and would be created again
 * under its original name: seed once, then manage the menu on the screens. The audit actor is the store's ADMIN (or, failing that, any user); with no user at all the seeder stops.
 */
class MealMenuSeeder extends Seeder
{
    /** @var array<string, list<string>> dish category => its dishes, in menu order. Chicken, Pork and Fish are one price category; the dishes keep their own names. */
    public const DISHES = [
        'Beef' => ['Beef nilaga', 'Beef caldereta', 'Beef pochero', 'Beef mechado'],
        'Chicken/Pork/Fish' => [
            'Pork kaldereta', 'Pork menudo', 'Pork pochero', 'Pork nilaga',
            'Chicken adobo', 'Chicken pastel', 'Chicken pastil', 'Chicken afritada',
            'Fried fish', 'Fish fillet', 'Paksiw', 'Fish sinigang',
        ],
        'Vegetable' => ['Pinakbet', 'Chopsuey', 'Laing', 'Ginisang toge', 'Ginisang sayote'],
    ];

    /** Standard Meal: the viand prices the meal by the dish's category. */
    public const STANDARD_PRICES = ['Beef' => '68.00', 'Chicken/Pork/Fish' => '60.00', 'Vegetable' => '60.00'];

    /** Combo Meal: PENDING CLIENT CONFIRMATION (canteen-build-plan.md open item 1) -- 85 / 80 are an assumption. */
    public const COMBO_PRICES = ['Beef' => '85.00', 'Chicken/Pork/Fish' => '80.00'];

    public const COMBO_PENDING_NOTE = 'Pending client confirmation: assumed opening price';

    /** @var array<string, string> the optional rice add-ons, offered in a pop-up after the main dish is chosen */
    public const RICE = ['Whole rice' => '15.00', 'Half rice' => '10.00'];

    public function run(?Store $store = null): void
    {
        if (app()->environment('production')) {
            $this->command?->error('MealMenuSeeder refused to run in the production environment.');

            return;
        }

        $this->seed($store ?? Store::firstOrCreate(['name' => env('TINDAFLOW_INITIAL_STORE_NAME', 'My Sari-Sari Store')]));
    }

    /** The seeding itself, without the production guard: `tindaflow:load-canteen-menu` calls it after asking the operator. */
    public function seed(Store $store): void
    {
        $actor = User::where('store_id', $store->id)->where('role', 'ADMIN')->orderBy('created_at')->first()
            ?? User::where('store_id', $store->id)->orderBy('created_at')->first();
        if ($actor === null) {
            $this->command?->error("MealMenuSeeder needs a user in '{$store->name}' to attribute the menu to. Create the admin first.");

            return;
        }

        $categories = $this->categories($actor);
        $dishes = $this->dishes($actor, $categories);

        $lunch = MenuGroup::where('store_id', $store->id)->whereNull('parent_id')->where('name', 'Lunch')->first();
        $subGroup = fn (string $name) => $lunch === null ? null : MenuGroup::where('parent_id', $lunch->id)->where('name', $name)->value('id');

        $standard = $this->meal($actor, 'Standard Meal', 'Viand', $subGroup('Standard Meal'));
        $this->offerDishes($actor, $this->groupNamed($standard, 'Viand'), $dishes, ['Beef', 'Chicken/Pork/Fish', 'Vegetable']);
        $this->price($actor, $standard, $categories, self::STANDARD_PRICES, null);
        $this->rice($actor, $standard);

        $combo = $this->meal($actor, 'Combo Meal', 'Main', $subGroup('Combo Meal'));
        $this->offerDishes($actor, $this->groupNamed($combo, 'Main'), $dishes, ['Beef', 'Chicken/Pork/Fish']);
        $this->price($actor, $combo, $categories, self::COMBO_PRICES, self::COMBO_PENDING_NOTE);
        $side = $this->group($actor, $combo, 'Side', MealOptionGroup::CHOICE, 1, 1, false);
        $this->offerDishes($actor, $side, $dishes, ['Vegetable']);
        $this->rice($actor, $combo);

        $this->command?->info("Seeded the canteen meal menu into '{$store->name}'. Combo Meal prices are pending client confirmation.");
    }

    /** @return array<string, DishCategory> */
    private function categories(User $actor): array
    {
        $service = app(DishCategoryService::class);
        $categories = [];

        foreach (array_keys(self::DISHES) as $name) {
            $categories[$name] = $this->named(DishCategory::where('store_id', $actor->store_id), $name)
                ?? $service->create($actor, ['name' => $name]);
        }

        return $categories;
    }

    /**
     * @param  array<string, DishCategory>  $categories
     * @return array<string, list<Dish>> dishes by category name
     */
    private function dishes(User $actor, array $categories): array
    {
        $service = app(DishService::class);
        $dishes = [];

        foreach (self::DISHES as $categoryName => $names) {
            foreach ($names as $name) {
                $dishes[$categoryName][] = $this->named(Dish::where('store_id', $actor->store_id), $name)
                    ?? $service->create($actor, ['name' => $name, 'dish_category_id' => $categories[$categoryName]->id]);
            }
        }

        return $dishes;
    }

    private function meal(User $actor, string $name, string $pricingGroupName, ?string $menuGroupId): Meal
    {
        return $this->named(Meal::where('store_id', $actor->store_id), $name)
            ?? app(MealService::class)->create($actor, ['name' => $name, 'pricing_group_name' => $pricingGroupName, 'menu_group_id' => $menuGroupId]);
    }

    private function groupNamed(Meal $meal, string $name): MealOptionGroup
    {
        return $this->named(MealOptionGroup::where('meal_id', $meal->id), $name)
            ?? MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->firstOrFail();
    }

    private function group(User $actor, Meal $meal, string $name, string $kind, int $min, int $max, bool $popup): MealOptionGroup
    {
        return $this->named(MealOptionGroup::where('meal_id', $meal->id), $name)
            ?? app(MealOptionGroupService::class)->create($actor, $meal->id, [
                'name' => $name, 'kind' => $kind, 'min_select' => $min, 'max_select' => $max, 'popup_after_choice' => $popup,
            ]);
    }

    /**
     * @param  array<string, list<Dish>>  $dishes
     * @param  list<string>  $categoryNames
     */
    private function offerDishes(User $actor, MealOptionGroup $group, array $dishes, array $categoryNames): void
    {
        $service = app(MealOptionService::class);
        $offered = MealOption::where('meal_option_group_id', $group->id)->pluck('dish_id')->all();

        foreach ($categoryNames as $categoryName) {
            foreach ($dishes[$categoryName] as $dish) {
                if (! in_array($dish->id, $offered, true)) {
                    $service->create($actor, $group->id, ['dish_id' => $dish->id]);
                }
            }
        }
    }

    /**
     * An opening price for each category, only where the meal has no price row for it yet.
     *
     * @param  array<string, DishCategory>  $categories
     * @param  array<string, string>  $prices
     */
    private function price(User $actor, Meal $meal, array $categories, array $prices, ?string $reason): void
    {
        $service = app(MenuPriceService::class);

        foreach ($prices as $categoryName => $amount) {
            $categoryId = $categories[$categoryName]->id;
            if (! MenuPrice::where('meal_id', $meal->id)->where('dish_category_id', $categoryId)->exists()) {
                $service->set($actor, $meal->id, ['dish_category_id' => $categoryId, 'amount' => $amount, 'reason' => $reason]);
            }
        }
    }

    private function rice(User $actor, Meal $meal): void
    {
        $group = $this->group($actor, $meal, 'Extra rice', MealOptionGroup::ADDON, 0, 1, true);
        $options = app(MealOptionService::class);
        $prices = app(MenuPriceService::class);

        foreach (self::RICE as $label => $amount) {
            $option = $this->labelled(MealOption::where('meal_option_group_id', $group->id), $label)
                ?? $options->create($actor, $group->id, ['label' => $label]);
            if (! MenuPrice::where('meal_option_id', $option->id)->exists()) {
                $prices->set($actor, $meal->id, ['meal_option_id' => $option->id, 'amount' => $amount]);
            }
        }
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return T|null
     */
    private function named($query, string $name)
    {
        return $query->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    private function labelled($query, string $label): ?MealOption
    {
        return $query->whereRaw('lower(label) = ?', [mb_strtolower($label)])->first();
    }
}
