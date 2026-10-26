<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 slice C. products is a LIFECYCLE table. shelf_life_days: the rough number of days a running-stock product keeps after it is received
// ("Brownies 3 days"). NULL means no shelf life is tracked. It only drives a derived "Check freshness" warning (StockFreshness): it is never an
// expiry date, never a batch, never blocks a sale and never changes a balance. The CHECK keeps it a sensible whole number of days (1 to 3650).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD COLUMN shelf_life_days smallint NULL');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_shelf_life_days_check CHECK (shelf_life_days IS NULL OR shelf_life_days BETWEEN 1 AND 3650)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_shelf_life_days_check');
        DB::statement('ALTER TABLE products DROP COLUMN shelf_life_days');
    }
};
