<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 2 (slice A). products.reorder_level becomes NULLABLE with no default: NULL means "use the store's low-stock default"
// (store_settings.low_stock_default_threshold, 3 out of the box), a number is a deliberate per-product override. products is a LIFECYCLE
// table. The old column default was 0 ("never set"), so every existing 0 becomes NULL; a non-zero value was typed on purpose and is kept.
// A product given an explicit 0 afterwards means "warn only when it is sold out".
//
// The CHECK (reorder_level >= 0) from the products migration stays; it is satisfied by NULL.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ALTER COLUMN reorder_level DROP NOT NULL');
        DB::statement('ALTER TABLE products ALTER COLUMN reorder_level DROP DEFAULT');
        DB::statement('UPDATE products SET reorder_level = NULL WHERE reorder_level = 0');
    }

    public function down(): void
    {
        DB::statement('UPDATE products SET reorder_level = 0 WHERE reorder_level IS NULL');
        DB::statement('ALTER TABLE products ALTER COLUMN reorder_level SET DEFAULT 0');
        DB::statement('ALTER TABLE products ALTER COLUMN reorder_level SET NOT NULL');
    }
};
