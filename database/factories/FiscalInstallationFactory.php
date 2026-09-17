<?php

namespace Database\Factories;

use App\Models\FiscalInstallation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalInstallation>
 */
class FiscalInstallationFactory extends Factory
{
    protected $model = FiscalInstallation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'deployment_model' => 'STANDALONE',
            'machine_serial_number' => null,
            'software_version' => '1.0.0',
            'installed_at' => now(),
            'superseded_at' => null,
        ];
    }
}
