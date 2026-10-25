<?php

namespace Database\Factories;

use App\Models\Meal;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds the meal row and its hidden backing product only. The services create a meal with its pricing group; tests that need
 * the whole configuration call MealService, or add MealOptionGroup rows themselves.
 *
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    protected $model = Meal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $storeId = Store::factory()->create()->id;

        return [
            'store_id' => $storeId,
            'menu_group_id' => null,
            'backing_product_id' => Product::factory()->create([
                'store_id' => $storeId, 'barcode' => null, 'track_inventory' => false, 'active' => false, 'menu_hidden' => true,
                'tax_class' => 'NON_VAT', 'selling_price' => '0.00',
            ])->id,
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
