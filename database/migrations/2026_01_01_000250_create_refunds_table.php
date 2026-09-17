<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.9 REFUND. Same processing-context-at-execution-only
// discipline as `voids` (invariant #67-#69), enforced by the same shape
// of CHECK constraint. Unlike Void, Refund has NO eligibility condition
// tying it to the original sale's own fiscal_day (state-machines.md SS3)
// -- that asymmetry lives in Stage 6 application logic, not here; this
// schema is symmetric with `voids` on purpose, since the database-level
// shape of "processing context populated only at completion" is
// identical for both.
//
// requested_at/resolved_at were added to the API contract in Stage 4
// pass 4 (RefundSummary) to mirror VoidSummary -- persisted here from
// the start so Stage 6 never needs a later migration to add them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->string('status')->default('REQUESTED'); // REQUESTED|APPROVED|REJECTED|COMPLETED
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->decimal('refund_total', 12, 2)->default(0);
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_day_id')->nullable()->constrained('fiscal_days')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('REQUESTED','APPROVED','REJECTED','COMPLETED'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_nonempty_check CHECK (btrim(reason) <> '')");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_total_nonneg_check CHECK (refund_total >= 0)');
        // Invariant #67 -- processing context populated at execution only.
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_processing_context_check CHECK (
            (status = 'COMPLETED' AND terminal_id IS NOT NULL AND fiscal_day_id IS NOT NULL AND shift_id IS NOT NULL AND refunded_at IS NOT NULL)
            OR
            (status <> 'COMPLETED' AND terminal_id IS NULL AND fiscal_day_id IS NULL AND shift_id IS NULL AND refunded_at IS NULL)
        )");

        // Owner hardening pass, 2026-09-16, closing DB-INV-063: same
        // construction as voids' equivalent -- see that migration's
        // comment for why nullable columns and MATCH SIMPLE composite
        // FKs compose cleanly with the processing-context CHECK above.
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_terminal_shift_fk FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts (terminal_id, id)');
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_terminal_fiscal_day_fk FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
