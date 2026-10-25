<?php

namespace Database\Factories;

use App\Models\Meal;
use App\Models\MealOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealOptionGroup>
 */
class MealOptionGroupFactory extends Factory
{
    protected $model = MealOptionGroup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'meal_id' => Meal::factory(),
            'name' => fake()->unique()->words(2, true),
            'kind' => MealOptionGroup::CHOICE,
            'min_select' => 1,
            'max_select' => 1,
            'prices_the_meal' => false,
            'popup_after_choice' => false,
            'step_order' => 0,
            'active' => true,
        ];
    }

    public function pricing(): static
    {
        return $this->state(fn () => ['kind' => MealOptionGroup::CHOICE, 'min_select' => 1, 'max_select' => 1, 'prices_the_meal' => true]);
    }

    public function addon(): static
    {
        return $this->state(fn () => ['kind' => MealOptionGroup::ADDON, 'min_select' => 0, 'max_select' => 1, 'popup_after_choice' => true]);
    }
}
