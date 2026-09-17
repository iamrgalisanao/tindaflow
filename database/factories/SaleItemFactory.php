<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    protected $model = SaleItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory()->create();
        $quantity = fake()->randomFloat(3, 1, 5);
        $grossLine = round($quantity * (float) $product->selling_price, 2);
        $vatAmount = round($grossLine * 0.12 / 1.12, 2);
        $taxableBase = round($grossLine - $vatAmount, 2);

        return [
            'sale_id' => Sale::factory(),
            'product_id' => $product->id,
            'line_number' => 1,
            'product_name_snapshot' => $product->name,
            'sku_snapshot' => $product->sku,
            'barcode_snapshot' => $product->barcode,
            'unit_of_measure_snapshot' => $product->unit_of_measure,
            'quantity' => $quantity,
            'unit_price_snapshot' => $product->selling_price,
            'gross_line_amount' => $grossLine,
            'line_discount_amount' => 0,
            'order_discount_eligible' => true,
            'allocated_order_discount_amount' => 0,
            'net_line_amount' => $grossLine,
            'tax_classification_snapshot' => $product->tax_class,
            'tax_rate_snapshot' => 0.12,
            'taxable_base' => $taxableBase,
            'tax_amount' => $vatAmount,
            'unit_cost_snapshot' => $product->cost,
        ];
    }

    /** Invalid-state mode for constraint tests: an out-of-range quantity beyond NUMERIC(10,3). */
    public function withOverflowQuantity(): static
    {
        return $this->state(fn () => ['quantity' => 99999999.999]);
    }
}
