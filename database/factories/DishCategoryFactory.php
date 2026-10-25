<?php

namespace Database\Factories;

use App\Models\DishCategory;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DishCategory>
 */
class DishCategoryFactory extends Factory
{
    protected $model = DishCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'store_id' => Store::factory(),
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'name' => $name,
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
