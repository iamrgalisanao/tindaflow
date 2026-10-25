<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// ADR-016 section 5 / invariants.md EMP-001. The people who may be charged on credit, the numbering of their codes, the party that
// is billed for them and the credit limit. No sale, ledger or credit tender exists yet (slices 3.2 and 3.3).
//
// employees  Table kind: LIFECYCLE (never deleted: an employee is deactivated, because the ledger of slice 3.2 refers to the row and
//   the code must never be reused). The application role gets a COLUMN-LEVEL UPDATE that leaves out `employee_code`, `employee_type`,
//   `store_id` and `created_by`, so the code (which is also the QR value) cannot be changed even by a bug or a direct query as that
//   role. UNIQUE (store_id, employee_code). Names are stored as first / middle / last; the till and the slip use the joined name.
//
// employee_code_sequences  Table kind: LIFECYCLE (one counter row per store and employee type). `last_number` is incremented under a
//   row lock (SELECT ... FOR UPDATE) in the same transaction that inserts the employee, so a rolled-back creation rolls its number back:
//   committed codes have no gap and are never reused. Column-level UPDATE (last_number, updated_at) only.
//
// billing_parties  Table kind: LIFECYCLE (configuration). Client decision 2026-10-25: subcontractor (SUB) employees are billed to TSC
//   too, so each store has ONE party, named TSC. The table stays so a later second party needs no schema change. Created for every
//   existing store here and lazily for a new store (BillingPartyResolver). UPDATE allowed (rename, deactivate); never deleted.
//
// credit_policies  Table kind: LIFECYCLE with a column-level UPDATE (limit_per_cutoff, updated_by, updated_at). One row per store and
//   employee type holds the CURRENT limit per cut-off (TSC 1,500.00, SUB 1,000.00 by default). It is not append-only history: the
//   limit is only ever compared with the present (the credit check at checkout, slice 3.2), never used to recompute a past balance,
//   and every change is recorded with the old and the new value in the CREDIT_LIMIT_CHANGED audit event (which is append-only), as is
//   the limit in force at each over-limit override. Rows are created for every existing store here and lazily for a new store.
return new class extends Migration
{
    private const DEFAULT_LIMITS = ['DIRECT' => '1500.00', 'SUBCONTRACTOR' => '1000.00'];

    public function up(): void
    {
        Schema::create('billing_parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE billing_parties ADD CONSTRAINT billing_parties_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX billing_parties_store_name_unique ON billing_parties (store_id, lower(name))');

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('employee_type', 16);
            $table->string('employee_code', 40);
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->foreignUuid('billing_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['store_id', 'employee_code']);
            $table->index(['store_id', 'active', 'last_name', 'first_name']);
        });
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_type_check CHECK (employee_type IN ('DIRECT', 'SUBCONTRACTOR'))");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_names_not_blank_check CHECK (btrim(first_name) <> '' AND btrim(last_name) <> '')");
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_code_format_check CHECK (employee_code ~ '^(TSC|SUB)[0-9]{5,}$')");

        Schema::create('employee_code_sequences', function (Blueprint $table) {
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('employee_type', 16);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampsTz();

            $table->primary(['store_id', 'employee_type']);
        });
        DB::statement("ALTER TABLE employee_code_sequences ADD CONSTRAINT employee_code_sequences_type_check CHECK (employee_type IN ('DIRECT', 'SUBCONTRACTOR'))");

        Schema::create('credit_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('employee_type', 16);
            $table->decimal('limit_per_cutoff', 12, 2);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['store_id', 'employee_type']);
        });
        DB::statement("ALTER TABLE credit_policies ADD CONSTRAINT credit_policies_type_check CHECK (employee_type IN ('DIRECT', 'SUBCONTRACTOR'))");
        DB::statement('ALTER TABLE credit_policies ADD CONSTRAINT credit_policies_limit_check CHECK (limit_per_cutoff >= 0)');

        // Every store that exists now gets its billing party and its two limits; a store created later gets them on first use.
        $now = now();
        foreach (DB::table('stores')->pluck('id') as $storeId) {
            DB::table('billing_parties')->insert(['id' => (string) Str::uuid(), 'store_id' => $storeId, 'name' => 'TSC', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach (self::DEFAULT_LIMITS as $type => $limit) {
                DB::table('credit_policies')->insert(['id' => (string) Str::uuid(), 'store_id' => $storeId, 'employee_type' => $type, 'limit_per_cutoff' => $limit, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_policies');
        Schema::dropIfExists('employee_code_sequences');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('billing_parties');
    }
};
