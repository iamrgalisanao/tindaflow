<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Stage 29 (docs/06-backend/stage-29-packaging-and-pack-receiving.md). A row of `product_barcodes` becomes a PACKAGING of
// the product: a barcode (now optional), a name such as "Case", and how many single units one of them holds. The stock
// itself stays in the product's one base unit; this only says how to convert a pack count into it. The product's own
// barcode and unit of measure are the base unit, so there is no "base" row and nothing to keep in step with it.
//
//   units_per_base  how many base units one of these holds (1 for an alias barcode of the single unit)
//   can_receive     may this packaging be used when receiving stock
//   can_sell        reserved for selling by the pack. Nothing reads it yet: pack selling is off, and is switched on
//                   later per store; the column exists so the model is checkout-capable from day one
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->string('name', 60)->nullable();
            $table->decimal('units_per_base', 10, 3)->default(1);
            $table->boolean('can_receive')->default(true);
            $table->boolean('can_sell')->default(false);
        });

        // A case without a scannable code is still a case: the barcode is optional. The existing unique index on
        // (store_id, barcode) keeps working, because PostgreSQL treats each NULL as distinct.
        DB::statement('ALTER TABLE product_barcodes ALTER COLUMN barcode DROP NOT NULL');

        DB::statement('ALTER TABLE product_barcodes ADD CONSTRAINT product_barcodes_units_positive_check CHECK (units_per_base > 0)');
        DB::statement('ALTER TABLE product_barcodes ADD CONSTRAINT product_barcodes_identifiable_check CHECK (barcode IS NOT NULL OR name IS NOT NULL)');
        // One "Case" per product, whatever the capitalisation.
        DB::statement('CREATE UNIQUE INDEX product_barcodes_product_name_unique ON product_barcodes (product_id, lower(name)) WHERE name IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_barcodes_product_name_unique');
        DB::statement('ALTER TABLE product_barcodes DROP CONSTRAINT IF EXISTS product_barcodes_identifiable_check');
        DB::statement('ALTER TABLE product_barcodes DROP CONSTRAINT IF EXISTS product_barcodes_units_positive_check');

        // A packaging without a barcode cannot exist in the old shape.
        DB::table('product_barcodes')->whereNull('barcode')->delete();
        DB::statement('ALTER TABLE product_barcodes ALTER COLUMN barcode SET NOT NULL');

        Schema::table('product_barcodes', function (Blueprint $table) {
            $table->dropColumn(['name', 'units_per_base', 'can_receive', 'can_sell']);
        });
    }
};
