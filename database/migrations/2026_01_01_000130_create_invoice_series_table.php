<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.6 INVOICE_SERIES -- ADR-004's row-locked counter.
// current_number/starting_number/ending_number use bigint (Stage 5
// instruction SS20: "a numeric counter type capable of the configured
// serial range" and "do not store the formatted six-digit invoice number
// as the sequence counter itself" -- current_number is the raw integer
// counter; the FORMATTED, zero-padded, digits-only display value
// (invoice.invoice_number, matching Stage 4's ^[0-9]{6,}$ contract
// pattern) is computed and stored separately on `invoices`, not here).
// `version` is retained per erd.md as an optimistic-lock column for
// future administrative tooling (ADR-004 SS"Alternatives Considered") --
// it is NOT the concurrency-control mechanism; SELECT ... FOR UPDATE is
// (ADR-004).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('series_code');
            $table->string('prefix')->nullable();
            $table->bigInteger('current_number');
            $table->bigInteger('starting_number');
            $table->bigInteger('ending_number')->nullable();
            $table->string('status')->default('ACTIVE'); // ACTIVE|CLOSED
            $table->integer('version')->default(0);
            $table->timestampsTz();

            $table->unique(['store_id', 'series_code']);
            // Owner hardening pass follow-up, 2026-09-16: referenced side
            // for invoices' composite FK proving an invoice's
            // invoice_series belongs to the same store as its sale -- see
            // the invoices migration and context-integrity-matrix.md.
            $table->unique(['store_id', 'id']);
        });

        DB::statement("ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_status_check CHECK (status IN ('ACTIVE','CLOSED'))");
        DB::statement('ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_current_number_bounds_check CHECK (current_number >= starting_number AND (ending_number IS NULL OR current_number <= ending_number))');
        // Invariant #18: no administrative rewind -- current_number may
        // only move forward. Enforced transactionally (Stage 6 -- a static
        // CHECK cannot compare a row's new value to its own prior value);
        // recorded in constraint-register.md as a TRANSACTION-level
        // invariant, not a DATABASE-level one.
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
