<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 4 (slice B). store_settings is a LIFECYCLE table. stock_day_cutover_hour: the hour (0 to 6, store-local) at which the stock day
// starts. A sale's stock date is the store-local date of the sale minus this many hours. Default 0 (the stock day is the calendar day); the
// canteen's go-live loader sets 5 (the day runs 05:00 to 05:00, client decision 3). Changed on the Stock Rules screen and audited
// (STOCK_RULES_CHANGED).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE store_settings ADD COLUMN stock_day_cutover_hour smallint NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE store_settings ADD CONSTRAINT store_settings_stock_day_cutover_hour_check CHECK (stock_day_cutover_hour BETWEEN 0 AND 6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE store_settings DROP CONSTRAINT store_settings_stock_day_cutover_hour_check');
        DB::statement('ALTER TABLE store_settings DROP COLUMN stock_day_cutover_hour');
    }
};
