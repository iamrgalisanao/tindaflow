<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Automated schema/constraint validation tests, Stage 5 instruction §59.
// Promotes the scenarios manually verified via raw psql during Stage 5
// live validation (docs/04-database/schema-validation.md) into a
// repeatable, CI-runnable suite. Each test creates its own minimal
// fixture with DB::table()->insert() (no factories yet -- see
// docs/04-database/migration-plan.md §5) and asserts that PostgreSQL
// itself rejects the violating write, not that application code checks
// for it first.
class ConstraintValidationTest extends PostgresSchemaTestCase
{
    private string $storeId;

    private string $categoryId;

    private string $terminalId;

    private string $terminal2Id;

    private string $cashierId;

    private string $cashier2Id;

    private string $productId;

    private string $inventoryLocationId;

    private string $invoiceSeriesId;

    private string $fiscalInstallationId;

    private string $fiscalDayId;

    private string $shiftId;

    private string $saleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        $this->categoryId = (string) Str::uuid();
        $this->terminalId = (string) Str::uuid();
        $this->terminal2Id = (string) Str::uuid();
        $this->cashierId = (string) Str::uuid();
        $this->cashier2Id = (string) Str::uuid();
        $this->productId = (string) Str::uuid();
        $this->inventoryLocationId = (string) Str::uuid();
        $this->invoiceSeriesId = (string) Str::uuid();
        $this->fiscalInstallationId = (string) Str::uuid();
        $this->fiscalDayId = (string) Str::uuid();
        $this->shiftId = (string) Str::uuid();
        $this->saleId = (string) Str::uuid();

        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('users')->insert([
            ['id' => $this->cashierId, 'store_id' => $this->storeId, 'name' => 'Cashier One', 'email' => 'c1@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->cashier2Id, 'store_id' => $this->storeId, 'name' => 'Cashier Two', 'email' => 'c2@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('terminals')->insert([
            ['id' => $this->terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'T-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->terminal2Id, 'store_id' => $this->storeId, 'terminal_code' => 'T-02', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('categories')->insert(['id' => $this->categoryId, 'store_id' => $this->storeId, 'name' => 'Snacks', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'id' => $this->productId, 'store_id' => $this->storeId, 'sku' => 'SKU-001', 'barcode' => '4800000000017',
            'name' => 'Test Product', 'category_id' => $this->categoryId, 'unit_of_measure' => 'pc',
            'cost' => 10, 'selling_price' => 15, 'tax_class' => 'VATABLE', 'track_inventory' => true,
            'reorder_level' => 5, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventory_locations')->insert(['id' => $this->inventoryLocationId, 'store_id' => $this->storeId, 'name' => 'Main Floor', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('fiscal_installations')->insert([
            'id' => $this->fiscalInstallationId, 'store_id' => $this->storeId, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('invoice_series')->insert([
            'id' => $this->invoiceSeriesId, 'store_id' => $this->storeId, 'fiscal_installation_id' => $this->fiscalInstallationId,
            'series_code' => 'MAIN', 'prefix' => 'INV',
            'current_number' => 1, 'starting_number' => 1, 'ending_number' => null, 'status' => 'ACTIVE',
            'version' => 0, 'created_at' => now(), 'updated_at' => now(),
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

        DB::table('sales')->insert([
            'id' => $this->saleId, 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
            'fiscal_day_id' => $this->fiscalDayId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId,
            'transaction_number' => 'TXN-0001', 'sold_at' => now(), 'subtotal' => 15, 'grand_total' => 15,
            'taxable_sales' => 13.39, 'vat_amount' => 1.61, 'status' => 'COMPLETED', 'created_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'id' => (string) Str::uuid(), 'sale_id' => $this->saleId, 'product_id' => $this->productId,
            'line_number' => 1, 'product_name_snapshot' => 'Test Product', 'sku_snapshot' => 'SKU-001',
            'unit_of_measure_snapshot' => 'pc', 'quantity' => 1, 'unit_price_snapshot' => 15,
            'gross_line_amount' => 15, 'line_discount_amount' => 0, 'order_discount_eligible' => true,
            'allocated_order_discount_amount' => 0, 'net_line_amount' => 15,
            'tax_classification_snapshot' => 'VATABLE', 'tax_rate_snapshot' => 0.12,
            'taxable_base' => 13.39, 'tax_amount' => 1.61,
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

    public function test_duplicate_sku_rejected_scoped_per_store(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('products')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'sku' => 'SKU-001',
                'name' => 'Dup', 'category_id' => $this->categoryId, 'unit_of_measure' => 'pc',
                'selling_price' => 10, 'tax_class' => 'VATABLE', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'duplicate SKU within the same store should be rejected');
    }

    public function test_one_open_shift_per_terminal(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('shifts')->insert([
                'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'fiscal_day_id' => $this->fiscalDayId,
                'cashier_id' => $this->cashier2Id, 'opening_cash' => 500, 'opened_at' => now(), 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a second OPEN shift on the same terminal should be rejected');
    }

    public function test_one_open_shift_per_cashier(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('shifts')->insert([
                'id' => (string) Str::uuid(), 'terminal_id' => $this->terminal2Id, 'fiscal_day_id' => $this->fiscalDayId,
                'cashier_id' => $this->cashierId, 'opening_cash' => 500, 'opened_at' => now(), 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'the same cashier opening a second OPEN shift on another terminal should be rejected');
    }

    public function test_one_open_fiscal_day_per_terminal(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('fiscal_days')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
                'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a second OPEN fiscal_day on the same terminal should be rejected');
    }

    public function test_multiple_interim_x_readings_allowed(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('x_readings')->insert([
                ['id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId, 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(), 'generated_by' => $this->cashierId, 'is_closing_reading' => false, 'totals_snapshot' => '{}'],
                ['id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId, 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(), 'generated_by' => $this->cashierId, 'is_closing_reading' => false, 'totals_snapshot' => '{}'],
            ]);
        });

        $this->assertFalse($failed, 'multiple interim (non-closing) XReadings for the same shift should be allowed');
    }

    public function test_only_one_closing_x_reading_per_shift(): void
    {
        DB::table('x_readings')->insert([
            'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId,
            'cashier_id' => $this->cashierId, 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(),
            'generated_by' => $this->cashierId, 'is_closing_reading' => true, 'totals_snapshot' => '{}',
        ]);

        $failed = $this->attemptFails(function () {
            DB::table('x_readings')->insert([
                'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId,
                'cashier_id' => $this->cashierId, 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(),
                'generated_by' => $this->cashierId, 'is_closing_reading' => true, 'totals_snapshot' => '{}',
            ]);
        });

        $this->assertTrue($failed, 'a second closing XReading for the same shift should be rejected');
    }

    public function test_exactly_one_z_reading_per_fiscal_day(): void
    {
        DB::table('z_readings')->insert([
            'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'fiscal_day_id' => $this->fiscalDayId,
            'business_date' => now()->toDateString(), 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(),
            'generated_by' => $this->cashierId, 'z_counter' => 1, 'totals_snapshot' => '{}',
        ]);

        $failed = $this->attemptFails(function () {
            DB::table('z_readings')->insert([
                'id' => (string) Str::uuid(), 'terminal_id' => $this->terminalId, 'fiscal_day_id' => $this->fiscalDayId,
                'business_date' => now()->toDateString(), 'from_at' => now(), 'to_at' => now(), 'generated_at' => now(),
                'generated_by' => $this->cashierId, 'z_counter' => 2, 'totals_snapshot' => '{}',
            ]);
        });

        $this->assertTrue($failed, 'a second ZReading for the same fiscal_day should be rejected');
    }

    public function test_duplicate_invoice_serial_rejected_within_series(): void
    {
        DB::table('invoices')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'sale_id' => $this->saleId, 'invoice_series_id' => $this->invoiceSeriesId,
            'invoice_number' => '000001', 'issued_at' => now(), 'terminal_id' => $this->terminalId,
            'seller_registered_name_snapshot' => 'Test Store', 'tax_registration_type_snapshot' => 'VAT',
            'terminal_code_snapshot' => 'T-01', 'invoice_snapshot_json' => '{}',
        ]);

        $secondSaleId = (string) Str::uuid();
        DB::table('sales')->insert([
            'id' => $secondSaleId, 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
            'fiscal_day_id' => $this->fiscalDayId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId,
            'transaction_number' => 'TXN-0002', 'sold_at' => now(), 'subtotal' => 15, 'grand_total' => 15,
            'taxable_sales' => 13.39, 'vat_amount' => 1.61, 'status' => 'COMPLETED', 'created_at' => now(),
        ]);

        $failed = $this->attemptFails(function () use ($secondSaleId) {
            DB::table('invoices')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'sale_id' => $secondSaleId, 'invoice_series_id' => $this->invoiceSeriesId,
                'invoice_number' => '000001', 'issued_at' => now(), 'terminal_id' => $this->terminalId,
                'seller_registered_name_snapshot' => 'Test Store', 'tax_registration_type_snapshot' => 'VAT',
                'terminal_code_snapshot' => 'T-01', 'invoice_snapshot_json' => '{}',
            ]);
        });

        $this->assertTrue($failed, 'a duplicate invoice_number within the same invoice_series should be rejected');
    }

    /**
     * Corrected 2026-09-16 (owner review): this proves MAGNITUDE
     * overflow rejection (99999999999.99 has 11 integer digits, but
     * NUMERIC(12,2) allows at most 10), never confused with fractional
     * SCALE rejection -- PostgreSQL rounds excess fractional digits
     * rather than rejecting them; see PrecisionCoercionTest for that
     * distinct, separately-verified behavior. Renamed from
     * test_invalid_money_precision_rejected, which conflated the two.
     */
    public function test_money_magnitude_overflow_rejected(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('payments')->insert([
                'id' => (string) Str::uuid(), 'sale_id' => $this->saleId, 'method' => 'CASH',
                'amount' => 99999999999.99, 'recorded_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a Money value whose magnitude exceeds NUMERIC(12,2)\'s 10 integer digits should be rejected');
    }

    /**
     * Corrected 2026-09-16 (owner review): see the docblock on
     * test_money_magnitude_overflow_rejected above -- same distinction
     * applies here. Renamed from test_invalid_quantity_precision_rejected.
     */
    public function test_quantity_magnitude_overflow_rejected(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sale_items')->insert([
                'id' => (string) Str::uuid(), 'sale_id' => $this->saleId, 'product_id' => $this->productId,
                'line_number' => 2, 'product_name_snapshot' => 'Test Product', 'sku_snapshot' => 'SKU-001',
                'unit_of_measure_snapshot' => 'pc', 'quantity' => 99999999.999, 'unit_price_snapshot' => 15,
                'gross_line_amount' => 15, 'line_discount_amount' => 0, 'order_discount_eligible' => true,
                'allocated_order_discount_amount' => 0, 'net_line_amount' => 15,
                'tax_classification_snapshot' => 'VATABLE', 'tax_rate_snapshot' => 0.12,
                'taxable_base' => 13.39, 'tax_amount' => 1.61,
            ]);
        });

        $this->assertTrue($failed, 'a Quantity value whose magnitude exceeds NUMERIC(10,3)\'s 7 integer digits should be rejected');
    }

    public function test_fk_cross_resource_integrity(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sale_items')->insert([
                'id' => (string) Str::uuid(), 'sale_id' => $this->saleId, 'product_id' => (string) Str::uuid(),
                'line_number' => 3, 'product_name_snapshot' => 'Ghost', 'sku_snapshot' => 'SKU-GHOST',
                'unit_of_measure_snapshot' => 'pc', 'quantity' => 1, 'unit_price_snapshot' => 15,
                'gross_line_amount' => 15, 'line_discount_amount' => 0, 'order_discount_eligible' => true,
                'allocated_order_discount_amount' => 0, 'net_line_amount' => 15,
                'tax_classification_snapshot' => 'VATABLE', 'tax_rate_snapshot' => 0.12,
                'taxable_base' => 13.39, 'tax_amount' => 1.61,
            ]);
        });

        $this->assertTrue($failed, 'a sale_item referencing a nonexistent product should be rejected');
    }

