<?php

namespace Database\Factories;

use App\Models\FiscalDay;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A coherent graph needs the shift's fiscal_day to share the same
        // terminal (and, transitively, store) -- resolving the terminal
        // first and threading it into the fiscal_day avoids two
        // independently-random terminals that would violate the
        // sale/shift/fiscal_day terminal-consistency Stage 6 will check.
        $terminal = Terminal::factory()->create();

        return [
            'terminal_id' => $terminal->id,
            'fiscal_day_id' => FiscalDay::factory()->state([
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
            ]),
            'cashier_id' => User::factory()->state(['store_id' => $terminal->store_id]),
            'opening_cash' => fake()->randomFloat(2, 500, 5000),
            'opened_at' => now(),
            'status' => 'OPEN',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => 'CLOSED',
            'closed_at' => now(),
            'expected_cash' => $attrs['opening_cash'] ?? 1000,
            'declared_cash' => $attrs['opening_cash'] ?? 1000,
            'variance' => 0,
            'cash_sales' => 0,
            'non_cash_sales' => 0,
            'refunds_total' => 0,
            'cash_in_total' => 0,
            'cash_out_total' => 0,
        ]);
    }

    /**
     * Invalid-state mode for constraint tests: a second OPEN shift for a
     * cashier/terminal that already has one — expected to be REJECTED by
     * shifts_one_open_per_terminal / shifts_one_open_per_cashier.
     */
    public function secondOpenFor(Terminal $terminal, User $cashier, FiscalDay $fiscalDay): static
    {
        return $this->state(fn () => [
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'fiscal_day_id' => $fiscalDay->id,
            'status' => 'OPEN',
        ]);
    }
}
