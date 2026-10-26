<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 1 (slice A). The store's stock rules as typed columns on store_settings, which is a LIFECYCLE table (the application
// updates it; the change is ADMIN-only and audited as STOCK_RULES_CHANGED):
//  - block_sale_without_stock    the master switch, default FALSE. FALSE keeps stage 14's behaviour exactly: a sale never refuses and a
//    balance may go below zero. TRUE: a sale that demands more of a tracked product than is on hand is refused (INSUFFICIENT_STOCK).
//  - stock_override_enabled      default TRUE. Only matters while blocking is on: whether a manager may approve one blocked sale.
//  - low_stock_default_threshold default 3 ("atleast last 3"): the threshold used by every product whose reorder_level is NULL.
// stock_day_cutover_hour belongs to slice B (daily dish stock) and is deliberately NOT added here.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE store_settings ADD COLUMN block_sale_without_stock boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE store_settings ADD COLUMN stock_override_enabled boolean NOT NULL DEFAULT true');
        DB::statement('ALTER TABLE store_settings ADD COLUMN low_stock_default_threshold smallint NOT NULL DEFAULT 3');
        DB::statement('ALTER TABLE store_settings ADD CONSTRAINT store_settings_low_stock_default_threshold_check CHECK (low_stock_default_threshold BETWEEN 0 AND 1000)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE store_settings DROP CONSTRAINT store_settings_low_stock_default_threshold_check');
        DB::statement('ALTER TABLE store_settings DROP COLUMN low_stock_default_threshold');
        DB::statement('ALTER TABLE store_settings DROP COLUMN stock_override_enabled');
        DB::statement('ALTER TABLE store_settings DROP COLUMN block_sale_without_stock');
    }
};
