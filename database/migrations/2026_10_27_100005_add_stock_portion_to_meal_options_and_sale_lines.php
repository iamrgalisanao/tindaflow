<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 3 and 5 (slice B).
//  - meal_options.stock_portion  Table kind: LIFECYCLE (configuration). Portions of the option's dish one plate takes from the dish's daily
//    pool, editable in the menu builder. NUMERIC(10,3) CHECK > 0. Client decision 1 (2026-10-27): Standard viand 1.0, Combo main 1.0, Combo
//    vegetable side 1.0. Applies only when the option's dish is DAILY; an add-on option (rice) never draws stock.
//  - sale_item_selections.stock_portion_snapshot  Table kind: APPEND-ONLY (no UPDATE for the application role): the portion actually drawn
//    from the dish's pool for this chosen dish when the plate was sold; NULL when nothing was drawn. A later menu change never alters it.
//  - sale_items.stock_portion_snapshot  Table kind: APPEND-ONLY: for an ala carte line of a product linked to a dish, the portions drawn per
//    unit sold at the time (products.stock_portion then); NULL otherwise.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE meal_options ADD COLUMN stock_portion numeric(10,3) NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE meal_options ADD CONSTRAINT meal_options_stock_portion_positive_check CHECK (stock_portion > 0)');
        DB::statement('ALTER TABLE sale_item_selections ADD COLUMN stock_portion_snapshot numeric(10,3) NULL');
        DB::statement('ALTER TABLE sale_item_selections ADD CONSTRAINT sale_item_selections_stock_portion_positive_check CHECK (stock_portion_snapshot IS NULL OR stock_portion_snapshot > 0)');
        DB::statement('ALTER TABLE sale_items ADD COLUMN stock_portion_snapshot numeric(10,3) NULL');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_stock_portion_positive_check CHECK (stock_portion_snapshot IS NULL OR stock_portion_snapshot > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT sale_items_stock_portion_positive_check');
        DB::statement('ALTER TABLE sale_items DROP COLUMN stock_portion_snapshot');
        DB::statement('ALTER TABLE sale_item_selections DROP CONSTRAINT sale_item_selections_stock_portion_positive_check');
        DB::statement('ALTER TABLE sale_item_selections DROP COLUMN stock_portion_snapshot');
        DB::statement('ALTER TABLE meal_options DROP CONSTRAINT meal_options_stock_portion_positive_check');
        DB::statement('ALTER TABLE meal_options DROP COLUMN stock_portion');
    }
};
