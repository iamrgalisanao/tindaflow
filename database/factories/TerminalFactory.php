<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    protected $model = Terminal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'terminal_code' => 'T-'.fake()->unique()->numerify('##'),
            'status' => 'ACTIVE',
            'activated_at' => now(),
        ];
    }

    /** Invalid-state mode for constraint tests: a terminal not yet enrolled. */
    public function unenrolled(): static
    {
        return $this->state(fn () => [
            'credential_hash' => null,
            'credential_issued_at' => null,
            'activated_at' => null,
        ]);
    }

    public function decommissioned(): static
    {
        return $this->state(fn () => ['status' => 'DECOMMISSIONED']);
    }
}
