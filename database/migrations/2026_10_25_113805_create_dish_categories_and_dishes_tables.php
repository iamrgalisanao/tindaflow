<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 2 / invariants.md MENU-005. A dish category is the price category of a meal's dishes (Beef, Chicken/Pork/Fish,
// Vegetable ...); a dish is a real record with its own id (the future inventory phase attaches to it) that the picker offers.
//
// Table kind, both tables: LIFECYCLE (configuration). The application role may INSERT and UPDATE (rename, reorder, `active`)
// but never DELETE: a dish or category is hidden with active = false because sale snapshots and the inventory phase reference
// dishes.id. Neither is in the DELETE allow-list of database/scripts/harden_append_only_privileges.sql, so the store-wide REVOKE
// DELETE already covers them; AppendOnlyPrivilegesTest proves it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dish_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->string('code'); // system generated from the first name, immutable (a stable key for future integrations)
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique(['store_id', 'code']);
            $table->index(['store_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE dish_categories ADD CONSTRAINT dish_categories_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX dish_categories_name_unique ON dish_categories (store_id, lower(name))');

        Schema::create('dishes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('dish_category_id')->constrained('dish_categories')->restrictOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['store_id', 'dish_category_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE dishes ADD CONSTRAINT dishes_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX dishes_name_unique ON dishes (store_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('dishes');
        Schema::dropIfExists('dish_categories');
    }
};
