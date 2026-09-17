<?php

namespace Tests\Database;

use App\Domain\Exceptions\InvoiceSeriesExhaustedException;
use App\Domain\Exceptions\InvoiceSeriesResolutionException;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use App\Support\GlobalLockOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stage 6B -- proves InvoiceSeriesAllocator against real PostgreSQL 17
 * for every scenario that doesn't require genuinely independent
 * connections/processes (see InvoiceSeriesAllocatorConcurrencyTest for
 * the true multi-process cases).
 *
 * Owner review, 2026-09-17 (second pass): rewritten for two amendments --
 * (1) a fresh series bootstraps at `starting_number - 1`, not
 * `starting_number`, so the first real allocation is never skipped; (2)
 * `invoice_series` resolution is keyed by `(store_id,
 * fiscal_installation_id)`, not `store_id` alone, since a store may
 * legitimately have more than one `invoice_series` (DB-INV-018).
 */
class InvoiceSeriesAllocatorTest extends PostgresSchemaTestCase
{
    private string $storeId;

    private string $fiscalInstallationId;

    private InvoiceSeriesAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeId = (string) Str::uuid();
        DB::table('stores')->insert(['id' => $this->storeId, 'name' => 'Test Store', 'created_at' => now(), 'updated_at' => now()]);

        $this->fiscalInstallationId = $this->insertFiscalInstallation($this->storeId);

