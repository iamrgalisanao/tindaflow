<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\TaxRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRegistration>
 */
class TaxRegistrationFactory extends Factory
{
    protected $model = TaxRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'registration_type' => 'VAT',
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['effective_to' => now()->toDateString()]);
    }
}
