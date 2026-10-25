<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 2 / invariants.md MENU-004. The price history of the menu. A row prices either a meal for a dish category
// (meal_id + dish_category_id: "Standard Meal with a Beef dish costs 68") or one add-on option (meal_option_id: "Whole rice 15").
// The price in force at an instant is the row of that key with the latest effective_from <= the instant. A price change is a
// new row; no row is ever updated, so the table is itself the history.
//
// Table kind: APPEND-ONLY. database/scripts/harden_append_only_privileges.sql REVOKEs UPDATE (and DELETE) from the application
// role; tindaflow:harden-database reads that back and AppendOnlyPrivilegesTest proves an UPDATE is refused.
//
// Database guarantees: exactly one of the two keys is used; two rows cannot share a key and effective_from (an unambiguous
// price at every instant); the amount is not negative.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('meal_id')->nullable()->constrained('meals')->restrictOnDelete();
            $table->foreignUuid('dish_category_id')->nullable()->constrained('dish_categories')->restrictOnDelete();
            $table->foreignUuid('meal_option_id')->nullable()->constrained('meal_options')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestampTz('effective_from');
            $table->foreignUuid('changed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['meal_id', 'dish_category_id', 'effective_from']);
            $table->index(['meal_option_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE menu_prices ADD CONSTRAINT menu_prices_amount_nonneg_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE menu_prices ADD CONSTRAINT menu_prices_one_key_check CHECK ('
            .'(meal_id IS NOT NULL AND dish_category_id IS NOT NULL AND meal_option_id IS NULL) OR (meal_id IS NULL AND dish_category_id IS NULL AND meal_option_id IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX menu_prices_meal_key_unique ON menu_prices (meal_id, dish_category_id, effective_from) WHERE meal_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX menu_prices_option_key_unique ON menu_prices (meal_option_id, effective_from) WHERE meal_option_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_prices');
    }
};
