<?php

namespace Tests\Database;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Owner hardening pass follow-up, 2026-09-16: live tests for the
// Invoice structural store-coherence fix (invoices.store_id +
// composite FKs to sales/invoice_series/terminals) and a
// reconfirmation of the pre-existing serial/series uniqueness rules
// now that invoices carries an extra column. Every INSERT is raw SQL
// via DB::table(), bypassing Eloquent and Laravel validation entirely.
class InvoiceContextIntegrityTest extends PostgresSchemaTestCase
{
    private string $storeA;

    private string $storeB;

    private string $terminalA;

    private string $terminalA2;

    private string $cashierA;

    private string $fiscalDayA;

    private string $shiftA;

    private string $invoiceSeriesA;

    private string $invoiceSeriesB;

    private string $fiscalInstallationA;

    private string $fiscalInstallationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeA = (string) Str::uuid();
        $this->storeB = (string) Str::uuid();
        $this->terminalA = (string) Str::uuid();
        $this->terminalA2 = (string) Str::uuid();
        $this->cashierA = (string) Str::uuid();
        $this->fiscalDayA = (string) Str::uuid();
        $this->shiftA = (string) Str::uuid();
        $this->invoiceSeriesA = (string) Str::uuid();
        $this->invoiceSeriesB = (string) Str::uuid();
        $this->fiscalInstallationA = (string) Str::uuid();
        $this->fiscalInstallationB = (string) Str::uuid();

