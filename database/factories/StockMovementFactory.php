<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory()->create();

        return [
            'product_id' => $product->id,
            'location_id' => InventoryLocation::factory()->create(['store_id' => $product->store_id])->id,
            'terminal_id' => null,
            'movement_type' => 'PURCHASE_RECEIPT',
            'quantity' => fake()->randomFloat(3, 1, 50),
            'reference_type' => null,
            'reference_id' => null,
            'reason' => null,
            'unit_cost' => $product->cost,
            'created_by' => User::factory()->create(['store_id' => $product->store_id])->id,
        ];
    }
}
