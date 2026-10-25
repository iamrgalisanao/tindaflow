<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 3 / invariants.md MENU-001. What the cashier chose on a meal line, copied at the moment of sale: one row per
// chosen dish of a meal line (group name, dish name, dish category, and the price when this group priced the meal), and one row on
// an add-on line (the group and the option label, with the add-on's price). Receipts, reprints, reports and billing read the
// *_snapshot columns only, never dishes / meal_options / menu_prices, so renaming, hiding or repricing menu data cannot change a
// past sale. dish_id is a restrict foreign key kept only as the future inventory phase's attach point.
//
// Table kind: APPEND-ONLY. database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE (and DELETE) from the application
// role; tindaflow:harden-database reads that back and AppendOnlyPrivilegesTest proves an UPDATE is refused.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_item_selections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->string('group_name_snapshot');
            $table->string('option_label_snapshot');
            $table->foreignUuid('dish_id')->nullable()->constrained('dishes')->restrictOnDelete();
            $table->string('dish_name_snapshot')->nullable();
            $table->string('dish_category_snapshot')->nullable();
            $table->decimal('price_snapshot', 12, 2)->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['sale_item_id', 'sort_order']);
            $table->index('dish_id');
        });
        DB::statement('ALTER TABLE sale_item_selections ADD CONSTRAINT sale_item_selections_price_nonneg_check CHECK (price_snapshot IS NULL OR price_snapshot >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_selections');
    }
};
