<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Owner hardening pass, 2026-09-16: live negative tests proving the
// composite FK constraints added to close DB-INV-063 actually reject
// every named invalid store/terminal context combination -- not that
// Laravel throws before issuing the INSERT, but that PostgreSQL itself
// rejects the write. Every fixture row here is built with raw
// DB::table()->insert(), bypassing Eloquent entirely.
class ContextIntegrityTest extends PostgresSchemaTestCase
{
    private string $storeA;

    private string $storeB;

    private string $terminalA1;

    private string $terminalA2;

    private string $terminalB1;

    private string $cashierA;

    private string $cashierA2;

    private string $cashierB;

    private string $fiscalDayA1;

    private string $fiscalDayA2;

    private string $fiscalDayB1;

    private string $shiftA1;

    private string $shiftA2;

    private string $shiftB1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = (string) Str::uuid();
        $this->storeB = (string) Str::uuid();
        $this->terminalA1 = (string) Str::uuid();
        $this->terminalA2 = (string) Str::uuid();
        $this->terminalB1 = (string) Str::uuid();
        $this->cashierA = (string) Str::uuid();
        $this->cashierA2 = (string) Str::uuid();
        $this->cashierB = (string) Str::uuid();
        $this->fiscalDayA1 = (string) Str::uuid();
        $this->fiscalDayA2 = (string) Str::uuid();
        $this->fiscalDayB1 = (string) Str::uuid();
        $this->shiftA1 = (string) Str::uuid();
        $this->shiftA2 = (string) Str::uuid();
        $this->shiftB1 = (string) Str::uuid();

