<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Stage 5 amendment tests, 2026-09-17 -- proves the NON_VAT gap fix
// (database/migrations/2026_09_16_174605_add_non_vat_sales_to_sales_table.php,
// domain-model.md §4a, invariants.md TAX-NV-001..005) at the database
// layer: PostgreSQL itself enforces the mutual-exclusivity CHECK, not
// merely application code.
class NonVatSalesTest extends PostgresSchemaTestCase
{
    private string $storeId;

    private string $terminalId;

    private string $cashierId;

    private string $fiscalDayId;

    private string $shiftId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        $this->terminalId = (string) Str::uuid();
        $this->cashierId = (string) Str::uuid();
        $this->fiscalDayId = (string) Str::uuid();
        $this->shiftId = (string) Str::uuid();

        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('users')->insert([
            'id' => $this->cashierId, 'store_id' => $this->storeId, 'name' => 'Cashier One',
            'email' => 'nv-cashier@test.local', 'password_hash' => 'x', 'role' => 'CASHIER',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('terminals')->insert([
            'id' => $this->terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'T-NV-01',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fiscal_days')->insert([
            'id' => $this->fiscalDayId, 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
            'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('shifts')->insert([
            'id' => $this->shiftId, 'terminal_id' => $this->terminalId, 'fiscal_day_id' => $this->fiscalDayId,
            'cashier_id' => $this->cashierId, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Runs $callback in a nested transaction (SAVEPOINT) and returns whether it threw. */
    private function attemptFails(callable $callback): bool
    {
        try {
            DB::transaction($callback);

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    private function baseSaleRow(string $transactionNumber): array
    {
        return [
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
            'fiscal_day_id' => $this->fiscalDayId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId,
            'transaction_number' => $transactionNumber, 'sold_at' => now(), 'subtotal' => 250, 'grand_total' => 250,
            'status' => 'COMPLETED', 'created_at' => now(),
        ];
    }

    public function test_non_vat_sale_persists_with_grand_total_in_non_vat_sales_and_zero_vat_buckets(): void
    {
        $saleId = (string) Str::uuid();

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-0001'), [
                'id' => $saleId,
                'taxable_sales' => 0, 'vat_exempt_sales' => 0, 'zero_rated_sales' => 0, 'vat_amount' => 0,
                'non_vat_sales' => 250,
            ]));
        });

        $this->assertFalse($failed, 'a NON_VAT sale with grand_total = non_vat_sales and all VAT buckets zero should persist');

        $row = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame('250.00', $row->non_vat_sales);
        $this->assertSame('250.00', $row->grand_total);
        $this->assertSame('0.00', $row->taxable_sales);
        $this->assertSame('0.00', $row->vat_exempt_sales);
        $this->assertSame('0.00', $row->zero_rated_sales);
        $this->assertSame('0.00', $row->vat_amount);
    }

    public function test_vat_sale_persists_with_zero_non_vat_sales(): void
    {
        $saleId = (string) Str::uuid();

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-0002'), [
                'id' => $saleId,
                'taxable_sales' => 223.21, 'vat_exempt_sales' => 0, 'zero_rated_sales' => 0, 'vat_amount' => 26.79,
                'non_vat_sales' => 0,
            ]));
        });

        $this->assertFalse($failed, 'a VAT-registered sale with non_vat_sales = 0.00 should persist unaffected');

        $row = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame('0.00', $row->non_vat_sales);
        $this->assertSame('223.21', $row->taxable_sales);
        $this->assertSame('26.79', $row->vat_amount);
    }

    public function test_non_vat_sales_defaults_to_zero_when_omitted(): void
    {
        $saleId = (string) Str::uuid();

        DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-0003'), [
            'id' => $saleId,
            'taxable_sales' => 223.21, 'vat_amount' => 26.79,
        ]));

        $this->assertSame('0.00', DB::table('sales')->where('id', $saleId)->value('non_vat_sales'));
    }

    public function test_mixed_non_vat_and_vat_buckets_on_same_sale_rejected(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-BAD-1'), [
                'taxable_sales' => 100, 'vat_amount' => 12, 'non_vat_sales' => 138,
            ]));
        });

        $this->assertTrue($failed, 'a sale with both non_vat_sales > 0 and a VAT bucket > 0 should be rejected by sales_non_vat_vat_mutually_exclusive_check');
    }

    public function test_non_vat_sales_with_vat_exempt_bucket_rejected(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-BAD-2'), [
                'vat_exempt_sales' => 100, 'non_vat_sales' => 150,
            ]));
        });

        $this->assertTrue($failed, 'non_vat_sales > 0 alongside vat_exempt_sales > 0 should be rejected -- NON_VAT is never VAT_EXEMPT (TAX-NV-001)');
    }

    public function test_negative_non_vat_sales_rejected(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert(array_merge($this->baseSaleRow('TXN-NV-BAD-3'), [
                'non_vat_sales' => -1,
            ]));
        });

        $this->assertTrue($failed, 'a negative non_vat_sales value should be rejected by sales_non_vat_sales_nonneg_check');
    }
}
