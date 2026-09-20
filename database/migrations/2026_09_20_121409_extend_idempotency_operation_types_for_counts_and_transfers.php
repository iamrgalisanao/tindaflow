<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stage 25: posting a stock count and creating a stock transfer both write ledger movements
// against a terminal, so a retried request must not count twice (same reasoning as
// STOCK_RECEIPT / STOCK_ADJUSTMENT). The constraint is replaced, not the table: the original 14
// values are kept exactly. IdempotencyOperationType is the matching PHP enum.
return new class extends Migration
{
    private const ORIGINAL = "'CHECKOUT','SALE_VOID','SALE_REFUND','VOID_APPROVE','VOID_REJECT','REFUND_APPROVE','REFUND_REJECT','SHIFT_OPEN','SHIFT_CLOSE','CASH_MOVEMENT','STOCK_RECEIPT','STOCK_ADJUSTMENT','FISCAL_DAY_CLOSE','INVOICE_REPRINT'";

    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_records DROP CONSTRAINT idempotency_records_operation_type_check');
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_type_check CHECK (operation_type IN ('.self::ORIGINAL.",'STOCK_COUNT_POST','STOCK_TRANSFER'))");
    }

    public function down(): void
    {
        DB::table('idempotency_records')->whereIn('operation_type', ['STOCK_COUNT_POST', 'STOCK_TRANSFER'])->delete();
        DB::statement('ALTER TABLE idempotency_records DROP CONSTRAINT idempotency_records_operation_type_check');
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_type_check CHECK (operation_type IN ('.self::ORIGINAL.'))');
    }
};
