<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $store = Store::factory()->create();

        return [
            'store_id' => $store->id,
            'from_location_id' => InventoryLocation::factory()->create(['store_id' => $store->id])->id,
            'to_location_id' => InventoryLocation::factory()->create(['store_id' => $store->id])->id,
            'terminal_id' => null,
            'note' => null,
            'created_by' => User::factory()->create(['store_id' => $store->id])->id,
        ];
    }
}
