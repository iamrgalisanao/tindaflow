<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.5 FISCAL_DAY -- a terminal's operating business day /
// Z-Reading boundary. Invariant #32: at most one OPEN fiscal_day per
// terminal -- enforced by the partial unique index below (also
// architecture.md SS4's database-authority table, row 3). business_date
// is a label only, never a query filter for fiscal attribution
// (invariant #9) -- no uniqueness is placed on business_date itself,
// deliberately, since a store operating past midnight can have exactly
// one fiscal_day spanning two calendar dates.
//
// Composite constraints (owner hardening pass, 2026-09-16, closing
// DB-INV-063): UNIQUE(terminal_id, id) lets downstream tables (shifts,
// sales, z_readings, void/refund processing context) composite-FK
// against (terminal_id, fiscal_day_id) and have PostgreSQL prove the
// referenced fiscal_day really belongs to that terminal, not just that
// the row exists. The composite FK to terminals(store_id, id) below
// closes the same gap one level up: proves this fiscal_day's own
// store_id/terminal_id pair is itself coherent, so nothing downstream
// can inherit an already-broken store/terminal pairing. See
// context-integrity-matrix.md for the full review.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->date('business_date');
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('status')->default('OPEN'); // OPEN|CLOSED
            $table->timestampsTz();

            $table->unique(['terminal_id', 'id']);
        });

        DB::statement("ALTER TABLE fiscal_days ADD CONSTRAINT fiscal_days_status_check CHECK (status IN ('OPEN','CLOSED'))");
        DB::statement("ALTER TABLE fiscal_days ADD CONSTRAINT fiscal_days_closed_at_check CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL))");
        // Invariant #32 -- at most one OPEN fiscal_day per terminal.
        DB::statement("CREATE UNIQUE INDEX fiscal_days_one_open_per_terminal ON fiscal_days (terminal_id) WHERE status = 'OPEN'");
        // Owner hardening pass, 2026-09-16: proves this fiscal_day's own
        // store_id/terminal_id pair is coherent (the terminal really
        // belongs to the declared store).
        DB::statement('ALTER TABLE fiscal_days ADD CONSTRAINT fiscal_days_store_terminal_fk FOREIGN KEY (store_id, terminal_id) REFERENCES terminals (store_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_days');
    }
};
