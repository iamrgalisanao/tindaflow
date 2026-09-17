<?php

namespace Tests\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage 6B, owner hardening pass ("prove the forward migration is safe
 * against the already-frozen Stage 5 schema containing existing
 * rows") -- exercises the REAL migration's up()/down() methods
 * directly against a reconstructed pre-amendment table shape, not a
 * reimplementation of its logic. A fresh-database migrate:fresh round
 * trip alone (proven elsewhere) is not sufficient evidence for an
 * upgrade scenario.
 *
 * Technique: PostgresSchemaTestCase already migrates the full amended
 * schema once per class. Each test here calls the amendment migration's
 * own down() to reconstruct the exact pre-amendment CHECK constraint,
 * inserts LEGACY-shaped fixture rows under that old constraint, then
 * calls the SAME migration's up() again to exercise the real upgrade
 * path. Both calls run inside this test's own transaction (rolled back
 * in tearDown), so nothing leaks into other tests.
 */
class InvoiceSeriesCounterUpgradeTest extends PostgresSchemaTestCase
{
    private string $storeId;

    private string $fiscalInstallationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Upgrade Test Store', 'created_at' => now(), 'updated_at' => now()]);

        $this->fiscalInstallationId = (string) Str::uuid();
        DB::table('fiscal_installations')->insert([
            'id' => $this->fiscalInstallationId, 'store_id' => $this->storeId, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function counterMigration(): object
    {
        return require base_path('database/migrations/2026_09_17_004719_amend_invoice_series_bootstrap_constraint.php');
    }

    /** Reverts to the exact pre-amendment CHECK (current_number >= starting_number), so a legacy row can be inserted under the OLD rule. */
    private function revertToPreAmendmentSchema(): void
    {
        $this->counterMigration()->down();
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertLegacySeries(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('invoice_series')->insert(array_merge([
            'id' => $id,
            'store_id' => $this->storeId,
            'fiscal_installation_id' => $this->fiscalInstallationId,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            'current_number' => 1,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_unused_legacy_series_with_starting_number_one_is_reinterpreted_to_bootstrap_zero(): void
    {
        $this->revertToPreAmendmentSchema();

        // The exact pre-amendment "unused" fixture shape: current_number
        // was written equal to starting_number, under the OLD (now
        // corrected) convention.
        $seriesId = $this->insertLegacySeries(['starting_number' => 1, 'current_number' => 1]);

        $this->counterMigration()->up();

        $row = DB::table('invoice_series')->where('id', $seriesId)->first();
        $this->assertSame(1, (int) $row->starting_number);
        $this->assertSame(0, (int) $row->current_number, 'an unused legacy series must be reinterpreted to the new bootstrap baseline (starting_number - 1)');
    }

    public function test_unused_legacy_series_with_non_one_starting_number_is_reinterpreted_correctly(): void
    {
        $this->revertToPreAmendmentSchema();

        $seriesId = $this->insertLegacySeries(['starting_number' => 100, 'current_number' => 100]);

        $this->counterMigration()->up();

        $row = DB::table('invoice_series')->where('id', $seriesId)->first();
        $this->assertSame(99, (int) $row->current_number, 'an unused legacy series with starting_number=100 must migrate to current_number=99');
    }

    public function test_used_legacy_series_consistent_with_its_invoices_is_left_unchanged(): void
    {
        $this->revertToPreAmendmentSchema();

        // Under the OLD model, current_number already meant "last
        // allocated" for a series that had actually issued invoices --
        // this was never the ambiguous case. A series whose
        // current_number already matches its highest issued invoice
        // number needs no correction.
        $seriesId = $this->insertLegacySeries(['starting_number' => 1, 'current_number' => 3]);
        $this->insertMinimalInvoiceForSeries($seriesId, '000003');

        $this->counterMigration()->up();

        $row = DB::table('invoice_series')->where('id', $seriesId)->first();
        $this->assertSame(3, (int) $row->current_number, 'a used series already consistent with its own invoice history must not be touched');
    }

    public function test_used_legacy_series_inconsistent_with_its_invoices_aborts_the_migration(): void
    {
        $this->revertToPreAmendmentSchema();

        // current_number (5) disagrees with the highest actually-issued
        // invoice_number (3) -- an unexplained inconsistency the
        // migration must not guess through.
        $seriesId = $this->insertLegacySeries(['starting_number' => 1, 'current_number' => 5]);
        $this->insertMinimalInvoiceForSeries($seriesId, '000003');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not.*match/');

        $this->counterMigration()->up();
    }

    public function test_migration_is_a_no_op_when_no_invoice_series_rows_exist(): void
    {
        $this->revertToPreAmendmentSchema();

        // No legacy rows inserted at all -- must not error.
        $this->counterMigration()->up();

        $this->assertSame(0, DB::table('invoice_series')->count());
    }

    private function insertMinimalInvoiceForSeries(string $seriesId, string $invoiceNumber): void
    {
        $terminalId = (string) Str::uuid();
        $cashierId = (string) Str::uuid();
        $fiscalDayId = (string) Str::uuid();
        $shiftId = (string) Str::uuid();
        $saleId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $cashierId, 'store_id' => $this->storeId, 'name' => 'Cashier',
            'email' => Str::uuid().'@test.local', 'password_hash' => 'x', 'role' => 'CASHIER',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('terminals')->insert([
            'id' => $terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'T-'.Str::random(6),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fiscal_days')->insert([
            'id' => $fiscalDayId, 'store_id' => $this->storeId, 'terminal_id' => $terminalId,
            'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shifts')->insert([
            'id' => $shiftId, 'terminal_id' => $terminalId, 'fiscal_day_id' => $fiscalDayId,
            'cashier_id' => $cashierId, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales')->insert([
            'id' => $saleId, 'store_id' => $this->storeId, 'terminal_id' => $terminalId,
            'fiscal_day_id' => $fiscalDayId, 'shift_id' => $shiftId, 'cashier_id' => $cashierId,
            'transaction_number' => 'TXN-'.Str::random(6), 'sold_at' => now(), 'subtotal' => 100, 'grand_total' => 100,
            'taxable_sales' => 89.29, 'vat_amount' => 10.71, 'status' => 'COMPLETED', 'created_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'sale_id' => $saleId,
            'invoice_series_id' => $seriesId, 'invoice_number' => $invoiceNumber, 'issued_at' => now(),
            'terminal_id' => $terminalId, 'seller_registered_name_snapshot' => 'Test Store',
            'tax_registration_type_snapshot' => 'VAT', 'terminal_code_snapshot' => 'T-01',
            'invoice_snapshot_json' => '{}', 'created_at' => now(),
        ]);
    }
}
