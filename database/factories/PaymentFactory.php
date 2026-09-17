<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'method' => 'CASH',
            'amount' => fake()->randomFloat(2, 20, 2000),
            'recorded_at' => now(),
        ];
    }

    public function gcash(): static
    {
        return $this->state(fn () => ['method' => 'GCASH', 'reference_note' => fake()->numerify('GC-##########')]);
    }

    /** Invalid-state mode for constraint tests: overflows NUMERIC(12,2). */
    public function withOverflowAmount(): static
    {
        return $this->state(fn () => ['amount' => 99999999999.99]);
    }
}
