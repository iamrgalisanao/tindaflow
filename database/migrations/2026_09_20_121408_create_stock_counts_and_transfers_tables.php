<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 25 (docs/06-backend/stage-25-stock-counts-and-transfers.md). Two new documents that sit
// beside the stock ledger and never replace it: a count or a transfer is a record of WHY movements
// were written, and the movements themselves still go through StockLedger (the only writer of
// stock_movements / stock_balances). Neither table is a balance; nothing here can change on-hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('status');
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_status_check CHECK (status IN ('OPEN','POSTED','CANCELLED'))");
        // A count is in exactly one state, and the state's own timestamp and actor say so.
        DB::statement("ALTER TABLE stock_counts ADD CONSTRAINT stock_counts_state_coherence_check CHECK (
            (status = 'OPEN' AND posted_at IS NULL AND cancelled_at IS NULL)
            OR (status = 'POSTED' AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND cancelled_at IS NULL)
            OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND posted_at IS NULL)
        )");
        // One count in progress per location. Two open counts of the same shelf would each post a
        // correction against the same stock and apply it twice; several people share one count instead.
        DB::statement("CREATE UNIQUE INDEX stock_counts_one_open_per_location ON stock_counts (location_id) WHERE status = 'OPEN'");
        DB::statement('CREATE INDEX stock_counts_store_created_idx ON stock_counts (store_id, created_at)');

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_count_id')->constrained('stock_counts')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('counted_quantity', 10, 3);
            // What the ledger said was on hand at the moment this line was recorded. The correction is
            // counted - expected, so movements that happen after the line was recorded are left alone.
            $table->decimal('expected_quantity', 10, 3);
            $table->foreignUuid('counted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('counted_at')->useCurrent();
            // Set when the count is posted and the line produced a correction.
            $table->foreignUuid('stock_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();

            $table->unique(['stock_count_id', 'product_id']);
        });

        DB::statement('ALTER TABLE stock_count_lines ADD CONSTRAINT stock_count_lines_counted_non_negative_check CHECK (counted_quantity >= 0)');

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('from_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('to_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            // Append-only, like the ledger it writes: a wrong transfer is corrected by transferring back.
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_distinct_locations_check CHECK (from_location_id <> to_location_id)');
        DB::statement('CREATE INDEX stock_transfers_store_created_idx ON stock_transfers (store_id, created_at)');

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained('stock_transfers')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 10, 3);

            $table->unique(['stock_transfer_id', 'product_id']);
        });

        DB::statement('ALTER TABLE stock_transfer_lines ADD CONSTRAINT stock_transfer_lines_quantity_positive_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
