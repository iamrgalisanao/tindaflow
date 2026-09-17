<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 6B amendment, 2026-09-17 (hardened after owner review) --
// closes the InvoiceSeries resolution gap discovered while implementing
// InvoiceSeriesAllocator: DB-INV-018 already established that a store
// may legitimately have more than one invoice_series row, but no frozen
// document assigned a field the allocator could use to choose between
// them (invariants.md INVSERIES-002/003, domain-model.md SS2.6
// "Series-to-installation binding").
//
// invoice_series now belongs to exactly one fiscal_installation, never
// null -- mirroring the same identity chain already used everywhere
// else a terminal's fiscal context matters
// (terminal_fiscal_installation's effective-dated mapping,
// invoices.fiscal_installation_id). store_id is RETAINED alongside it
// (not removed) so store-level coherence stays enforceable via a
// composite FK, matching the same disclosed-denormalization pattern
// already used for terminal_fiscal_installations/invoices -- see
// context-integrity-matrix.md.
//
// UPGRADE SAFETY (owner review, second hardening pass): this migration
// runs against the already-frozen Stage 5 invoice_series table, which
// may already contain rows with no way to infer which
// fiscal_installation each belongs to. A fresh, empty database has no
// invoice_series rows, so the backfill/validation steps below are
// no-ops in that case -- verified alongside the ordinary fresh-migrate
// round-trip and by
// tests/Database/InvoiceSeriesFiscalInstallationUpgradeTest.php's
// legacy-fixture tests. For any store that already has invoice_series
// rows at migration time:
//
//   - Exactly one fiscal_installation for that store: safe,
//     deterministic backfill -- every one of that store's
//     invoice_series rows is assigned that single installation.
//   - Zero, or more than one, fiscal_installation for that store: the
//     migration cannot infer which installation each series belongs to
//     without guessing, so it aborts with a clear, store-identifying
//     RuntimeException (caught by Laravel's transactional-DDL
//     migration wrapper -- nothing partially applies) rather than
//     picking one arbitrarily.
//
// Separately, after backfill, this migration verifies no
// fiscal_installation would end up with more than one simultaneously
// ACTIVE invoice_series (a legitimate possibility under the OLD,
// store-only-scoped model this amendment replaces) BEFORE attempting
// to create invoice_series_one_active_per_installation -- a clear,
// series-identifying RuntimeException is raised instead of either
// letting the CREATE UNIQUE INDEX statement fail with a raw constraint
// error, or silently closing one of the conflicting series to make the
// index creation succeed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_series', function (Blueprint $table) {
            $table->foreignUuid('fiscal_installation_id')->nullable()->after('store_id')->constrained('fiscal_installations')->restrictOnDelete();
        });

        $this->backfillFiscalInstallations();
        $this->assertNoMultipleActivePerInstallation();

        Schema::table('invoice_series', function (Blueprint $table) {
            $table->uuid('fiscal_installation_id')->nullable(false)->change();
        });

        // Store-level structural coherence (DATABASE-enforced, same
        // pattern as tfi_store_installation_fk): proves an
        // invoice_series' fiscal_installation genuinely belongs to the
        // same store the series itself declares.
        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_store_installation_fk FOREIGN KEY (store_id, fiscal_installation_id) REFERENCES fiscal_installations (store_id, id)');

        // Invariant INVSERIES-003: at most one ACTIVE invoice_series
        // per fiscal_installation -- the same "current/active
        // singleton, history preserved" pattern already applied to
        // shifts/fiscal_days/terminal_fiscal_installations. A store may
        // still have multiple invoice_series rows (DB-INV-018), through
        // multiple or historical fiscal_installations, but never more
        // than one simultaneously ACTIVE row per fiscal_installation.
        DB::statement("CREATE UNIQUE INDEX invoice_series_one_active_per_installation ON invoice_series (fiscal_installation_id) WHERE status = 'ACTIVE'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoice_series_one_active_per_installation');
        DB::statement('ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_store_installation_fk');

        Schema::table('invoice_series', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fiscal_installation_id');
        });
    }

    /** @throws RuntimeException a store has zero, or more than one, fiscal_installation -- cannot infer the mapping */
    private function backfillFiscalInstallations(): void
    {
        $storeIds = DB::table('invoice_series')->distinct()->pluck('store_id');

        foreach ($storeIds as $storeId) {
            $installations = DB::table('fiscal_installations')->where('store_id', $storeId)->pluck('id');

            if ($installations->count() !== 1) {
                throw new RuntimeException(
                    "Store {$storeId} has {$installations->count()} fiscal_installations but already has existing ".
                    'invoice_series rows -- cannot determine which installation each series belongs to without '.
                    'guessing. This requires an explicit mapping (e.g. a manual data migration or admin tool) '.
                    'before the fiscal_installation_id amendment can proceed for this store.'
                );
            }

            DB::table('invoice_series')->where('store_id', $storeId)->update([
                'fiscal_installation_id' => $installations->first(),
            ]);
        }
    }

    /** @throws RuntimeException a fiscal_installation would end up with more than one simultaneously ACTIVE series */
    private function assertNoMultipleActivePerInstallation(): void
    {
        $conflicts = DB::table('invoice_series')
            ->select('fiscal_installation_id')
            ->where('status', 'ACTIVE')
            ->groupBy('fiscal_installation_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('fiscal_installation_id');

        if ($conflicts->isNotEmpty()) {
            $list = $conflicts->implode(', ');

            throw new RuntimeException(
                "fiscal_installation(s) [{$list}] would have more than one simultaneously ACTIVE invoice_series ".
                'after backfill. invoice_series_one_active_per_installation cannot be created honestly until this '.
                'is resolved by an explicit administrative decision (which series stays ACTIVE, which becomes '.
                'CLOSED) -- this migration will not choose one arbitrarily.'
            );
        }
    }
};
