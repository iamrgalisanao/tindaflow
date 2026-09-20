<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCountLine>
 */
class StockCountLineFactory extends Factory
{
    protected $model = StockCountLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $count = StockCount::factory()->create();

        return [
            'stock_count_id' => $count->id,
            'product_id' => Product::factory()->create(['store_id' => $count->store_id])->id,
            'counted_quantity' => '10.000',
            'expected_quantity' => '10.000',
            'counted_by' => $count->created_by,
            'counted_at' => now(),
        ];
    }
}
