<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\StockCount;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockCount>
 */
class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $store = Store::factory()->create();

        return [
            'store_id' => $store->id,
            'location_id' => InventoryLocation::factory()->create(['store_id' => $store->id])->id,
            'status' => StockCount::OPEN,
            'note' => null,
            'created_by' => User::factory()->create(['store_id' => $store->id])->id,
        ];
    }
}
