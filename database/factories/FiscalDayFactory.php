<?php

namespace Database\Factories;

use App\Models\FiscalDay;
use App\Models\Store;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalDay>
 */
class FiscalDayFactory extends Factory
{
    protected $model = FiscalDay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'terminal_id' => Terminal::factory(),
            'business_date' => now()->toDateString(),
            'opened_at' => now(),
            'status' => 'OPEN',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'CLOSED',
            'closed_at' => now(),
        ]);
    }

    /** Invalid-state mode: a second OPEN fiscal_day on an already-used terminal, for constraint tests. */
    public function forTerminal(Terminal $terminal): static
    {
        return $this->state(fn () => [
            'store_id' => $terminal->store_id,
            'terminal_id' => $terminal->id,
        ]);
    }
}
