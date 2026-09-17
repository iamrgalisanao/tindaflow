<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.9 REFUND_ITEM. disposition is required, never
// defaulted (invariant #30) -- no ->default() on the column. Cumulative
// quantity/monetary caps (invariants #27/#28, DISC-004) are TRANSACTIONAL
// invariants (require summing sibling rows under a row lock on
// sale_items -- architecture.md SS24's "Refund racing another refund"),
// not expressible as a static CHECK here; see constraint-register.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('refund_id')->constrained('refunds')->restrictOnDelete();
            $table->foreignUuid('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->decimal('quantity_returned', 10, 3);
            $table->string('disposition'); // RETURN_TO_STOCK|DAMAGED|EXPIRED|DISPOSED
            $table->decimal('unit_refund_amount', 12, 2);
        });

        DB::statement("ALTER TABLE refund_items ADD CONSTRAINT refund_items_disposition_check CHECK (disposition IN ('RETURN_TO_STOCK','DAMAGED','EXPIRED','DISPOSED'))");
        DB::statement('ALTER TABLE refund_items ADD CONSTRAINT refund_items_quantity_positive_check CHECK (quantity_returned > 0)');
        DB::statement('ALTER TABLE refund_items ADD CONSTRAINT refund_items_amount_nonneg_check CHECK (unit_refund_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
    }
};
