<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    protected $model = StockBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory()->create();

        return [
            'product_id' => $product->id,
            'location_id' => InventoryLocation::factory()->create(['store_id' => $product->store_id])->id,
            'quantity_on_hand' => fake()->randomFloat(3, 0, 100),
        ];
    }
}
