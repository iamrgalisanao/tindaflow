<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 8 / slice 4.1 (invariants.md BILL-001..BILL-005). Saved billing runs: a frozen copy of one billing statement, so a bill
// that was printed can be reproduced exactly later even though the live statement is recomputed from the ledger every time.
//
// billing_runs  Table kind: APPEND-ONLY. One row per explicit "Save as billing run"; never updated or deleted. `statement` is the whole
//   statement as generated (accounts, meal types, items, adjustments, totals, and the comparison with the previous run for the same
//   range) and `statement_sha256` is the hash of that JSON, so any later tampering is detectable. A later re-run for the same range is a NEW row
//   (never an edit), which is how a refund recorded after an earlier run shows up as a difference. Everything a run says about an employee
//   comes from the ledger and sale snapshots (employee code / name / type, meal and dish names), never from the mutable employee or
//   menu rows. `run_number` (BR-000001) is allocated from billing_run_counters inside the same transaction as the insert, so a run that
//   rolls back leaves no gap. database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE (and DELETE) from the application role;
//   tindaflow:harden-database reads that back and AppendOnlyPrivilegesTest proves it.
//
// billing_run_counters  Table kind: LIFECYCLE. One row per store holding the last number issued; the creating transaction locks it
//   FOR UPDATE and bumps `last_number` (column-level UPDATE grant: last_number, updated_at); the row is never deleted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_run_counters', function (Blueprint $table) {
            $table->foreignUuid('store_id')->primary()->constrained('stores')->restrictOnDelete();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampsTz();
        });

        Schema::create('billing_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('billing_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('run_number', 20);
            $table->date('from_date');
            $table->date('to_date');
            $table->string('period_label', 80)->nullable();
            $table->string('employee_type', 16)->nullable();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('employee_code', 40)->nullable();
            $table->timestampTz('as_of');
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('generated_at');
            $table->decimal('charges_total', 14, 2);
            $table->decimal('reversals_total', 14, 2);
            $table->decimal('net_total', 14, 2);
            $table->unsignedInteger('account_count');
            $table->unsignedInteger('sale_count');
            $table->jsonb('statement');
            $table->char('statement_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['store_id', 'run_number']);
            $table->unique(['store_id', 'sequence']);
            $table->index(['store_id', 'from_date', 'to_date']);
        });

        DB::statement('ALTER TABLE billing_runs ADD CONSTRAINT billing_runs_range_check CHECK (to_date >= from_date)');
        DB::statement("ALTER TABLE billing_runs ADD CONSTRAINT billing_runs_employee_type_check CHECK (employee_type IS NULL OR employee_type IN ('DIRECT','SUBCONTRACTOR'))");
        DB::statement('ALTER TABLE billing_runs ADD CONSTRAINT billing_runs_net_check CHECK (net_total = charges_total - reversals_total)');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_runs');
        Schema::dropIfExists('billing_run_counters');
    }
};
