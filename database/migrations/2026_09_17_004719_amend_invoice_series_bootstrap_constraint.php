<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 6B amendment, 2026-09-17 (hardened after owner review) --
// corrects an off-by-one the frozen corpus never explicitly settled
// either way (invariants.md INVSERIES-001, domain-model.md SS2.6
// "Counter bootstrap semantics", stage-6b-invoice-series-allocation.md
// SS8 for the full investigation).
//
// current_number continues to mean "last allocated serial" (ADR-004's
// increment-then-use mechanic, unchanged). starting_number is the
// first ISSUABLE serial. A fresh, never-used series must therefore
// bootstrap at current_number = starting_number - 1, so its first real
// allocation yields starting_number exactly -- not starting_number + 1.
//
// UPGRADE SAFETY (owner review, second hardening pass): this migration
// runs against the already-frozen Stage 5 invoice_series table, which
// may already contain rows written under the OLD bootstrap convention
// (current_number = starting_number for an unused series). Changing
// only the CHECK constraint would NOT reinterpret those existing rows
// -- an old unused series would still silently skip its
// starting_number. This migration therefore performs an explicit,
// bounded data fixup before tightening the schema:
//
//   - A series with ZERO referencing invoices has never actually
//     issued anything, regardless of whatever current_number happens
//     to hold -- it is unconditionally safe to reset it to the new
//     bootstrap baseline (starting_number - 1).
//   - A series WITH referencing invoices already had a meaningful
//     current_number under the OLD model too (current_number = last
//     allocated was never in question for a used series -- only the
//     unused-bootstrap value was ambiguous). Its current_number is
//     verified against MAX(invoice_number) among its own invoices; a
//     match means no change is needed, a mismatch means the row's
//     history is unexplained and this migration refuses to guess,
//     aborting instead (RuntimeException, caught by Laravel's
//     transactional-DDL migration wrapper -- the entire migration,
//     schema changes included, rolls back atomically).
//
// A fresh, empty database has no invoice_series rows at all, so this
// loop is a no-op in that case -- verified by
// tests/Database/InvoiceSeriesCounterUpgradeTest.php's "legacy fixture"
// tests alongside the ordinary fresh-migrate round-trip.
//
// Also adds two previously-unenforced range floors:
//   - starting_number >= 1: a series' first issuable serial is never
//     zero or negative; 0 exists only as internal pre-allocation
//     counter state for a series whose starting_number = 1.
//   - ending_number IS NULL OR ending_number >= starting_number:
//     without this, a misconfiguration such as
//     (starting_number=100, ending_number=99) would represent a
//     backwards, zero-length range that the allocator could only ever
//     observe as "already exhausted" rather than being rejected
//     outright as invalid configuration at the point it is created.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_current_number_bounds_check');

        $this->reinterpretLegacyCounters();

        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_current_number_bounds_check CHECK (current_number >= starting_number - 1 AND (ending_number IS NULL OR current_number <= ending_number))');
        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_starting_number_positive_check CHECK (starting_number >= 1)');
        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_ending_number_range_check CHECK (ending_number IS NULL OR ending_number >= starting_number)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_ending_number_range_check');
        DB::statement('ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_starting_number_positive_check');
        DB::statement('ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_current_number_bounds_check');
        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_current_number_bounds_check CHECK (current_number >= starting_number AND (ending_number IS NULL OR current_number <= ending_number))');
    }

    /**
     * @throws RuntimeException a series has invoices whose highest issued number disagrees with its own
     *                          current_number -- an unexplained inconsistency this migration will not guess through
     */
    private function reinterpretLegacyCounters(): void
    {
        $series = DB::table('invoice_series')->select(['id', 'starting_number', 'current_number'])->get();

        foreach ($series as $row) {
            $maxIssued = DB::table('invoices')
                ->where('invoice_series_id', $row->id)
                ->selectRaw('MAX(invoice_number::bigint) as max_issued')
                ->value('max_issued');

            if ($maxIssued === null) {
                // No invoice has ever been issued from this series --
                // safe to unconditionally reset to the new bootstrap
                // baseline, regardless of its prior current_number.
                DB::table('invoice_series')->where('id', $row->id)->update([
                    'current_number' => $row->starting_number - 1,
                ]);

                continue;
            }

            if ((int) $maxIssued !== (int) $row->current_number) {
                throw new RuntimeException(
                    "invoice_series {$row->id} has invoices but its current_number ({$row->current_number}) does not ".
                    "match the highest issued invoice_number ({$maxIssued}). Refusing to guess a safe migration -- ".
                    'this requires manual review before the counter-bootstrap amendment can proceed.'
                );
            }

            // Already consistent with the "last allocated" meaning
            // under both the old and new model -- no change needed.
        }
    }
};
