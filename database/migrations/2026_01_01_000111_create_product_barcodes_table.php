<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// domain-model.md SS2.3 PRODUCT_BARCODE -- alternate/multipack barcodes
// per product. store_id is denormalized here (not in the frozen ERD's
// column list) purely so the same per-store barcode-uniqueness scope as
// products.barcode can be expressed as a plain composite index without a
// join -- disclosed as a Stage 5 schema-implementation addition, not a
// frozen-domain field.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('barcode');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['store_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
    }
};
