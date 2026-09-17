<?php

namespace Database\Factories;

use App\Models\InventoryLocation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLocation>
 */
class InventoryLocationFactory extends Factory
{
    protected $model = InventoryLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Main Store',
            'is_default' => true,
        ];
    }

    public function notDefault(): static
    {
        return $this->state(fn () => ['is_default' => false]);
    }
}
