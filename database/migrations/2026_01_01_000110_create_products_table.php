<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.3 PRODUCT. sku uniqueness and barcode uniqueness are
// both per-store (Stage 5 instruction SS13 asks to confirm scope against
// the frozen domain: "barcode (nullable, unique per store)" is stated
// explicitly in domain-model.md SS2.3; sku carries no explicit scope
// statement there, so per-store is inferred by the same "every table is
// store_id-scoped" principle domain-model.md SS2.1 states generally --
// disclosed here as a Stage 5 inference, not a quoted frozen rule).
// active state (never destructive delete) per Stage 5 instruction SS13.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUuid('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('unit_of_measure');
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('selling_price', 12, 2);
            $table->string('tax_class'); // VATABLE|VAT_EXEMPT|ZERO_RATED|NON_VAT
            $table->boolean('track_inventory')->default(true);
            $table->integer('reorder_level')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['store_id', 'sku']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_tax_class_check CHECK (tax_class IN ('VATABLE','VAT_EXEMPT','ZERO_RATED','NON_VAT'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_cost_nonneg_check CHECK (cost IS NULL OR cost >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_selling_price_nonneg_check CHECK (selling_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_reorder_level_nonneg_check CHECK (reorder_level >= 0)');
        // Partial: barcode is nullable, so a plain UNIQUE column allows only
        // one NULL under Postgres semantics anyway, but we scope explicitly
        // per store and only over non-null values for clarity.
        DB::statement('CREATE UNIQUE INDEX products_barcode_unique_per_store ON products (store_id, barcode) WHERE barcode IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
