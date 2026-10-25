<?php

namespace App\Services\Menu;

use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use Carbon\CarbonInterface;

/**
 * The read-only menu payload of GET /menu (ADR-016 section 3): what the till may offer right now, with the price in force at
 * `$at` on every priced choice. It is the same visibility the checkout enforces -- the meal and its menu group are active, so
 * are the group, option, dish and dish category, and a choice with no price in force is left out because the checkout would
 * refuse it -- so a till that renders this payload cannot build a selection the server would reject for being hidden. Prices
 * here are for display and the cart preview; the server prices the sale again at its own sale instant.
 */
final class TillMenuBuilder
{
    public function __construct(private readonly MenuPriceResolver $prices) {}

    /**
     * @return array{as_of: string, dish_categories: list<array<string, mixed>>, meals: list<array<string, mixed>>}
     */
    public function build(string $storeId, CarbonInterface $at): array
    {
        $meals = Meal::where('store_id', $storeId)->where('active', true)
            ->with([
                'menuGroup.parent',
                'optionGroups' => fn ($groups) => $groups->where('active', true),
                'optionGroups.options' => fn ($options) => $options->where('active', true),
                'optionGroups.options.dish.dishCategory',
            ])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')
            ->get();

        $categories = [];
        $payload = [];
        foreach ($meals as $meal) {
            if (! MenuGroupPath::isVisible($meal->menuGroup)) {
                continue;
            }
            $built = $this->meal($meal, $at, $categories);
            if ($built !== null) {
                $payload[] = $built;
            }
        }

        $sorted = collect($categories)->sortBy([['sort_order', 'asc'], ['name', 'asc'], ['id', 'asc']])->values()->all();

        return ['as_of' => $at->toIso8601String(), 'dish_categories' => $sorted, 'meals' => $payload];
    }

    /**
     * @param  array<string, array<string, mixed>>  $categories  collects the dish categories the offered dishes belong to
     * @return array<string, mixed>|null null when the meal cannot be completed from what is offerable
     */
    private function meal(Meal $meal, CarbonInterface $at, array &$categories): ?array
    {
        $groups = [];
        $mealPrices = [];
        $mealCategories = [];
        $pricingGroupOffered = false;

        foreach ($meal->optionGroups as $group) {
            $options = [];
            foreach ($group->options as $option) {
                $built = $this->option($meal, $group, $option, $at, $mealPrices, $mealCategories);
                if ($built !== null) {
                    $options[] = $built;
                }
            }
            if ($group->min_select > 0 && count($options) < $group->min_select) {
                return null;
            }
            if ($group->prices_the_meal && $options !== []) {
                $pricingGroupOffered = true;
            }
            $groups[] = [
                'id' => $group->id,
                'name' => $group->name,
                'kind' => $group->kind,
                'min_select' => $group->min_select,
                'max_select' => $group->max_select,
                'prices_the_meal' => $group->prices_the_meal,
                'popup_after_choice' => $group->popup_after_choice,
                'step_order' => $group->step_order,
                'options' => $options,
            ];
        }
        if (! $pricingGroupOffered) {
            return null;
        }

        foreach ($mealCategories as $id => $category) {
            $categories[$id] = ['id' => $category->id, 'code' => $category->code, 'name' => $category->name, 'sort_order' => $category->sort_order];
        }

        return [
            'id' => $meal->id,
            'name' => $meal->name,
            'menu_group_id' => $meal->menu_group_id,
            'menu_group_path' => MenuGroupPath::of($meal->menuGroup),
            'sort_order' => $meal->sort_order,
            'prices' => collect($mealPrices)->map(fn (string $amount, string $categoryId) => ['dish_category_id' => $categoryId, 'amount' => $amount])->values()->all(),
            'option_groups' => $groups,
        ];
    }

    /**
     * @param  array<string, string>  $mealPrices  collects the current meal price of each dish category seen in the pricing group
     * @param  array<string, DishCategory>  $mealCategories
     * @return array<string, mixed>|null
     */
    private function option(Meal $meal, MealOptionGroup $group, MealOption $option, CarbonInterface $at, array &$mealPrices, array &$mealCategories): ?array
    {
        if ($group->kind === MealOptionGroup::ADDON) {
            $price = $option->addon_product_id === null ? null : $this->prices->addonPrice($option->id, $at);

            return $price === null ? null : [
                'id' => $option->id,
                'label' => $option->label,
                'sort_order' => $option->sort_order,
                'dish' => null,
                'price' => (string) $price->amount,
            ];
        }

        $dish = $option->dish;
        if ($dish === null || ! $dish->active || ! $dish->dishCategory->active) {
            return null;
        }

        $price = null;
        if ($group->prices_the_meal) {
            $mealPrices[$dish->dish_category_id] ??= optional($this->prices->mealPrice($meal->id, $dish->dish_category_id, $at))->amount;
            if ($mealPrices[$dish->dish_category_id] === null) {
                unset($mealPrices[$dish->dish_category_id]);

                return null;
            }
            $price = (string) $mealPrices[$dish->dish_category_id];
        }
        $mealCategories[$dish->dish_category_id] = $dish->dishCategory;

        return [
            'id' => $option->id,
            'label' => $dish->name,
            'sort_order' => $option->sort_order,
            'dish' => ['id' => $dish->id, 'name' => $dish->name, 'dish_category_id' => $dish->dish_category_id],
            'price' => $price,
        ];
    }
}
