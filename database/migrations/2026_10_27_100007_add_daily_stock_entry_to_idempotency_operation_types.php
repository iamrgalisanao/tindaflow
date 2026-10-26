<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-017 slice B: saving the daily stock grid records movements against a terminal, so a retried request must not count twice. The constraint is
// replaced, not the table: every earlier value is kept exactly. IdempotencyOperationType is the matching PHP enum.
return new class extends Migration
{
    private const EARLIER = "'CHECKOUT','SALE_VOID','SALE_REFUND','VOID_APPROVE','VOID_REJECT','REFUND_APPROVE','REFUND_REJECT','SHIFT_OPEN','SHIFT_CLOSE','CASH_MOVEMENT','STOCK_RECEIPT','STOCK_ADJUSTMENT','FISCAL_DAY_CLOSE','INVOICE_REPRINT','STOCK_COUNT_POST','STOCK_TRANSFER','SLIP_REPRINT'";

    public function up(): void
    {
        DB::statement('ALTER TABLE idempotency_records DROP CONSTRAINT idempotency_records_operation_type_check');
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_type_check CHECK (operation_type IN ('.self::EARLIER.",'DAILY_STOCK_ENTRY'))");
    }

    public function down(): void
    {
        DB::table('idempotency_records')->where('operation_type', 'DAILY_STOCK_ENTRY')->delete();
        DB::statement('ALTER TABLE idempotency_records DROP CONSTRAINT idempotency_records_operation_type_check');
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_records_operation_type_check CHECK (operation_type IN ('.self::EARLIER.'))');
    }
};
