<?php

namespace Database\Factories;

use App\Models\Dish;
use App\Models\DishCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dish>
 */
class DishFactory extends Factory
{
    protected $model = Dish::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = DishCategory::factory()->create();

        return [
            'store_id' => $category->store_id,
            'dish_category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    /** A dish of the given category, in that category's store. */
    public function in(DishCategory $category): static
    {
        return $this->state(fn () => ['store_id' => $category->store_id, 'dish_category_id' => $category->id]);
    }
}