    public function test_journal_duplicate_source_prevention(): void
    {
        DB::table('electronic_journal_entries')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
            'event_type' => 'INVOICE', 'source_type' => 'sale', 'source_id' => $this->saleId,
            'payload_json' => '{}', 'occurred_at' => now(),
        ]);

        $failed = $this->attemptFails(function () {
            DB::table('electronic_journal_entries')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
                'event_type' => 'INVOICE', 'source_type' => 'sale', 'source_id' => $this->saleId,
                'payload_json' => '{}', 'occurred_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a duplicate (source_type, source_id, event_type) journal entry should be rejected');
    }

    public function test_idempotency_duplicate_key_prevention(): void
    {
        DB::table('idempotency_records')->insert([
            'terminal_id' => $this->terminalId, 'idempotency_key' => (string) Str::uuid(),
            'operation_type' => 'CHECKOUT', 'request_hash' => str_repeat('a', 64),
            'status' => 'IN_PROGRESS', 'created_at' => now(),
        ]);

        $key = DB::table('idempotency_records')->where('terminal_id', $this->terminalId)->value('idempotency_key');

        $failed = $this->attemptFails(function () use ($key) {
            DB::table('idempotency_records')->insert([
                'terminal_id' => $this->terminalId, 'idempotency_key' => $key,
                'operation_type' => 'CHECKOUT', 'request_hash' => str_repeat('b', 64),
                'status' => 'IN_PROGRESS', 'created_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'a duplicate (terminal_id, idempotency_key) should be rejected');
    }

    public function test_status_check_constraint_rejects_invalid_value(): void
    {
        $failed = $this->attemptFails(function () {
            DB::table('sales')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->terminalId,
                'fiscal_day_id' => $this->fiscalDayId, 'shift_id' => $this->shiftId, 'cashier_id' => $this->cashierId,
                'transaction_number' => 'TXN-BAD', 'sold_at' => now(), 'subtotal' => 0, 'grand_total' => 0,
                'status' => 'DRAFT', 'created_at' => now(),
            ]);
        });

        $this->assertTrue($failed, 'an invalid sales.status value (e.g. DRAFT) should be rejected');
    }

    public function test_no_cascade_removal_of_financial_history(): void
    {
        $saleDeleteFailed = $this->attemptFails(function () {
            DB::table('sales')->where('id', $this->saleId)->delete();
        });
        $this->assertTrue($saleDeleteFailed, 'deleting a sale with sale_items/invoice children should be rejected (RESTRICT)');

        $productDeleteFailed = $this->attemptFails(function () {
            DB::table('products')->where('id', $this->productId)->delete();
        });
        $this->assertTrue($productDeleteFailed, 'deleting a product referenced by sale_items should be rejected (RESTRICT)');
    }
}