        DB::table('stores')->insert([
            ['id' => $this->storeA, 'name' => 'Store A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->storeB, 'name' => 'Store B', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('users')->insert([
            'id' => $this->cashierA, 'store_id' => $this->storeA, 'name' => 'Cashier A', 'email' => 'ia@test.local',
            'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('terminals')->insert([
            ['id' => $this->terminalA, 'store_id' => $this->storeA, 'terminal_code' => 'IA-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->terminalA2, 'store_id' => $this->storeA, 'terminal_code' => 'IA-02', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('fiscal_days')->insert([
            'id' => $this->fiscalDayA, 'store_id' => $this->storeA, 'terminal_id' => $this->terminalA,
            'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('shifts')->insert([
            'id' => $this->shiftA, 'terminal_id' => $this->terminalA, 'fiscal_day_id' => $this->fiscalDayA,
            'cashier_id' => $this->cashierA, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fiscal_installations')->insert([
            ['id' => $this->fiscalInstallationA, 'store_id' => $this->storeA, 'deployment_model' => 'STANDALONE', 'software_version' => '1.0.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->fiscalInstallationB, 'store_id' => $this->storeB, 'deployment_model' => 'STANDALONE', 'software_version' => '1.0.0', 'installed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // invoiceSeriesA belongs to Store A; invoiceSeriesB belongs to
        // Store B -- used to construct the cross-store attempt.
        DB::table('invoice_series')->insert([
            ['id' => $this->invoiceSeriesA, 'store_id' => $this->storeA, 'fiscal_installation_id' => $this->fiscalInstallationA, 'series_code' => 'MAIN', 'prefix' => 'INV', 'current_number' => 1, 'starting_number' => 1, 'status' => 'ACTIVE', 'version' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->invoiceSeriesB, 'store_id' => $this->storeB, 'fiscal_installation_id' => $this->fiscalInstallationB, 'series_code' => 'MAIN', 'prefix' => 'INV', 'current_number' => 1, 'starting_number' => 1, 'status' => 'ACTIVE', 'version' => 0, 'created_at' => now(), 'updated_at' => now()],
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

    private function makeSaleOnStoreA(string $transactionNumber): string
    {
        $saleId = (string) Str::uuid();

        DB::table('sales')->insert([
            'id' => $saleId, 'store_id' => $this->storeA, 'terminal_id' => $this->terminalA,
            'fiscal_day_id' => $this->fiscalDayA, 'shift_id' => $this->shiftA, 'cashier_id' => $this->cashierA,
            'transaction_number' => $transactionNumber, 'sold_at' => now(), 'subtotal' => 15, 'grand_total' => 15,
            'taxable_sales' => 13.39, 'vat_amount' => 1.61, 'status' => 'COMPLETED', 'created_at' => now(),
        ]);

        return $saleId;
    }

    private function baseInvoiceAttrs(string $storeId, string $saleId, string $seriesId, string $invoiceNumber): array
    {
        return [
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'sale_id' => $saleId,
            'invoice_series_id' => $seriesId, 'invoice_number' => $invoiceNumber, 'issued_at' => now(),
            'terminal_id' => $this->terminalA, 'seller_registered_name_snapshot' => 'Store A',
            'tax_registration_type_snapshot' => 'VAT', 'terminal_code_snapshot' => 'IA-01',
            'invoice_snapshot_json' => '{}',
        ];
    }

    /** A. Store A Sale + Store B InvoiceSeries -> rejected. */
    public function test_invoice_rejects_store_a_sale_with_store_b_invoice_series(): void
    {
        $saleId = $this->makeSaleOnStoreA('TXN-A1');

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('invoices')->insert(
                $this->baseInvoiceAttrs($this->storeA, $saleId, $this->invoiceSeriesB, '000001')
            );
        });

        $this->assertTrue($failed, 'an invoice citing a Store A sale but a Store B invoice_series must be rejected');
    }

    /** B. Store A Sale + Store A InvoiceSeries -> accepted. */
    public function test_invoice_accepts_matching_store_sale_and_invoice_series(): void
    {
        $saleId = $this->makeSaleOnStoreA('TXN-A2');

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('invoices')->insert(
                $this->baseInvoiceAttrs($this->storeA, $saleId, $this->invoiceSeriesA, '000001')
            );
        });

        $this->assertFalse($failed, 'a fully coherent invoice (sale and invoice_series both Store A) must still succeed');
    }

    /** Store coherence must also catch a mismatched invoices.store_id itself (not just series/sale disagreeing with each other). */
    public function test_invoice_rejects_declared_store_not_matching_its_own_sale(): void
    {
        $saleId = $this->makeSaleOnStoreA('TXN-A3');

        $failed = $this->attemptFails(function () use ($saleId) {
            DB::table('invoices')->insert(
                // store_id claims Store B, but the sale and series are both Store A
                $this->baseInvoiceAttrs($this->storeB, $saleId, $this->invoiceSeriesA, '000002')
            );
        });

        $this->assertTrue($failed, 'an invoice whose own declared store_id disagrees with its sale\'s store must be rejected');
    }

    /** Bonus: invoice's own terminal_id must also belong to its declared store. */
    public function test_invoice_rejects_terminal_from_a_different_store(): void
    {
        $terminalB = (string) Str::uuid();
        DB::table('terminals')->insert(['id' => $terminalB, 'store_id' => $this->storeB, 'terminal_code' => 'IB-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $saleId = $this->makeSaleOnStoreA('TXN-A4');

        $failed = $this->attemptFails(function () use ($saleId, $terminalB) {
            $attrs = $this->baseInvoiceAttrs($this->storeA, $saleId, $this->invoiceSeriesA, '000003');
            $attrs['terminal_id'] = $terminalB;
            DB::table('invoices')->insert($attrs);
        });

        $this->assertTrue($failed, 'an invoice citing a Store B terminal while declaring Store A must be rejected');
    }

    /**
     * A. Sale: Store A / Terminal 01. Invoice: Store A / same Sale /
     * Terminal 02. Expected: DATABASE REJECTS -- both terminals
     * legitimately belong to Store A, so only an Invoice/Sale
     * terminal-identity check (not a store-level one) can catch this.
     */
    public function test_invoice_rejects_terminal_disagreeing_with_its_own_sale_same_store(): void
    {
        $saleId = $this->makeSaleOnStoreA('TXN-A8'); // finalized on terminalA (Terminal 01)

        $failed = $this->attemptFails(function () use ($saleId) {
            $attrs = $this->baseInvoiceAttrs($this->storeA, $saleId, $this->invoiceSeriesA, '000004');
            $attrs['terminal_id'] = $this->terminalA2; // Terminal 02, same Store A
            DB::table('invoices')->insert($attrs);
        });

        $this->assertTrue($failed, 'an invoice must not be able to declare a different terminal than the one that actually finalized its sale, even within the same store');
    }

    /**
     * B. Sale: Store A / Terminal 01. Invoice: Store A / same Sale /
     * Terminal 01. Expected: ACCEPTED.
     */
    public function test_invoice_accepts_terminal_matching_its_own_sale(): void
    {
        $saleId = $this->makeSaleOnStoreA('TXN-A9'); // finalized on terminalA (Terminal 01)

        $failed = $this->attemptFails(function () use ($saleId) {
            $attrs = $this->baseInvoiceAttrs($this->storeA, $saleId, $this->invoiceSeriesA, '000005');
            $attrs['terminal_id'] = $this->terminalA; // Terminal 01, matching the sale
            DB::table('invoices')->insert($attrs);
        });

        $this->assertFalse($failed, 'an invoice whose terminal matches the terminal that finalized its sale must be accepted');
    }

    /** C. Duplicate serial within same InvoiceSeries -> rejected (reconfirmed with the new store_id column present). */
    public function test_duplicate_serial_within_same_series_rejected(): void
    {
        $sale1 = $this->makeSaleOnStoreA('TXN-A5');
        $sale2 = $this->makeSaleOnStoreA('TXN-A6');

        DB::table('invoices')->insert($this->baseInvoiceAttrs($this->storeA, $sale1, $this->invoiceSeriesA, '000010'));

        $failed = $this->attemptFails(function () use ($sale2) {
            DB::table('invoices')->insert($this->baseInvoiceAttrs($this->storeA, $sale2, $this->invoiceSeriesA, '000010'));
        });

        $this->assertTrue($failed, 'a duplicate invoice_number within the same invoice_series must be rejected');
    }

    /** D. Same numeric serial in a different (legitimate) series -> permitted, per the frozen per-series uniqueness scope. */
    public function test_same_numeric_serial_in_different_series_permitted(): void
    {
        $terminalB = (string) Str::uuid();
        $cashierB = (string) Str::uuid();
        $fiscalDayB = (string) Str::uuid();
        $shiftB = (string) Str::uuid();
        $saleB = (string) Str::uuid();

        DB::table('terminals')->insert(['id' => $terminalB, 'store_id' => $this->storeB, 'terminal_code' => 'IB-02', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['id' => $cashierB, 'store_id' => $this->storeB, 'name' => 'Cashier B', 'email' => 'ib@test.local', 'password_hash' => 'x', 'role' => 'CASHIER', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fiscal_days')->insert(['id' => $fiscalDayB, 'store_id' => $this->storeB, 'terminal_id' => $terminalB, 'business_date' => now()->toDateString(), 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shifts')->insert(['id' => $shiftB, 'terminal_id' => $terminalB, 'fiscal_day_id' => $fiscalDayB, 'cashier_id' => $cashierB, 'opening_cash' => 1000, 'opened_at' => now(), 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales')->insert(['id' => $saleB, 'store_id' => $this->storeB, 'terminal_id' => $terminalB, 'fiscal_day_id' => $fiscalDayB, 'shift_id' => $shiftB, 'cashier_id' => $cashierB, 'transaction_number' => 'TXN-B1', 'sold_at' => now(), 'subtotal' => 15, 'grand_total' => 15, 'taxable_sales' => 13.39, 'vat_amount' => 1.61, 'status' => 'COMPLETED', 'created_at' => now()]);

        $saleA = $this->makeSaleOnStoreA('TXN-A7');
        DB::table('invoices')->insert($this->baseInvoiceAttrs($this->storeA, $saleA, $this->invoiceSeriesA, '000020'));

        $failed = $this->attemptFails(function () use ($saleB, $terminalB) {
            DB::table('invoices')->insert([
                'id' => (string) Str::uuid(), 'store_id' => $this->storeB, 'sale_id' => $saleB,
                'invoice_series_id' => $this->invoiceSeriesB, 'invoice_number' => '000020', 'issued_at' => now(),
                'terminal_id' => $terminalB, 'seller_registered_name_snapshot' => 'Store B',
                'tax_registration_type_snapshot' => 'VAT', 'terminal_code_snapshot' => 'IB-02',
                'invoice_snapshot_json' => '{}',
            ]);
        });

        $this->assertFalse($failed, 'the same numeric serial (000020) in a different, legitimate invoice_series must be permitted -- uniqueness is scoped per series, not globally');
    }
}
