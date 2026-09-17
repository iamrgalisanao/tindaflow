<?php

namespace Tests\Database;

use App\Models\ElectronicJournalEntry;
use App\Models\FiscalInstallation;
use App\Models\InvoiceSeries;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Store;
use Illuminate\Support\Str;

// Stage 6C integration corrections to pre-existing model metadata,
// discovered while consuming the frozen Stage 5/6A schema (not a redesign
// of anything Stage 5/6A decided -- see docs/06-backend/stage-6c-sale-finalization.md
// SS5 gap 4). Factory::create() bypasses $fillable via forceFill(), so
// these two defects were invisible to every existing factory-based test;
// these tests deliberately go through Model::create() (real mass
// assignment) to prove the fix, not around it.
class ModelMassAssignmentRegressionTest extends PostgresSchemaTestCase
{
    public function test_sale_mass_assignment_preserves_non_vat_sales(): void
    {
        $shift = Shift::factory()->create();

        $sale = Sale::create([
            'store_id' => $shift->fiscalDay->store_id,
            'terminal_id' => $shift->terminal_id,
            'fiscal_day_id' => $shift->fiscal_day_id,
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'transaction_number' => 'TXN-MASSASSIGN-001',
            'sold_at' => now(),
            'subtotal' => '250.00',
            'order_level_discount_amount' => '0.00',
            'discount_total' => '0.00',
            'taxable_sales' => '0.00',
            'vat_exempt_sales' => '0.00',
            'zero_rated_sales' => '0.00',
            'vat_amount' => '0.00',
            'non_vat_sales' => '250.00',
            'grand_total' => '250.00',
            'status' => 'COMPLETED',
        ]);

        $this->assertSame('250.00', $sale->fresh()->non_vat_sales);
    }

    public function test_invoice_series_mass_assignment_preserves_fiscal_installation_id(): void
    {
        $fiscalInstallation = FiscalInstallation::factory()->create();

        $series = InvoiceSeries::create([
            'store_id' => $fiscalInstallation->store_id,
            'fiscal_installation_id' => $fiscalInstallation->id,
            'series_code' => 'MASSASSIGN',
            'prefix' => 'INV',
            'current_number' => 0,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
        ]);

        $this->assertSame($fiscalInstallation->id, $series->fresh()->fiscal_installation_id);
    }

    public function test_electronic_journal_entry_generates_a_postgresql_uuid_accepted_id(): void
    {
        $store = Store::factory()->create();

        $entry = ElectronicJournalEntry::create([
            'store_id' => $store->id,
            'event_type' => 'INVOICE',
            'source_type' => 'invoice',
            'source_id' => (string) Str::uuid(),
            'payload_json' => ['schema_version' => 1],
        ]);

        $this->assertTrue(Str::isUuid($entry->id), "generated id \"{$entry->id}\" is not a valid UUID");

        // Round-trips through the real `uuid` column -- this is the
        // assertion that would have failed outright (SQLSTATE 22P02)
        // before the HasUuids correction.
        $persisted = ElectronicJournalEntry::findOrFail($entry->id);
        $this->assertSame($entry->id, $persisted->id);
    }
}
