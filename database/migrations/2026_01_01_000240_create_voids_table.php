<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.8 VOID -- one row per void ATTEMPT. Processing
// context (terminal_id/fiscal_day_id/shift_id) is populated only at
// execution (APPROVED->VOIDED), never at request (invariant #67) --
// nullable columns, enforced NULL/NOT-NULL-together by the CHECK
// constraint below, which is a direct database-level implementation of
// invariant #67 rather than mere prose. Invariant #21: at most one
// successful void per sale -- the partial unique index below.
//
// state-machines.md SS2 (Stage 2 amendment pass 4, commit 6da07bd): a
// failed execution-time recheck leaves the void REQUESTED, it is NEVER
// automatically moved to REJECTED -- this schema does not (and must not)
// encode that business rule itself; it only ensures the four status
// values are valid and that processing-context nullability tracks
// status correctly. The failure-does-not-mutate-status guarantee is a
// TRANSACTIONAL invariant (Stage 6: a failed approve attempt's UPDATE
// simply never runs, inside a transaction that rolls back) -- see
// constraint-register.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->string('status')->default('REQUESTED'); // REQUESTED|APPROVED|REJECTED|VOIDED
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_day_id')->nullable()->constrained('fiscal_days')->restrictOnDelete();
            $table->foreignUuid('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE voids ADD CONSTRAINT voids_status_check CHECK (status IN ('REQUESTED','APPROVED','REJECTED','VOIDED'))");
        DB::statement("ALTER TABLE voids ADD CONSTRAINT voids_reason_nonempty_check CHECK (btrim(reason) <> '')");
        // Invariant #67 -- processing context populated at execution only.
        DB::statement("ALTER TABLE voids ADD CONSTRAINT voids_processing_context_check CHECK (
            (status = 'VOIDED' AND terminal_id IS NOT NULL AND fiscal_day_id IS NOT NULL AND shift_id IS NOT NULL)
            OR
            (status <> 'VOIDED' AND terminal_id IS NULL AND fiscal_day_id IS NULL AND shift_id IS NULL)
        )");
        // Invariant #21 -- at most one successful void per sale.
        DB::statement("CREATE UNIQUE INDEX voids_one_voided_per_sale ON voids (sale_id) WHERE status = 'VOIDED'");

        // Owner hardening pass, 2026-09-16, closing DB-INV-063: proves
        // the processing terminal/shift/fiscal_day are mutually
        // coherent (all three belong to the same terminal) once
        // populated. Postgres's default MATCH SIMPLE FK semantics skip
        // the check entirely when either column of the pair is NULL, so
        // this coexists cleanly with voids_processing_context_check's
        // "all three NULL until VOIDED" rule above -- there is nothing
        // to validate before execution, and everything to validate once
        // execution context exists.
        DB::statement('ALTER TABLE voids ADD CONSTRAINT voids_terminal_shift_fk FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts (terminal_id, id)');
        DB::statement('ALTER TABLE voids ADD CONSTRAINT voids_terminal_fiscal_day_fk FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('voids');
    }
};
