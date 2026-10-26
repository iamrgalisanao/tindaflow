<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 section 3 (slice B). Table kind: `stock_movements` stays APPEND-ONLY (the application role has no UPDATE); these are new
// columns written at INSERT only.
//  - stock_date   the store's stock day a DAILY movement belongs to (NULL for RUNNING items and every old row). Required for the two new
//    types.
//  - approved_by  who approved a cashier's daily stock entry with their code (client decision 5, 2026-10-27); NULL for a direct entry.
//  - DAILY_PORTIONS_ADDED (inflow) and DAILY_PORTIONS_REMOVED (outflow, reason required) join the type list.
return new class extends Migration
{
    private const OLD_TYPES = "'OPENING_STOCK','PURCHASE_RECEIPT','SALE','SALE_RETURN','STOCK_ADJUSTMENT_IN','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED','TRANSFER_IN','TRANSFER_OUT'";

    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements ADD COLUMN stock_date date NULL');
        DB::statement('ALTER TABLE stock_movements ADD COLUMN approved_by uuid NULL REFERENCES users (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('.self::OLD_TYPES.",'DAILY_PORTIONS_ADDED','DAILY_PORTIONS_REMOVED'))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_daily_date_check CHECK (movement_type NOT IN ('DAILY_PORTIONS_ADDED','DAILY_PORTIONS_REMOVED') OR stock_date IS NOT NULL)");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_adjustment_reason_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_adjustment_reason_check CHECK (
            movement_type NOT IN ('STOCK_ADJUSTMENT_IN','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED','DAILY_PORTIONS_REMOVED')
            OR (reason IS NOT NULL AND btrim(reason) <> '')
        )");
        DB::statement('CREATE INDEX stock_movements_product_stock_date_idx ON stock_movements (product_id, stock_date) WHERE stock_date IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX stock_movements_product_stock_date_idx');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_adjustment_reason_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_adjustment_reason_check CHECK (
            movement_type NOT IN ('STOCK_ADJUSTMENT_IN','STOCK_ADJUSTMENT_OUT','DAMAGE','EXPIRED')
            OR (reason IS NOT NULL AND btrim(reason) <> '')
        )");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_daily_date_check');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('.self::OLD_TYPES.'))');
        DB::statement('ALTER TABLE stock_movements DROP COLUMN approved_by');
        DB::statement('ALTER TABLE stock_movements DROP COLUMN stock_date');
    }
};
