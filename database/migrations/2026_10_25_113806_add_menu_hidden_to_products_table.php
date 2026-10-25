<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 3: every meal (and every add-on option) has a hidden backing product, kept only so a sale line has a product
// for its foreign key, tax class and product reports. `menu_hidden` marks it: the product list, the barcode lookup, the CSV
// export and the product admin API never show or touch such a product, so it can never appear on the till grid or in search.
//
// Table kind: `products` stays a LIFECYCLE table; this adds one column, set when the backing product is created and never changed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('menu_hidden')->default(false)->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('menu_hidden');
        });
    }
};
