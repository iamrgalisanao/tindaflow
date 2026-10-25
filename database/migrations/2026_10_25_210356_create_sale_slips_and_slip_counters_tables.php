<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 7 / invariants.md SLIP-001. The document of a sale finalized in SLIP mode.
//
// sale_slips  Table kind: APPEND-ONLY. One row per slip sale (sale_id UNIQUE), written in the checkout transaction. `slip_number` is
//   "<terminal code>-<6 digits>"; `slip_sequence` is the integer behind it. `slip_snapshot_json` is everything the slip printed (store
//   name, cashier, lines, total, payments, footer), copied at the sale, so a reprint reads it and never today's settings or menu
//   (ADR-006). database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE (and DELETE) from the application role;
//   tindaflow:harden-database reads that back and AppendOnlyPrivilegesTest proves an UPDATE is refused.
//
// slip_counters  Table kind: LIFECYCLE (the running number of each terminal). `last_number` is incremented inside the checkout
//   transaction under the shift lock it already holds, so a sale that rolls back rolls its number back with it: committed slips have no
//   gap. Column-level UPDATE only (last_number, updated_at) for the application role; never deleted. It is a different sequence from
//   invoice_series and is never formatted like an invoice number.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slip_counters', function (Blueprint $table) {
            $table->foreignUuid('terminal_id')->primary()->constrained('terminals')->restrictOnDelete();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestampsTz();
        });

        Schema::create('sale_slips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->string('slip_number', 40);
            $table->unsignedBigInteger('slip_sequence');
            $table->jsonb('slip_snapshot_json');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['store_id', 'slip_number']);
            $table->unique(['terminal_id', 'slip_sequence']);
        });
        DB::statement('ALTER TABLE sale_slips ADD CONSTRAINT sale_slips_sequence_positive_check CHECK (slip_sequence > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_slips');
        Schema::dropIfExists('slip_counters');
    }
};
