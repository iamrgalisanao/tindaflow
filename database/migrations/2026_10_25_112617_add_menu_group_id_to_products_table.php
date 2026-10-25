<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 1: a product is placed on the till's menu tree by a nullable menu_group_id. A product in no group is still
// sellable and found by search. `category_id` is untouched. RESTRICT, not SET NULL: menu groups are never deleted (MENU-005).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignUuid('menu_group_id')->nullable()->after('brand_id')->constrained('menu_groups')->restrictOnDelete();
            $table->index(['store_id', 'menu_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['store_id', 'menu_group_id']);
            $table->dropConstrainedForeignId('menu_group_id');
        });
    }
};
