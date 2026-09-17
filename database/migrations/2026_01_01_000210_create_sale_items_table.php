<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.7 SALE_ITEM -- immutable financial snapshot per line
// (invariants #7, DISC-002/DISC-003). Column names match domain-model.md
// SS2.7 / erd.md's SALE_ITEM block exactly (Stage 5 instruction SS23).
// product_id is "reference only" (erd.md) -- never re-read for historical
// figures; nullOnDelete would be wrong here (a deleted product must never
// silently blank a historical sale line), so it is restrictOnDelete, and
// in practice products are never hard-deleted at all (active flag only,
// SS13).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('line_number');
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot');
            $table->string('barcode_snapshot')->nullable();
            $table->string('unit_of_measure_snapshot');
            $table->decimal('quantity', 10, 3);
            $table->decimal('unit_price_snapshot', 12, 2);
            $table->decimal('gross_line_amount', 12, 2);
            $table->decimal('line_discount_amount', 12, 2)->default(0);
            $table->boolean('order_discount_eligible')->default(true);
            $table->decimal('allocated_order_discount_amount', 12, 2)->default(0);
            $table->decimal('net_line_amount', 12, 2);
            $table->string('tax_classification_snapshot'); // VATABLE|VAT_EXEMPT|ZERO_RATED|NON_VAT
            $table->decimal('tax_rate_snapshot', 6, 4);
            $table->decimal('taxable_base', 12, 2);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable();

            $table->unique(['sale_id', 'line_number']);
        });

        DB::statement("ALTER TABLE sale_items ADD CONSTRAINT sale_items_tax_classification_check CHECK (tax_classification_snapshot IN ('VATABLE','VAT_EXEMPT','ZERO_RATED','NON_VAT'))");
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_positive_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_unit_price_nonneg_check CHECK (unit_price_snapshot >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_gross_line_nonneg_check CHECK (gross_line_amount >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_line_discount_nonneg_check CHECK (line_discount_amount >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_allocated_discount_nonneg_check CHECK (allocated_order_discount_amount >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_net_line_nonneg_check CHECK (net_line_amount >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_taxable_base_nonneg_check CHECK (taxable_base >= 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_tax_amount_nonneg_check CHECK (tax_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
