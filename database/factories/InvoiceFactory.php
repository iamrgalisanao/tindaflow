<?php

namespace Database\Factories;

use App\Models\FiscalInstallation;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sale = Sale::factory()->create();
        // fiscal_installation_id and store_id must reference the same
        // store (invoice_series_store_installation_fk), so the
        // installation is created explicitly for $sale->store_id rather
        // than relying on InvoiceSeriesFactory's own default (which
        // creates an unrelated store).
        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $sale->store_id]);
        $series = InvoiceSeries::factory()->create([
            'store_id' => $sale->store_id,
            'fiscal_installation_id' => $fiscalInstallation->id,
        ]);

        return [
            'store_id' => $sale->store_id,
            'sale_id' => $sale->id,
            'invoice_series_id' => $series->id,
            'invoice_number' => fake()->unique()->numerify('######'),
            'issued_at' => now(),
            'terminal_id' => $sale->terminal_id,
            'seller_registered_name_snapshot' => 'Test Store',
            'tax_registration_type_snapshot' => 'VAT',
            'terminal_code_snapshot' => $sale->terminal->terminal_code,
            'invoice_snapshot_json' => [],
        ];
    }

    /** Invalid-state mode for constraint tests: duplicate serial within the same series. */
    public function withSerial(InvoiceSeries $series, string $invoiceNumber): static
    {
        return $this->state(fn () => [
            'invoice_series_id' => $series->id,
            'invoice_number' => $invoiceNumber,
        ]);
    }
}