        $this->allocator = new InvoiceSeriesAllocator;
    }

    private function insertFiscalInstallation(string $storeId): string
    {
        $id = (string) Str::uuid();

        DB::table('fiscal_installations')->insert([
            'id' => $id, 'store_id' => $storeId, 'deployment_model' => 'STANDALONE',
            'software_version' => '1.0.0', 'installed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertSeries(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('invoice_series')->insert(array_merge([
            'id' => $id,
            'store_id' => $this->storeId,
            'fiscal_installation_id' => $this->fiscalInstallationId,
            'series_code' => 'MAIN',
            'prefix' => 'INV',
            // Counter semantics (invariants.md INVSERIES-001):
            // current_number is the LAST allocated serial. A fresh
            // series bootstraps at starting_number - 1 so its first
            // real allocation yields starting_number exactly.
            'current_number' => 0,
            'starting_number' => 1,
            'ending_number' => null,
            'status' => 'ACTIVE',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_fresh_series_first_allocation_is_starting_number_exactly(): void
    {
        // starting_number = 1, current_number = 0 (bootstrap).
        $seriesId = $this->insertSeries();

        $first = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame($seriesId, $first->invoiceSeriesId);
        $this->assertSame(1, $first->serial);
        $this->assertSame('000001', $first->formattedNumber, 'the configured starting_number must never be skipped');

        $second = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(2, $second->serial);
        $this->assertSame('000002', $second->formattedNumber);

        $this->assertSame(2, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));
    }

    public function test_fresh_series_with_non_one_starting_number_starts_exactly_there(): void
    {
        // starting_number = 100, current_number = 99 (bootstrap).
        $seriesId = $this->insertSeries(['starting_number' => 100, 'current_number' => 99]);

        $first = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(100, $first->serial);
        $this->assertSame('000100', $first->formattedNumber);

        $second = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(101, $second->serial);
        $this->assertSame('000101', $second->formattedNumber);

        $this->assertSame(101, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));
    }

    public function test_sequential_allocation_from_a_partially_used_series(): void
    {
        $seriesId = $this->insertSeries(['current_number' => 102]); // 102 already issued

        $first = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(103, $first->serial);
        $this->assertSame('000103', $first->formattedNumber);

        $second = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(104, $second->serial);

        $this->assertSame(104, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));
    }

    public function test_rollback_does_not_consume_serial(): void
    {
        $seriesId = $this->insertSeries(['current_number' => 5]);

        $failed = false;
        try {
            DB::transaction(function () {
                $allocated = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
                $this->assertSame(6, $allocated->serial);

                throw new \RuntimeException('Simulated business-step failure after allocation, before commit.');
            });
        } catch (\RuntimeException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'the simulated failure must have propagated');

        // current_number must be exactly as it was before the failed
        // attempt -- invariants.md #14, "no gaps from failed attempts".
        $this->assertSame(5, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));

        // The NEXT successful allocation must receive 6, not 7 -- the
        // failed attempt's serial was never actually consumed.
        $next = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(6, $next->serial);
    }

    public function test_rollback_of_fresh_series_first_allocation_still_yields_starting_number(): void
    {
        $seriesId = $this->insertSeries(['starting_number' => 100, 'current_number' => 99]);

        $failed = false;
        try {
            DB::transaction(function () {
                $allocated = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
                $this->assertSame(100, $allocated->serial);

                throw new \RuntimeException('Simulated failure.');
            });
        } catch (\RuntimeException) {
            $failed = true;
        }

        $this->assertTrue($failed);
        $this->assertSame(99, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));

        $next = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(100, $next->serial, 'the rolled-back attempt must not have burned serial 100');
        $this->assertSame('000100', $next->formattedNumber);
    }

    public function test_no_active_series_is_rejected(): void
    {
        // No invoice_series row at all for this fiscal installation.
        $this->expectException(InvoiceSeriesResolutionException::class);
        $this->expectExceptionMessageMatches('/no ACTIVE invoice_series/');

        $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
    }

    public function test_only_closed_series_is_rejected_as_no_active_series(): void
    {
        $this->insertSeries(['status' => 'CLOSED']);

        $this->expectException(InvoiceSeriesResolutionException::class);
        $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
    }

    public function test_closed_series_is_ignored_when_an_active_one_also_exists(): void
    {
        $this->insertSeries(['series_code' => 'OLD', 'status' => 'CLOSED', 'current_number' => 999]);
        $activeId = $this->insertSeries(['series_code' => 'MAIN', 'status' => 'ACTIVE', 'current_number' => 10]);

        $allocated = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);

        $this->assertSame($activeId, $allocated->invoiceSeriesId);
        $this->assertSame(11, $allocated->serial);
    }

    public function test_two_active_series_for_the_same_fiscal_installation_is_rejected_by_the_database(): void
    {
        // invariants.md INVSERIES-003 / invoice_series_one_active_per_installation:
        // this is now a DATABASE constraint, not merely an application guard.
        $this->insertSeries(['series_code' => 'MAIN']);

        $failed = $this->attemptFails(function () {
            $this->insertSeries(['series_code' => 'ALT']);
        });

        $this->assertTrue($failed, 'a second simultaneously-ACTIVE invoice_series for the same fiscal_installation must be rejected at the database level');
    }

    public function test_two_active_series_for_different_fiscal_installations_of_the_same_store_are_both_permitted(): void
    {
        // DB-INV-018: a store may legitimately have more than one
        // invoice_series -- this must not raise
        // InvoiceSeriesResolutionException merely because the STORE has
        // two active series, as long as each belongs to a different
        // fiscal_installation.
        $installationB = $this->insertFiscalInstallation($this->storeId);

        $seriesA = $this->insertSeries(['series_code' => 'A']);
        $seriesB = $this->insertSeries(['series_code' => 'B', 'fiscal_installation_id' => $installationB, 'current_number' => 50]);

        $allocatedA = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $allocatedB = $this->allocator->allocateForFiscalInstallation($this->storeId, $installationB, new GlobalLockOrder);

        $this->assertSame($seriesA, $allocatedA->invoiceSeriesId);
        $this->assertSame(1, $allocatedA->serial);

        $this->assertSame($seriesB, $allocatedB->invoiceSeriesId);
        $this->assertSame(51, $allocatedB->serial);
    }

    public function test_allocating_the_final_valid_serial_succeeds(): void
    {
        $seriesId = $this->insertSeries(['current_number' => 97, 'ending_number' => 99]);

        $allocated = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(98, $allocated->serial);

        $final = $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
        $this->assertSame(99, $final->serial, 'allocating the ending_number itself must succeed');

        $this->assertSame(99, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'));
    }

    public function test_allocation_at_ending_number_is_exhausted(): void
    {
        $seriesId = $this->insertSeries(['current_number' => 100, 'ending_number' => 100]);

        try {
            $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);
            $this->fail('expected InvoiceSeriesExhaustedException was not thrown');
        } catch (InvoiceSeriesExhaustedException) {
            // expected
        }

        $this->assertSame(100, (int) DB::table('invoice_series')->where('id', $seriesId)->value('current_number'), 'an exhausted allocation must never advance the counter');
    }

    /**
     * Owner hardening pass, 2026-09-17: proves the new
     * invoice_series_ending_number_range_check directly -- without it,
     * a backwards/zero-length range (starting_number=100, ending_number=99)
     * could only ever be observed by the allocator as "already
     * exhausted," rather than being rejected outright as invalid
     * configuration at the point it is created.
     */
    public function test_database_check_constraint_rejects_ending_number_below_starting_number(): void
    {
        $failed = $this->attemptFails(function () {
            $this->insertSeries(['starting_number' => 100, 'current_number' => 99, 'ending_number' => 99]);
        });

        $this->assertTrue($failed, 'ending_number must never be less than starting_number');
    }

    public function test_ending_number_equal_to_starting_number_is_a_valid_single_serial_range(): void
    {
        $failed = $this->attemptFails(function () {
            $this->insertSeries(['starting_number' => 100, 'current_number' => 99, 'ending_number' => 100]);
        });

        $this->assertFalse($failed, 'ending_number == starting_number is a valid, single-serial range, not a backwards one');
    }

    public function test_bootstrap_current_number_at_starting_number_minus_one_with_non_one_starting_number_is_accepted(): void
    {
        $failed = $this->attemptFails(function () {
            $this->insertSeries(['starting_number' => 100, 'current_number' => 99]);
        });

        $this->assertFalse($failed, 'current_number = starting_number - 1 is the valid bootstrap state for any starting_number');
    }

    public function test_current_number_below_starting_number_minus_one_is_rejected_for_a_non_one_starting_number(): void
    {
        $failed = $this->attemptFails(function () {
            $this->insertSeries(['starting_number' => 100, 'current_number' => 98]);
        });

        $this->assertTrue($failed, 'current_number may never fall below starting_number - 1, even for a non-1 starting_number');
    }

    public function test_database_check_constraint_rejects_a_raw_bypass_below_bootstrap_floor(): void
    {
        // Proves the amended CHECK (current_number >= starting_number - 1)
        // itself, independent of application logic.
        $seriesId = $this->insertSeries(['starting_number' => 5, 'current_number' => 4]);

        $failed = $this->attemptFails(function () use ($seriesId) {
            DB::table('invoice_series')->where('id', $seriesId)->update(['current_number' => 3]);
        });

        $this->assertTrue($failed, 'current_number may never fall below starting_number - 1');
    }

    public function test_database_check_constraint_rejects_starting_number_below_one(): void
    {
        $failed = $this->attemptFails(function () {
            $this->insertSeries(['starting_number' => 0, 'current_number' => -1]);
        });

        $this->assertTrue($failed, 'starting_number must always be >= 1 -- zero is never an issuable serial');
    }

    public function test_database_check_constraint_rejects_a_raw_bypass_of_ending_number(): void
    {
        $seriesId = $this->insertSeries(['current_number' => 99, 'ending_number' => 100]);

        $failed = $this->attemptFails(function () use ($seriesId) {
            DB::table('invoice_series')->where('id', $seriesId)->update(['current_number' => 101]);
        });

        $this->assertTrue($failed, 'PostgreSQL must reject current_number exceeding ending_number even via a direct write');
    }

    public function test_database_uniqueness_rejects_duplicate_invoice_number_within_same_series(): void
    {
        $seriesId = $this->insertSeries();
        [$saleId1, $terminalId, $fiscalDayId, $shiftId, $cashierId] = $this->createMinimalSale($seriesId, 'TXN-A');

        DB::table('invoices')->insert($this->invoiceRow($seriesId, $saleId1, $terminalId, '000001'));

        $saleId2 = (string) Str::uuid();
        DB::table('sales')->insert($this->saleRow($saleId2, $terminalId, $fiscalDayId, $shiftId, $cashierId, 'TXN-B'));

        $failed = $this->attemptFails(function () use ($seriesId, $saleId2, $terminalId) {
            DB::table('invoices')->insert($this->invoiceRow($seriesId, $saleId2, $terminalId, '000001'));
        });

        $this->assertTrue($failed, 'a duplicate (invoice_series_id, invoice_number) must be rejected');
    }

    public function test_same_numeric_invoice_number_across_different_series_is_permitted(): void
    {
        $installationB = $this->insertFiscalInstallation($this->storeId);
        $seriesId1 = $this->insertSeries(['series_code' => 'A']);
        $seriesId2 = $this->insertSeries(['series_code' => 'B', 'fiscal_installation_id' => $installationB]);
        [$saleId1, $terminalId, $fiscalDayId, $shiftId, $cashierId] = $this->createMinimalSale($seriesId1, 'TXN-C');

        DB::table('invoices')->insert($this->invoiceRow($seriesId1, $saleId1, $terminalId, '000001'));

        $saleId2 = (string) Str::uuid();
        DB::table('sales')->insert($this->saleRow($saleId2, $terminalId, $fiscalDayId, $shiftId, $cashierId, 'TXN-D'));

        $failed = $this->attemptFails(function () use ($seriesId2, $saleId2, $terminalId) {
            DB::table('invoices')->insert($this->invoiceRow($seriesId2, $saleId2, $terminalId, '000001'));
        });

        $this->assertFalse($failed, 'the SAME formatted number in two DIFFERENT series must be permitted -- uniqueness is per-series, not global');
    }

    public function test_allocation_advances_version_column(): void
    {
        $seriesId = $this->insertSeries(['version' => 0]);

        $this->allocator->allocateForFiscalInstallation($this->storeId, $this->fiscalInstallationId, new GlobalLockOrder);

        $this->assertSame(1, (int) DB::table('invoice_series')->where('id', $seriesId)->value('version'));
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

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string} [saleId, terminalId, fiscalDayId, shiftId, cashierId] */
    private function createMinimalSale(string $seriesId, string $transactionNumber): array
    {
        $terminalId = (string) Str::uuid();
        $cashierId = (string) Str::uuid();
        $fiscalDayId = (string) Str::uuid();
        $shiftId = (string) Str::uuid();
        $saleId = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $cashierId, 'store_id' => $this->storeId, 'name' => 'Cashier',
            'email' => Str::uuid().'@test.local', 'password_hash' => 'x', 'role' => 'CASHIER',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('terminals')->insert([
            'id' => $terminalId, 'store_id' => $this->storeId, 'terminal_code' => 'T-'.Str::random(6),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fiscal_days')->insert([
            'id' => $fiscalDayId, 'store_id' => $this->storeId, 'terminal_id' => $terminalId,
            'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shifts')->insert([
            'id' => $shiftId, 'terminal_id' => $terminalId, 'fiscal_day_id' => $fiscalDayId,
            'cashier_id' => $cashierId, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales')->insert($this->saleRow($saleId, $terminalId, $fiscalDayId, $shiftId, $cashierId, $transactionNumber));

        return [$saleId, $terminalId, $fiscalDayId, $shiftId, $cashierId];
    }

    /** @return array<string, mixed> */
    private function saleRow(string $saleId, string $terminalId, string $fiscalDayId, string $shiftId, string $cashierId, string $transactionNumber): array
    {
        return [
            'id' => $saleId, 'store_id' => $this->storeId, 'terminal_id' => $terminalId,
            'fiscal_day_id' => $fiscalDayId, 'shift_id' => $shiftId, 'cashier_id' => $cashierId,
            'transaction_number' => $transactionNumber, 'sold_at' => now(), 'subtotal' => 100, 'grand_total' => 100,
            'taxable_sales' => 89.29, 'vat_amount' => 10.71, 'status' => 'COMPLETED', 'created_at' => now(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoiceRow(string $seriesId, string $saleId, string $terminalId, string $invoiceNumber): array
    {
        return [
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'sale_id' => $saleId,
            'invoice_series_id' => $seriesId, 'invoice_number' => $invoiceNumber, 'issued_at' => now(),
            'terminal_id' => $terminalId, 'seller_registered_name_snapshot' => 'Test Store',
            'tax_registration_type_snapshot' => 'VAT', 'terminal_code_snapshot' => 'T-01',
            'invoice_snapshot_json' => '{}', 'created_at' => now(),
        ];
    }
}
