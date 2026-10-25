<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-016 section 3 / invariants.md MENU-001. A meal (or an add-on of a meal) is still an ordinary sale_items row; these four
// nullable columns are its snapshot. They are written in the same INSERT as the rest of the row and never changed.
//   menu_group_path_snapshot  "Lunch > Standard Meal": where the line sat in the menu when it was sold (also filled for a plain
//                             product that has a menu group, so billing can group by meal type);
//   meal_id                   the meal sold (or the meal an add-on belongs to); a restrict foreign key kept for reports, never read for a name;
//   meal_name_snapshot        the meal's name when it was sold;
//   parent_line_number        set on an add-on line: the line_number of the meal line it was ordered with.
// An existing row, and every sale without a meal, has all four NULL and is untouched.
//
// Table kind: sale_items stays APPEND-ONLY (UPDATE revoked from the application role by harden_append_only_privileges.sql).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('menu_group_path_snapshot')->nullable();
            $table->foreignUuid('meal_id')->nullable()->constrained('meals')->restrictOnDelete();
            $table->string('meal_name_snapshot')->nullable();
            $table->unsignedInteger('parent_line_number')->nullable();

            $table->index('meal_id');
        });
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_parent_line_before_check CHECK (parent_line_number IS NULL OR parent_line_number < line_number)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_parent_line_needs_meal_check CHECK (parent_line_number IS NULL OR meal_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT IF EXISTS sale_items_parent_line_needs_meal_check');
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT IF EXISTS sale_items_parent_line_before_check');
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['meal_id']);
            $table->dropConstrainedForeignId('meal_id');
            $table->dropColumn(['menu_group_path_snapshot', 'meal_name_snapshot', 'parent_line_number']);
        });
    }
};
