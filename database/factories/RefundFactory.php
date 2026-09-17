<?php

namespace Database\Factories;

use App\Models\Refund;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sale = Sale::factory()->create();

        return [
            'sale_id' => $sale->id,
            'requested_by' => $sale->cashier_id,
            'reason' => fake()->sentence(),
            'status' => 'REQUESTED',
            'requested_at' => now(),
        ];
    }

    /**
     * Completed state populates the processing context atomically, per
     * invariants.md #67/#68 -- the processing terminal/fiscal_day/shift
     * are NOT required to equal the original sale's own context.
     */
    public function completed(): static
    {
        return $this->state(function (array $attrs) {
            $sale = Sale::find($attrs['sale_id']);

            return [
                'status' => 'COMPLETED',
                'approved_by' => User::factory()->state(['store_id' => $sale->store_id]),
                'resolved_at' => now(),
                'refunded_at' => now(),
                'refund_total' => fake()->randomFloat(2, 10, 500),
                'terminal_id' => $sale->terminal_id,
                'fiscal_day_id' => $sale->fiscal_day_id,
                'shift_id' => $sale->shift_id,
            ];
        });
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'REJECTED',
            'resolved_at' => now(),
        ]);
    }
}
