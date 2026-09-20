<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransferLine>
 */
class StockTransferLineFactory extends Factory
{
    protected $model = StockTransferLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $transfer = StockTransfer::factory()->create();

        return [
            'stock_transfer_id' => $transfer->id,
            'product_id' => Product::factory()->create(['store_id' => $transfer->store_id])->id,
            'quantity' => '5.000',
        ];
    }
}
