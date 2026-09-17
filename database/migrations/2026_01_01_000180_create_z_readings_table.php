<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.5 Z_READING -- End-of-Day Report, exactly one per
// fiscal_day (invariant #42), created atomically with the OPEN->CLOSED
// transition (architecture.md SS8). z_counter is this terminal's running
// Z-Reading sequence number (RMO 24-2023's Z Counter requirement,
// ZReadingTotalsSnapshot in openapi.yaml) -- derived by counting this
// terminal's prior z_readings at generation time (not a separately
// mutated counter column), and protected here by its own uniqueness
// constraint as a defense-in-depth backstop against ever assigning the
// same counter value twice for one terminal. reset_counter is not a
// separate column: TindaFlow has no reset function (architecture.md
// SS4/openapi.yaml's own note that this is expected to remain 0 for the
// life of a terminal), so it is carried inside totals_snapshot only,
// where it costs nothing to keep at a constant 0.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('z_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignUuid('fiscal_day_id')->constrained('fiscal_days')->restrictOnDelete();
            $table->date('business_date');
            $table->timestampTz('from_at');
            $table->timestampTz('to_at');
            $table->timestampTz('generated_at');
            $table->foreignUuid('generated_by')->constrained('users')->restrictOnDelete();
            $table->integer('z_counter');
            $table->jsonb('totals_snapshot');

            // Invariant #42: exactly one Z-Reading per fiscal_day.
            $table->unique('fiscal_day_id');
        });

        // "Never reused" (openapi.yaml ZReadingTotalsSnapshot.z_counter).
        DB::statement('CREATE UNIQUE INDEX z_readings_counter_unique_per_terminal ON z_readings (terminal_id, z_counter)');
        // Owner hardening pass, 2026-09-16, closing DB-INV-063: proves
        // the reading's own terminal_id and fiscal_day_id agree.
        DB::statement('ALTER TABLE z_readings ADD CONSTRAINT z_readings_terminal_fiscal_day_fk FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days (terminal_id, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('z_readings');
    }
};
