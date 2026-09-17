<?php

namespace Tests\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stage 6B, owner hardening pass -- exercises the REAL
 * fiscal_installation_id migration's up()/down() methods directly
 * against a reconstructed pre-amendment table shape (no
 * fiscal_installation_id column, no partial unique index), not a
 * reimplementation of its backfill/validation logic. Mirrors
 * InvoiceSeriesCounterUpgradeTest's technique.
 */
class InvoiceSeriesFiscalInstallationUpgradeTest extends PostgresSchemaTestCase
{
    private string $storeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Upgrade Test Store', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fiscalInstallationMigration(): object
    {
        return require base_path('database/migrations/2026_09_17_004738_add_fiscal_installation_to_invoice_series_table.php');
    }

    /** Reverts to the exact pre-amendment shape: no fiscal_installation_id column, no partial unique index. */
    private function revertToPreAmendmentSchema(): void
    {
        $this->fiscalInstallationMigration()->down();
    }

    private function insertFiscalInstallation(string $storeId): string
    {
        $id = (string) Str::uuid();

        DB::table('fiscal_installations')->insert([
            'id' => $id, 'store_id' => $storeId, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertLegacySeriesWithoutInstallation(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('invoice_series')->insert(array_merge([
            'id' => $id,
            'store_id' => $this->storeId,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            'current_number' => 0,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * Scenario A (owner instruction §5): Store with exactly one
     * fiscal_installation and an existing invoice_series -- safe,
     * deterministic backfill.
     */
    public function test_single_installation_backfills_deterministically(): void
    {
        $this->revertToPreAmendmentSchema();

        $installationId = $this->insertFiscalInstallation($this->storeId);
        $seriesId = $this->insertLegacySeriesWithoutInstallation();

        $this->fiscalInstallationMigration()->up();

        $row = DB::table('invoice_series')->where('id', $seriesId)->first();
        $this->assertSame($installationId, $row->fiscal_installation_id, 'the single existing fiscal_installation must be backfilled deterministically');
    }

    /**
     * Scenario B (owner instruction §5): Store with TWO
     * fiscal_installations and an existing invoice_series -- the
     * migration must not guess, must abort clearly and transactionally.
     */
    public function test_ambiguous_multiple_installations_aborts_the_migration(): void
    {
        $this->revertToPreAmendmentSchema();

        $this->insertFiscalInstallation($this->storeId);
        $this->insertFiscalInstallation($this->storeId);
        $this->insertLegacySeriesWithoutInstallation();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot determine which installation/');

        $this->fiscalInstallationMigration()->up();
    }

    public function test_zero_installations_for_a_store_with_an_existing_series_aborts_the_migration(): void
    {
        $this->revertToPreAmendmentSchema();

        // No fiscal_installations at all for this store, yet an
        // invoice_series already exists -- equally unresolvable.
        $this->insertLegacySeriesWithoutInstallation();

        $this->expectException(RuntimeException::class);

        $this->fiscalInstallationMigration()->up();
    }

    /**
     * Owner instruction §6: two legacy ACTIVE series under the same
     * (single) fiscal_installation -- the new partial unique index
     * cannot be created honestly without resolving this; the migration
     * must fail clearly rather than auto-selecting a winner.
     */
    public function test_multiple_active_legacy_series_for_the_same_installation_aborts_the_migration(): void
    {
        $this->revertToPreAmendmentSchema();

        $this->insertFiscalInstallation($this->storeId);
        $this->insertLegacySeriesWithoutInstallation(['series_code' => 'S1', 'status' => 'ACTIVE']);
        $this->insertLegacySeriesWithoutInstallation(['series_code' => 'S2', 'status' => 'ACTIVE']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/simultaneously ACTIVE/');

        $this->fiscalInstallationMigration()->up();
    }

    public function test_one_active_one_closed_legacy_series_for_the_same_installation_backfills_successfully(): void
    {
        $this->revertToPreAmendmentSchema();

        $installationId = $this->insertFiscalInstallation($this->storeId);
        $activeId = $this->insertLegacySeriesWithoutInstallation(['series_code' => 'S1', 'status' => 'ACTIVE']);
        $closedId = $this->insertLegacySeriesWithoutInstallation(['series_code' => 'S2', 'status' => 'CLOSED']);

        $this->fiscalInstallationMigration()->up();

        $this->assertSame($installationId, DB::table('invoice_series')->where('id', $activeId)->value('fiscal_installation_id'));
        $this->assertSame($installationId, DB::table('invoice_series')->where('id', $closedId)->value('fiscal_installation_id'));
    }

    public function test_migration_is_a_no_op_when_no_invoice_series_rows_exist(): void
    {
        $this->revertToPreAmendmentSchema();

        $this->fiscalInstallationMigration()->up();

        $this->assertSame(0, DB::table('invoice_series')->count());
    }
}
