<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.4 STOCK_MOVEMENT -- authoritative inventory ledger
// (Stage 5 instruction SS14/SS15). Append-only (invariant #45): no
// updated_at. quantity is always POSITIVE (erd.md: "direction implied by
// movement_type") -- a CHECK enforces this directly; the ledger's sign
// convention lives in application logic (Stage 6), not a negative column
// value. reference_type/reference_id are a deliberate, disclosed
// exception to "avoid generic polymorphism" (Stage 5 instruction SS15):
// a stock_movement can originate from a SaleItem, a RefundItem, a stock
// receipt, or a manual adjustment -- four distinct, rarely-joined source
// kinds recorded for audit/traceability display only, never used to
// enforce a foreign-key-level invariant (no code path relies on this
// pair for referential integrity the way sale_item_id/refund_item_id
// direct FKs would) -- the trade-off is explained in
// database-schema.md SS"Inventory ledger".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignUuid('terminal_id')->nullable()->constrained('terminals')->restrictOnDelete();
            $table->string('movement_type');
            $table->decimal('quantity', 10, 3);
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at')->useCurrent();
        });

        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('OPENING_STOCK','PURCHASE_RECEIPT','SALE','SALE_RETURN','STOCK_ADJUSTMENT_IN','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED','TRANSFER_IN','TRANSFER_OUT'))");
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_positive_check CHECK (quantity > 0)');
        // Invariant #46 -- adjustments require a reason.
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_adjustment_reason_check CHECK (
            movement_type NOT IN ('STOCK_ADJUSTMENT_IN','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED')
            OR (reason IS NOT NULL AND btrim(reason) <> '')
        )");
        $table = 'stock_movements';
        DB::statement("CREATE INDEX {$table}_product_occurred_idx ON {$table} (product_id, occurred_at)");
        DB::statement("CREATE INDEX {$table}_reference_idx ON {$table} (reference_type, reference_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
