<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 2. A meal (Standard Meal, Combo Meal) is built from option groups; a group offers options, each either a dish
// (a CHOICE group) or a labelled add-on with its own backing product (an ADDON group, e.g. Extra rice).
//
// Table kind, all three tables: LIFECYCLE (configuration). INSERT and UPDATE for the application role, never DELETE: an
// option, group or meal is hidden with active = false because sale snapshots (slice 1.3) and billing refer to them
// (invariants.md MENU-005). All three are already covered by the store-wide REVOKE DELETE in
// database/scripts/harden_append_only_privileges.sql.
//
// Database guarantees: at most one pricing group per meal (the service creates it with the meal, so it is exactly one),
// min_select <= max_select, a pricing group is a CHOICE group that needs exactly one pick, and an option is either a dish or
// an add-on, never both. Which kind of option a group accepts, and the rest of the configuration rules, are in app/Services/Menu.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('menu_group_id')->nullable()->constrained('menu_groups')->restrictOnDelete();
            $table->foreignUuid('backing_product_id')->constrained('products')->restrictOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->unique('backing_product_id');
            $table->index(['store_id', 'sort_order']);
        });
        DB::statement("ALTER TABLE meals ADD CONSTRAINT meals_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement('CREATE UNIQUE INDEX meals_name_unique ON meals (store_id, lower(name))');

        Schema::create('meal_option_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('meal_id')->constrained('meals')->restrictOnDelete();
            $table->string('name');
            $table->string('kind'); // CHOICE (dishes) | ADDON (labelled extras)
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->boolean('prices_the_meal')->default(false);
            $table->boolean('popup_after_choice')->default(false); // the extra-rice pop-up after the viand is chosen
            $table->integer('step_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['meal_id', 'step_order']);
        });
        DB::statement("ALTER TABLE meal_option_groups ADD CONSTRAINT meal_option_groups_name_not_blank_check CHECK (btrim(name) <> '')");
        DB::statement("ALTER TABLE meal_option_groups ADD CONSTRAINT meal_option_groups_kind_check CHECK (kind IN ('CHOICE','ADDON'))");
        DB::statement('ALTER TABLE meal_option_groups ADD CONSTRAINT meal_option_groups_min_max_check CHECK (min_select <= max_select AND max_select >= 1)');
        DB::statement("ALTER TABLE meal_option_groups ADD CONSTRAINT meal_option_groups_pricing_check CHECK (NOT prices_the_meal OR (kind = 'CHOICE' AND min_select = 1 AND max_select = 1 AND active))");
        DB::statement('CREATE UNIQUE INDEX meal_option_groups_name_unique ON meal_option_groups (meal_id, lower(name))');
        DB::statement('CREATE UNIQUE INDEX meal_option_groups_one_pricing_group ON meal_option_groups (meal_id) WHERE prices_the_meal');

        Schema::create('meal_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('meal_option_group_id')->constrained('meal_option_groups')->restrictOnDelete();
            $table->foreignUuid('dish_id')->nullable()->constrained('dishes')->restrictOnDelete();
            $table->string('label')->nullable();
            $table->foreignUuid('addon_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['meal_option_group_id', 'sort_order']);
        });
        DB::statement('ALTER TABLE meal_options ADD CONSTRAINT meal_options_dish_or_addon_check CHECK ('
            ."(dish_id IS NOT NULL AND label IS NULL AND addon_product_id IS NULL) OR (dish_id IS NULL AND btrim(label) <> '' AND addon_product_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX meal_options_dish_unique ON meal_options (meal_option_group_id, dish_id) WHERE dish_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX meal_options_label_unique ON meal_options (meal_option_group_id, lower(label)) WHERE label IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX meal_options_addon_product_unique ON meal_options (addon_product_id) WHERE addon_product_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_options');
        Schema::dropIfExists('meal_option_groups');
        Schema::dropIfExists('meals');
    }
};
