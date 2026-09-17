<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.5 SHIFT -- cashier/drawer accountability, scoped to
// one terminal and one cashier. Invariants #33/#34: at most one OPEN
// shift per terminal AND at most one OPEN shift per cashier (across all
// terminals) -- both enforced by partial unique indexes below
// (architecture.md SS4's database-authority table, rows 3-4). Totals
// columns are nullable until close (invariant #37: computed, never
// entered) -- populated only at the OPEN->CLOSED transition.
//
// Composite constraints (owner hardening pass, 2026-09-16, closing
// DB-INV-063): UNIQUE(terminal_id, id) lets sales/x_readings
// composite-FK against (terminal_id, shift_id), proving a referenced
// shift really belongs to that terminal. The composite FK to
// fiscal_days(terminal_id, id) below closes this shift's OWN internal
// coherence risk: nothing previously stopped a shift's fiscal_day_id
// from pointing at a fiscal_day belonging to a different terminal than
// the shift itself. See context-integrity-matrix.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_day_id')->constrained('fiscal_days')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_cash', 12, 2);
            $table->timestampTz('opened_at');
            $table->string('status')->default('OPEN'); // OPEN|CLOSED
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('declared_cash', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->decimal('cash_sales', 12, 2)->nullable();
            $table->decimal('non_cash_sales', 12, 2)->nullable();
            $table->decimal('refunds_total', 12, 2)->nullable();
            $table->decimal('cash_in_total', 12, 2)->nullable();
            $table->decimal('cash_out_total', 12, 2)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['terminal_id', 'id']);
        });

        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_status_check CHECK (status IN ('OPEN','CLOSED'))");
        DB::statement("ALTER TABLE shifts ADD CONSTRAINT shifts_closed_at_check CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL))");
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_opening_cash_nonneg_check CHECK (opening_cash >= 0)');
        // Invariants #33/#34.
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_terminal ON shifts (terminal_id) WHERE status = 'OPEN'");
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_cashier ON shifts (cashier_id) WHERE status = 'OPEN'");
        // Owner hardening pass, 2026-09-16: this shift's own fiscal_day
        // must belong to the same terminal as the shift itself.
        DB::statement('ALTER TABLE shifts ADD CONSTRAINT shifts_terminal_fiscal_day_fk FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
