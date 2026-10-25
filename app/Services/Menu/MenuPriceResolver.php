<?php

namespace App\Services\Menu;

use App\Models\MenuPrice;
use Carbon\CarbonInterface;

/**
 * The one definition of "the price at an instant" (invariants.md MENU-004): of the rows for a key (meal + dish category, or an
 * add-on option), the one with the latest effective_from not after the instant. A row starting later is not in force yet; no
 * row means no price. The checkout (slice 1.3) resolves meal and add-on prices with this at `sold_at`.
 */
final class MenuPriceResolver
{
    public function mealPrice(string $mealId, string $dishCategoryId, CarbonInterface $at): ?MenuPrice
    {
        return MenuPrice::where('meal_id', $mealId)->where('dish_category_id', $dishCategoryId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function addonPrice(string $mealOptionId, CarbonInterface $at): ?MenuPrice
    {
        return MenuPrice::where('meal_option_id', $mealOptionId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** The first row of the key that starts after the instant, if one is scheduled. */
    public function nextMealPrice(string $mealId, string $dishCategoryId, CarbonInterface $after): ?MenuPrice
    {
        return MenuPrice::where('meal_id', $mealId)->where('dish_category_id', $dishCategoryId)
            ->where('effective_from', '>', $after)->orderBy('effective_from')->first();
    }

    public function nextAddonPrice(string $mealOptionId, CarbonInterface $after): ?MenuPrice
    {
        return MenuPrice::where('meal_option_id', $mealOptionId)->where('effective_from', '>', $after)->orderBy('effective_from')->first();
    }
}
