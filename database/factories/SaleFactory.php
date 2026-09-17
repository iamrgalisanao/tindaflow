<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A coherent Sale needs store/terminal/fiscal_day/shift/cashier
        // to all agree -- resolving an OPEN Shift first (which itself
        // builds a coherent terminal+fiscal_day+cashier graph) and
        // deriving the rest from it, rather than resolving each
        // independently, is what keeps this factory's default output
        // usable without bypassing invariant #35 in ordinary usage.
        $shift = Shift::factory()->create();

        $subtotal = fake()->randomFloat(2, 20, 2000);
        $vatAmount = round($subtotal * 0.12 / 1.12, 2);
        $taxableSales = round($subtotal - $vatAmount, 2);

        return [
            'store_id' => $shift->fiscalDay->store_id,
            'terminal_id' => $shift->terminal_id,
            'fiscal_day_id' => $shift->fiscal_day_id,
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'transaction_number' => 'TXN-'.fake()->unique()->numerify('######'),
            'sold_at' => now(),
            'subtotal' => $subtotal,
            'order_level_discount_amount' => 0,
            'discount_total' => 0,
            'taxable_sales' => $taxableSales,
            'vat_exempt_sales' => 0,
            'zero_rated_sales' => 0,
            'vat_amount' => $vatAmount,
            'grand_total' => $subtotal,
            'status' => 'COMPLETED',
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => ['status' => 'VOIDED']);
    }

    public function partiallyRefunded(): static
    {
        return $this->state(fn () => ['status' => 'PARTIALLY_REFUNDED']);
    }

    public function refunded(): static
    {
        return $this->state(fn () => ['status' => 'REFUNDED']);
    }

    public function withIdempotencyKey(?string $key = null): static
    {
        return $this->state(fn () => ['idempotency_key' => $key ?? (string) Str::uuid()]);
    }
}
