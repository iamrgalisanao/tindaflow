<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.5 X_READING -- Cashier's Accountability / End-of-Shift
// Report. Invariant #43: zero-or-more XReadings per shift (on-demand pulls
// plus one automatic closing reading) -- deliberately NO UNIQUE(shift_id).
// is_closing_reading (openapi.yaml XReading, not named as a column in
// domain-model.md's prose but consistent with state-machines.md SS5/SS6's
// "closing X-Reading" concept) distinguishes the one automatic reading
// generated at shift close from interim, on-demand ones -- Stage 5
// instruction SS18 requires this to be enforced via a partial unique
// index: at most one closing reading per shift.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('from_at');
            $table->timestampTz('to_at');
            $table->timestampTz('generated_at');
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_closing_reading')->default(false);
            $table->jsonb('totals_snapshot');
        });

        // Stage 5 instruction SS18 / invariant #43: at most one CLOSING
        // reading per shift; interim readings remain unlimited.
        DB::statement('CREATE UNIQUE INDEX x_readings_one_closing_per_shift ON x_readings (shift_id) WHERE is_closing_reading = true');
        // Owner hardening pass, 2026-09-16, closing DB-INV-063: proves
        // the reading's own terminal_id and shift_id agree -- an
        // x_reading claiming terminal A but citing a shift that
        // actually ran on terminal B is now a constraint violation.
        DB::statement('ALTER TABLE x_readings ADD CONSTRAINT x_readings_terminal_shift_fk FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('x_readings');
    }
};
