<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\MealOption;
use App\Models\MealOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A dish option by default; the add-on option needs a backing product, so tests build those through MealOptionService.
 *
 * @extends Factory<MealOption>
 */
class MealOptionFactory extends Factory
{
    protected $model = MealOption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meal_option_group_id' => MealOptionGroup::factory(),
            'dish_id' => Dish::factory(),
            'label' => null,
            'addon_product_id' => null,
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
