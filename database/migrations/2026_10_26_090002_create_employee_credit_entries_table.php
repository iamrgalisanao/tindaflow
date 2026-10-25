<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 5 / invariants.md CREDIT-003, CREDIT-004. The credit ledger: what each employee owes the store, one row per charge
// and one per reversal.
//
// employee_credit_entries  Table kind: APPEND-ONLY. A row is inserted in the transaction of the sale (CHARGE) or of the void or refund
//   (REVERSAL) and never updated or deleted; there is no running balance anywhere: the amount owed for a cut-off period is
//   SUM(CHARGE) - SUM(REVERSAL) over the rows of that `period_start`. A reversal carries the ORIGINAL charge's period, so reversing
//   after the cut-off has passed reduces what was owed for that period and frees no room in the current one. The period is the store's
//   business calendar date of the sale (Asia/Manila), 1st-15th or 16th-end of month, fixed when the CHARGE is written.
//   database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE (and DELETE) from the application role;
//   tindaflow:harden-database reads that back and AppendOnlyPrivilegesTest proves it. The employee_code / employee_name /
//   employee_type / billing_party_id columns are snapshots, so billing (slice 4.1) never reads the mutable employee row for history.
//   `override_grant_id` is NOT here: slice 3.3 adds it together with the grants table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('entry_type', 16);
            $table->decimal('amount', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignUuid('void_id')->nullable()->constrained('voids')->restrictOnDelete();
            $table->string('employee_code', 40);
            $table->string('employee_name');
            $table->string('employee_type', 16);
            $table->foreignUuid('billing_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->timestampTz('recorded_at')->useCurrent();

            $table->index(['employee_id', 'period_start']);
            $table->index('sale_id');
        });

        DB::statement("ALTER TABLE employee_credit_entries ADD CONSTRAINT employee_credit_entries_type_check CHECK (entry_type IN ('CHARGE', 'REVERSAL'))");
        DB::statement('ALTER TABLE employee_credit_entries ADD CONSTRAINT employee_credit_entries_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE employee_credit_entries ADD CONSTRAINT employee_credit_entries_period_check CHECK (period_end >= period_start)');
        // A charge belongs to a sale alone; a reversal names exactly one of the refund or the void that caused it.
        DB::statement("ALTER TABLE employee_credit_entries ADD CONSTRAINT employee_credit_entries_reference_check CHECK (
            (entry_type = 'CHARGE' AND refund_id IS NULL AND void_id IS NULL)
            OR (entry_type = 'REVERSAL' AND ((refund_id IS NOT NULL AND void_id IS NULL) OR (refund_id IS NULL AND void_id IS NOT NULL)))
        )");
        // A sale is charged once; a void or a refund reverses once. These make a double charge or a double reversal impossible.
        DB::statement("CREATE UNIQUE INDEX employee_credit_entries_one_charge_per_sale ON employee_credit_entries (sale_id) WHERE entry_type = 'CHARGE'");
        DB::statement('CREATE UNIQUE INDEX employee_credit_entries_one_reversal_per_void ON employee_credit_entries (void_id) WHERE void_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX employee_credit_entries_one_reversal_per_refund ON employee_credit_entries (refund_id) WHERE refund_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_credit_entries');
    }
};
