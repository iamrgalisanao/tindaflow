<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 8 / slice 4.2 (invariants.md BILL-002, BILL-006, BILL-007). Cheques the billing party (TSC) pays the canteen with, and the
// cut-off periods each cheque pays. A cheque is NOT a sale, a payment, a cash movement or anything to do with a shift: it only reduces what
// is still owed per cut-off period, which is derived (billed from the credit ledger, minus the allocations of RECEIVED cheques); nothing here
// stores a balance.
//
// billing_cheques  Table kind: LIFECYCLE. One row per cheque recorded. A recorded cheque is never edited and never deleted; the only change
//   it ever undergoes is RECEIVED -> RETURNED (a bounced cheque), which stamps status, returned_on, returned_at, returned_by and
//   return_reason. database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE on the table and re-grants it for exactly those five
//   columns (so the amount, number, bank, dates and party of a cheque can never be changed by the application role); DELETE is never
//   granted. RETURNED is final: a re-presented cheque is a NEW row. The unique index below refuses a second RECEIVED cheque with the same
//   bank and number, but lets a returned one be re-presented.
//
// billing_cheque_allocations  Table kind: APPEND-ONLY. The cut-off periods one cheque pays and how much of it goes to each; written in
//   the same transaction as the cheque, never updated or deleted (UPDATE and DELETE revoked). A cheque's allocations add up to its amount
//   (checked by the service under a lock on the billing party; there is no database trigger). A returned cheque keeps its allocations; the
//   cheque's RETURNED status is what stops them counting.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_cheques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('billing_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->string('cheque_number', 40);
            $table->string('bank', 80);
            $table->date('cheque_date');
            $table->decimal('amount', 14, 2);
            $table->date('received_on');
            $table->foreignUuid('received_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 10)->default('RECEIVED');
            $table->date('returned_on')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->foreignUuid('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('return_reason', 255)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['store_id', 'received_on']);
            $table->index(['store_id', 'status']);
        });

        DB::statement('ALTER TABLE billing_cheques ADD CONSTRAINT billing_cheques_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE billing_cheques ADD CONSTRAINT billing_cheques_status_check CHECK (status IN ('RECEIVED','RETURNED'))");
        DB::statement("ALTER TABLE billing_cheques ADD CONSTRAINT billing_cheques_return_check CHECK ((status = 'RETURNED') = (returned_on IS NOT NULL AND returned_at IS NOT NULL AND returned_by IS NOT NULL AND return_reason IS NOT NULL))");
        // One RECEIVED cheque per bank and number in a store (bank compared without regard to case). A returned cheque no longer counts, so it can be re-presented.
        DB::statement("CREATE UNIQUE INDEX billing_cheques_received_number_unique ON billing_cheques (store_id, lower(bank), cheque_number) WHERE status = 'RECEIVED'");

        Schema::create('billing_cheque_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cheque_id')->constrained('billing_cheques')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 14, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['cheque_id', 'period_start']);
            $table->index('period_start');
        });

        DB::statement('ALTER TABLE billing_cheque_allocations ADD CONSTRAINT billing_cheque_allocations_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE billing_cheque_allocations ADD CONSTRAINT billing_cheque_allocations_period_check CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_cheque_allocations');
        Schema::dropIfExists('billing_cheques');
    }
};
