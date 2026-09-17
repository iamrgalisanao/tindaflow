<?php

namespace Database\Factories;

use App\Models\FiscalInstallation;
use App\Models\InvoiceSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSeries>
 */
class InvoiceSeriesFactory extends Factory
{
    protected $model = InvoiceSeries::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // fiscal_installation_id and store_id must reference the SAME
        // store (invoice_series_store_installation_fk), so the
        // installation is created eagerly here rather than via two
        // independent Model::factory() refs, which would each create
        // their own unrelated Store.
        $fiscalInstallation = FiscalInstallation::factory()->create();

        return [
            'store_id' => $fiscalInstallation->store_id,
            'fiscal_installation_id' => $fiscalInstallation->id,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            // Stage 6B amendment (invariants.md INVSERIES-001): a fresh
            // series bootstraps at starting_number - 1 so its first
            // real allocation yields starting_number exactly.
            'current_number' => 0,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'CLOSED']);
    }
}
