<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 8 / slice 4.3 (invariants.md BILL-008, BILL-009). Writing off an unpaid amount (bad debt) when the billing party will
// never pay it, typically after a cheque bounced. A write-off is NOT money received: it takes no cash, makes no payment and touches no
// sale, shift, cash drawer or reading. It only lowers what is still owed for one cut-off period, which stays derived:
//   owed = billed - received (allocations of cheques that are not RETURNED) - net written off (WRITE_OFF rows minus REVERSAL rows).
//
// billing_write_offs  Table kind: APPEND-ONLY. One row per write-off (kind WRITE_OFF) or per cancellation of one (kind REVERSAL). A row
//   is never updated and never deleted (database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE and DELETE); a mistaken
//   write-off is cancelled by inserting a REVERSAL row that points at it, never by editing or removing it. This is the same pattern as
//   employee_credit_entries (CHARGE / REVERSAL).
//   - amount > 0 and period_end >= period_start (CHECKs). A WRITE_OFF names one real cut-off period (the 1st or 16th: checked by the
//     service) and its amount is at most what the period owes at that moment, checked under the billing party's row lock because the
//     limit is a sum across rows no constraint can express (BILL-008).
//   - reason is required (never empty) on both kinds.
//   - A REVERSAL has reverses_write_off_id set; a WRITE_OFF does not (CHECK). reverses_write_off_id is UNIQUE, so one write-off is
//     reversed at most once. A composite foreign key (reverses_write_off_id, period_start, amount) -> (id, period_start, amount) makes a
//     reversal carry exactly the amount and period of the write-off it cancels (no partial reversals). That the target is a WRITE_OFF
//     (a reversal cannot be reversed) is checked by the service under the lock; the reversal's own unique id cannot be reversed again
//     because the service refuses a target of kind REVERSAL and the test pins it.
//   - cheque_id optionally links a WRITE_OFF to the RETURNED cheque it relates to (same store; checked by the service); never on a REVERSAL.
//   - request_id is the optional client-generated id that makes a retried POST idempotent: unique per store when present.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_write_offs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('billing_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->string('kind', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 14, 2);
            $table->string('reason', 255);
            $table->foreignUuid('cheque_id')->nullable()->constrained('billing_cheques')->restrictOnDelete();
            $table->uuid('reverses_write_off_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->foreignUuid('written_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('written_at')->useCurrent();

            $table->unique('reverses_write_off_id');
            $table->unique(['id', 'period_start', 'amount']);
            $table->index(['store_id', 'billing_party_id', 'period_start']);
            $table->index(['store_id', 'written_at']);
        });

        DB::statement('ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_period_check CHECK (period_end >= period_start)');
        DB::statement("ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_kind_check CHECK (kind IN ('WRITE_OFF','REVERSAL'))");
        DB::statement('ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_reason_check CHECK (length(btrim(reason)) > 0)');
        DB::statement("ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_reversal_check CHECK ((kind = 'REVERSAL') = (reverses_write_off_id IS NOT NULL))");
        DB::statement("ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_reversal_cheque_check CHECK (kind = 'WRITE_OFF' OR cheque_id IS NULL)");
        DB::statement('ALTER TABLE billing_write_offs ADD CONSTRAINT billing_write_offs_reverses_fk FOREIGN KEY (reverses_write_off_id, period_start, amount) REFERENCES billing_write_offs (id, period_start, amount) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX billing_write_offs_request_unique ON billing_write_offs (store_id, request_id) WHERE request_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_write_offs');
    }
};
