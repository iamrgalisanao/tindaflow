<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 3 (slice B). Table kind: `dishes` stays a LIFECYCLE (configuration) table; never deleted.
//  - stock_mode        NONE | DAILY. A dish is never RUNNING. DAILY: its portions are counted per stock date.
//  - stock_product_id  the dish's hidden POOL product (menu_hidden, inactive, tracked, DAILY), created when the dish first becomes DAILY and
//    kept (renamed with the dish) if it is switched back, because movements reference it. Every stock row stays keyed by a real product.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE dishes ADD COLUMN stock_mode varchar(10) NOT NULL DEFAULT 'NONE'");
        DB::statement('ALTER TABLE dishes ADD COLUMN stock_product_id uuid NULL REFERENCES products (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE dishes ADD CONSTRAINT dishes_stock_mode_check CHECK (stock_mode IN ('NONE','DAILY'))");
        DB::statement("ALTER TABLE dishes ADD CONSTRAINT dishes_daily_has_pool_check CHECK (stock_mode <> 'DAILY' OR stock_product_id IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX dishes_stock_product_unique ON dishes (stock_product_id) WHERE stock_product_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX dishes_stock_product_unique');
        DB::statement('ALTER TABLE dishes DROP CONSTRAINT dishes_daily_has_pool_check');
        DB::statement('ALTER TABLE dishes DROP CONSTRAINT dishes_stock_mode_check');
        DB::statement('ALTER TABLE dishes DROP COLUMN stock_product_id');
        DB::statement('ALTER TABLE dishes DROP COLUMN stock_mode');
    }
};
