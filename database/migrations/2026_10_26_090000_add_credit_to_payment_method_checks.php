<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-016 section 4 / slice 3.2: CREDIT becomes a tender (a sale charged to an employee) and a refund settlement (a refund of such a
// sale is credited back, never paid in cash). BANK_TRANSFER is deliberately NOT added here (slice 2.1 has not been built).
//
// Table kind: payments and refund_settlements are APPEND-ONLY (UPDATE revoked by database/scripts/harden_append_only_privileges.sql);
// this migration changes a CHECK constraint only, not a row, and the privileges are unchanged.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('CASH','GCASH','MAYA','CARD','OTHER','CREDIT'))");
        DB::statement('ALTER TABLE refund_settlements DROP CONSTRAINT refund_settlements_method_check');
        DB::statement("ALTER TABLE refund_settlements ADD CONSTRAINT refund_settlements_method_check CHECK (payment_method IN ('CASH','GCASH','MAYA','CARD','OTHER','CREDIT'))");
    }

    public function down(): void
    {
        DB::table('refund_settlements')->where('payment_method', 'CREDIT')->delete();
        DB::table('payments')->where('method', 'CREDIT')->delete();
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('CASH','GCASH','MAYA','CARD','OTHER'))");
        DB::statement('ALTER TABLE refund_settlements DROP CONSTRAINT refund_settlements_method_check');
        DB::statement("ALTER TABLE refund_settlements ADD CONSTRAINT refund_settlements_method_check CHECK (payment_method IN ('CASH','GCASH','MAYA','CARD','OTHER'))");
    }
};
