<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 5, 200);

        return [
            'store_id' => Store::factory(),
            'sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'barcode' => fake()->unique()->ean13(),
            'name' => fake()->words(3, true),
            'unit_of_measure' => fake()->randomElement(['pc', 'pack', 'kg', 'sachet']),
            'cost' => $cost,
            'selling_price' => round($cost * 1.3, 2),
            'tax_class' => 'VATABLE',
            'track_inventory' => true,
            'reorder_level' => fake()->numberBetween(0, 20),
            'active' => true,
        ];
    }

    public function withoutBarcode(): static
    {
        return $this->state(fn () => ['barcode' => null]);
    }

    public function vatExempt(): static
    {
        return $this->state(fn () => ['tax_class' => 'VAT_EXEMPT']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
