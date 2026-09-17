<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleVoid>
 */
class SaleVoidFactory extends Factory
{
    protected $model = SaleVoid::class;

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
     * Voided state populates the processing context atomically, per
     * invariants.md #67/#68 -- independent of the original sale's own
     * terminal/fiscal_day/shift.
     */
    public function voided(): static
    {
        return $this->state(function (array $attrs) {
            $sale = Sale::find($attrs['sale_id']);

            return [
                'status' => 'VOIDED',
                'approved_by' => User::factory()->state(['store_id' => $sale->store_id]),
                'resolved_at' => now(),
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
