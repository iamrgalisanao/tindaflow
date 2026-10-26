<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 1 and 3 (slice B). Table kind: `products` stays a LIFECYCLE table; these are ordinary columns of it.
//  - stock_mode     NONE | RUNNING | DAILY. Backfilled from track_inventory (tracked -> RUNNING, untracked -> NONE) and kept equal to it by a
//    CHECK (track_inventory = (stock_mode <> 'NONE')), so every reader of track_inventory stays valid. The column default is RUNNING because
//    the track_inventory default is true: a bare INSERT satisfies the CHECK. The ADR-016 hidden backing products stay NONE.
//  - dish_id        the ALA CARTE link: a product with dish_id draws `stock_portion` of the dish's daily pool per unit sold (client decision 2,
//    2026-10-27: every dish can be sold alone, at 2.0 portions). Restrict FK; only a DAILY product may carry it.
//  - stock_portion  portions of the dish consumed per unit sold; NUMERIC(10,3) so the client can change it (1, 2, 0.5 ...). Meaningful only
//    with dish_id.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products ADD COLUMN stock_mode varchar(10) NOT NULL DEFAULT 'RUNNING'");
        DB::statement("UPDATE products SET stock_mode = CASE WHEN track_inventory THEN 'RUNNING' ELSE 'NONE' END");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_mode_check CHECK (stock_mode IN ('NONE','RUNNING','DAILY'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_mode_tracks_check CHECK (track_inventory = (stock_mode <> 'NONE'))");

        DB::statement('ALTER TABLE products ADD COLUMN dish_id uuid NULL REFERENCES dishes (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE products ADD COLUMN stock_portion numeric(10,3) NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_portion_positive_check CHECK (stock_portion > 0)');
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_dish_link_daily_check CHECK (dish_id IS NULL OR stock_mode = 'DAILY')");
        DB::statement('CREATE INDEX products_dish_id_idx ON products (dish_id) WHERE dish_id IS NOT NULL');
        // At most one ala carte product per dish and store would be too strict (a half order could be a second product), so the link is not unique.
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_dish_id_idx');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_dish_link_daily_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_stock_portion_positive_check');
        DB::statement('ALTER TABLE products DROP COLUMN stock_portion');
        DB::statement('ALTER TABLE products DROP COLUMN dish_id');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_stock_mode_tracks_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_stock_mode_check');
        DB::statement('ALTER TABLE products DROP COLUMN stock_mode');
    }
};