        DB::table('stores')->insert([
            ['id' => $this->storeA, 'name' => 'Store A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->storeB, 'name' => 'Store B', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('users')->insert([
            ['id' => $this->cashierA, 'store_id' => $this->storeA, 'name' => 'Cashier A', 'email' => 'a@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->cashierA2, 'store_id' => $this->storeA, 'name' => 'Cashier A2', 'email' => 'a2@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->cashierB, 'store_id' => $this->storeB, 'name' => 'Cashier B', 'email' => 'b@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('terminals')->insert([
            ['id' => $this->terminalA1, 'store_id' => $this->storeA, 'terminal_code' => 'A-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->terminalA2, 'store_id' => $this->storeA, 'terminal_code' => 'A-02', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->terminalB1, 'store_id' => $this->storeB, 'terminal_code' => 'B-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('fiscal_days')->insert([
            ['id' => $this->fiscalDayA1, 'store_id' => $this->storeA, 'terminal_id' => $this->terminalA1, 'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->fiscalDayA2, 'store_id' => $this->storeA, 'terminal_id' => $this->terminalA2, 'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->fiscalDayB1, 'store_id' => $this->storeB, 'terminal_id' => $this->terminalB1, 'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('shifts')->insert([
            ['id' => $this->shiftA1, 'terminal_id' => $this->terminalA1, 'fiscal_day_id' => $this->fiscalDayA1, 'cashier_id' => $this->cashierA, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->shiftA2, 'terminal_id' => $this->terminalA2, 'fiscal_day_id' => $this->fiscalDayA2, 'cashier_id' => $this->cashierA2, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->shiftB1, 'terminal_id' => $this->terminalB1, 'fiscal_day_id' => $this->fiscalDayB1, 'cashier_id' => $this->cashierB, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function attemptFails(callable $callback): bool
    {
        try {
            DB::transaction($callback);

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    private function baseSaleAttrs(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'store_id' => $this->storeA,
            'terminal_id' => $this->terminalA1,
            'fiscal_day_id' => $this->fiscalDayA1,
            'shift_id' => $this->shiftA1,
            'cashier_id' => $this->cashierA,
            'transaction_number' => 'TXN-'.Str::random(8),
            'sold_at' => now(),
            'subtotal' => 15, 'grand_total' => 15,
            'status' => 'COMPLETED', 'created_at' => now(),
        ];
    }

    public function test_sale_rejects_store_a_terminal_with_store_b_shift(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleAttrs(), [
                'shift_id' => $this->shiftB1,
            ]));
        });

        $this->assertTrue($failed, 'a Store A sale must not be able to cite a Store B shift');
    }

    public function test_sale_rejects_store_a_terminal_with_store_b_fiscal_day(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleAttrs(), [
                'fiscal_day_id' => $this->fiscalDayB1,
            ]));
        });

        $this->assertTrue($failed, 'a Store A sale must not be able to cite a Store B fiscal_day');
    }

    public function test_sale_rejects_terminal_a_with_terminal_b_shift_same_store(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleAttrs(), [
                'shift_id' => $this->shiftA2, // same store (A), different terminal
            ]));
        });

        $this->assertTrue($failed, 'a Terminal A sale must not be able to cite a Terminal A2 shift even within the same store');
    }

    public function test_sale_rejects_terminal_a_with_terminal_b_fiscal_day_same_store(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleAttrs(), [
                'fiscal_day_id' => $this->fiscalDayA2, // same store (A), different terminal
            ]));
        });

        $this->assertTrue($failed, 'a Terminal A sale must not be able to cite a Terminal A2 fiscal_day even within the same store');
    }

    public function test_refund_processing_context_rejects_mismatched_terminal_and_shift(): void
    {
        DB::table('sales')->insert($this->baseSaleAttrs());
        $saleId = DB::table('sales')->where('terminal_id', $this->terminalA1)->value('id');

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('refunds')->insert([
                'id' => (string) Str::uuid(), 'sale_id' => $saleId, 'requested_by' => $this->cashierA,
                'reason' => 'test', 'status' => 'COMPLETED', 'approved_by' => $this->cashierA,
                'requested_at' => now(), 'resolved_at' => now(), 'refunded_at' => now(),
                'refund_total' => 10,
                'terminal_id' => $this->terminalA1, // processing Terminal A
                'shift_id' => $this->shiftB1,       // but citing Terminal B's shift
                'fiscal_day_id' => $this->fiscalDayA1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a Refund processing at Terminal A must not be able to cite Terminal B\'s shift');
    }

    public function test_void_processing_context_rejects_mismatched_terminal_and_fiscal_day(): void
    {
        DB::table('sales')->insert($this->baseSaleAttrs());
        $saleId = DB::table('sales')->where('terminal_id', $this->terminalA1)->value('id');

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('voids')->insert([
                'id' => (string) Str::uuid(), 'sale_id' => $saleId, 'requested_by' => $this->cashierA,
                'reason' => 'test', 'status' => 'VOIDED', 'approved_by' => $this->cashierA,
                'requested_at' => now(), 'resolved_at' => now(),
                'terminal_id' => $this->terminalA1,   // processing Terminal A
                'fiscal_day_id' => $this->fiscalDayB1, // but citing Terminal B's fiscal_day
                'shift_id' => $this->shiftA1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a Void processing at Terminal A must not be able to cite Terminal B\'s fiscal_day');
    }

    public function test_coherent_sale_still_succeeds(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert($this->baseSaleAttrs());
        });

        $this->assertFalse($failed, 'a fully coherent sale (matching store/terminal/shift/fiscal_day) must still succeed');
    }

    public function test_shift_rejects_fiscal_day_from_a_different_terminal(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('shifts')->insert([
                'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalA1,
                'fiscal_day_id' => $this->fiscalDayA2, // belongs to terminal A2, not A1
                'cashier_id' => $this->cashierB, 'opening_cash' => 500, 'opened_at' => now(),
                'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a shift on Terminal A1 must not be able to cite Terminal A2\'s fiscal_day');
    }

    public function test_terminal_fiscal_installation_rejects_cross_store_terminal(): void
    {
        $installationId = (string) Str::uuid();
        DB::table('fiscal_installations')->insert([
            'id' => $installationId, 'store_id' => $this->storeA, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $failed = $this->attemptFails(function () use ($installationId) {
            DB::table('terminal_fiscal_installations')->insert([
                'id' => (string) Str::uuid(),
                'store_id' => $this->storeB, // installation belongs to Store A, not B
                'terminal_id' => $this->terminalB1,
                'fiscal_installation_id' => $installationId,
                'effective_from' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a Store B terminal must not be able to associate with a Store A fiscal_installation');
    }
}
