<?php

namespace Database\Factories;

use App\Models\DishCategory;
use App\Models\Meal;
use App\Models\MenuPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A meal-by-dish-category price row.
 *
 * @extends Factory<MenuPrice>
 */
class MenuPriceFactory extends Factory
{
    protected $model = MenuPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $meal = Meal::factory()->create();

        return [
            'store_id' => $meal->store_id,
            'meal_id' => $meal->id,
            'dish_category_id' => DishCategory::factory()->create(['store_id' => $meal->store_id])->id,
            'meal_option_id' => null,
            'amount' => '60.00',
            'effective_from' => now()->subDay(),
            'changed_by' => User::factory()->create(['store_id' => $meal->store_id])->id,
            'reason' => null,
        ];
    }
}
