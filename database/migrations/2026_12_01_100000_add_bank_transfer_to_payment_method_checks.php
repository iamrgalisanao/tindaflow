<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ADR-016 section 4 / slice 2.1: BANK_TRANSFER becomes a tender and a refund settlement. It is an ordinary non-cash method: the money
// never enters the drawer, and TindaFlow does not check that it arrived.
//
// Table kind: payments and refund_settlements are APPEND-ONLY (UPDATE revoked by database/scripts/harden_append_only_privileges.sql);
// this migration changes a CHECK constraint only, not a row, and the privileges are unchanged. Rolling it back is refused once a bank
// transfer has been recorded: those rows are sales records and are never deleted to restore the old rule.
return new class extends Migration
{
    public function up(): void
    {
        $this->constrain("'CASH','GCASH','MAYA','BANK_TRANSFER','CARD','OTHER','CREDIT'");
    }

    public function down(): void
    {
        if (DB::table('payments')->where('method', 'BANK_TRANSFER')->exists() || DB::table('refund_settlements')->where('payment_method', 'BANK_TRANSFER')->exists()) {
            throw new RuntimeException('Bank transfers have been recorded, so this migration cannot be rolled back. Restore a backup instead.');
        }

        $this->constrain("'CASH','GCASH','MAYA','CARD','OTHER','CREDIT'");
    }

    private function constrain(string $methods): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ({$methods}))");
        DB::statement('ALTER TABLE refund_settlements DROP CONSTRAINT refund_settlements_method_check');
        DB::statement("ALTER TABLE refund_settlements ADD CONSTRAINT refund_settlements_method_check CHECK (payment_method IN ({$methods}))");
    }
};
