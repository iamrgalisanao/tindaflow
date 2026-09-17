<?php

namespace Tests\Database;

use App\Domain\Exceptions\FiscalDayNotOpenException;
use App\Domain\Exceptions\InsufficientPaymentException;
use App\Domain\Exceptions\InvalidPaymentTotalException;
use App\Domain\Exceptions\InvoiceSeriesExhaustedException;
use App\Domain\Exceptions\ShiftRequiredException;
use App\Domain\Financial\FinancialCalculator;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Stage 6C: exercises CheckoutService end to end (ADR-003's full
// transaction script) against real PostgreSQL, reusing exactly the
// already-approved Stage 6A/6B components -- see
// docs/06-backend/stage-6c-sale-finalization.md for the acceptance
// criteria this test class proves.
class CheckoutServiceTest extends PostgresSchemaTestCase
{
    private CheckoutService $checkoutService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutService = new CheckoutService(
            new IdempotencyService,
            new CanonicalRequestHasher,
            new FinancialCalculator,
            new FiscalInstallationResolver,
            new InventoryLocationResolver,
            new TaxRegistrationResolver,
            new InvoiceSeriesAllocator,
        );
    }

    /** @return array{shift: Shift, product: Product} */
    private function readyToCheckout(): array
    {
        $shift = Shift::factory()->create();
        $storeId = $shift->fiscalDay->store_id;
        $terminalId = $shift->terminal_id;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
        InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);

        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'fiscal_installation_id' => $fiscalInstallation->id,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);

        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(),
            'store_id' => $storeId,
            'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(),
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00', 'cost' => '60.00']);

        return ['shift' => $shift, 'product' => $product];
    }

    public function test_successful_checkout_writes_every_required_row_in_one_transaction(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $idempotencyKey = (string) Str::uuid();

        $sale = $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            $idempotencyKey,
            [
                'items' => [['product_id' => $product->id, 'quantity' => '2']],
                'payments' => [['method' => 'CASH', 'amount' => '200.00']],
            ]
        );

        // --- sale ---------------------------------------------------
        $this->assertSame('COMPLETED', $sale->status);
        $this->assertSame($shift->terminal_id, $sale->terminal_id);
        $this->assertSame($shift->cashier_id, $sale->cashier_id);
        $this->assertSame($shift->id, $sale->shift_id);
        $this->assertSame($shift->fiscal_day_id, $sale->fiscal_day_id);
        $this->assertSame($idempotencyKey, $sale->idempotency_key);
        $this->assertNotEmpty($sale->transaction_number);
        // unit_price is VAT-inclusive (TaxCalculator::decompose divides
        // it out of the total rather than adding on top), so 2 x 100.00
        // stays 200.00 -- the VAT is a breakdown of that total, not an
        // addition to it.
        $this->assertSame('200.00', $sale->grand_total);
        $this->assertSame('200.00', $sale->subtotal);
        $this->assertSame('0.00', $sale->order_level_discount_amount);
        $this->assertSame('0.00', $sale->non_vat_sales);
        $this->assertSame('178.57', $sale->taxable_sales);
        $this->assertSame('21.43', $sale->vat_amount);
        $this->assertSame('0.00', $sale->vat_exempt_sales);
        $this->assertSame('0.00', $sale->zero_rated_sales);

        // --- sale_item ------------------------------------------------
        $item = $sale->items()->sole();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame($product->name, $item->product_name_snapshot);
        $this->assertSame($product->sku, $item->sku_snapshot);
        $this->assertSame(1, $item->line_number);
        $this->assertSame('2.000', $item->quantity);
        $this->assertSame('100.00', $item->unit_price_snapshot);
        $this->assertSame('200.00', $item->gross_line_amount);
        $this->assertSame('0.00', $item->line_discount_amount);
        $this->assertSame('0.00', $item->allocated_order_discount_amount);
        $this->assertSame('200.00', $item->net_line_amount);
        $this->assertSame('VATABLE', $item->tax_classification_snapshot);
        $this->assertSame('0.1200', $item->tax_rate_snapshot);
        $this->assertSame('178.57', $item->taxable_base);
        $this->assertSame('21.43', $item->tax_amount);
        $this->assertSame('60.00', $item->unit_cost_snapshot);

        // --- payment ----------------------------------------------------
        $payment = $sale->payments()->sole();
        $this->assertSame('CASH', $payment->method);
        $this->assertSame('200.00', $payment->amount);

        // --- invoice ------------------------------------------------
        $invoice = $sale->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame($sale->id, $invoice->sale_id);
        $this->assertMatchesRegularExpression('/^\d{6,}$/', $invoice->invoice_number);
        $this->assertSame($shift->terminal_id, $invoice->terminal_id);
        $series = InvoiceSeries::findOrFail($invoice->invoice_series_id);
        $this->assertSame($series->store_id, $sale->store_id);
        $this->assertSame('VAT', $invoice->tax_registration_type_snapshot);
        $this->assertSame(1, $invoice->invoice_snapshot_json['schema_version']);
        $this->assertSame($invoice->invoice_number, $invoice->invoice_snapshot_json['invoice_number']);

        // --- stock_movements: location, product, reference, and the
        // direction convention (movement_type = 'SALE' means an outbound
        // deduction; the stored quantity itself is always positive -- the
        // frozen migration's own comment: "direction implied by
        // movement_type... the ledger's sign convention lives in
        // application logic (Stage 6), not a negative column value"). ---
        $movement = DB::table('stock_movements')->where('reference_type', 'sale_item')->sole();
        $this->assertSame('SALE', $movement->movement_type);
        $this->assertSame($product->id, $movement->product_id);
        $this->assertSame($item->id, $movement->reference_id);
        $this->assertSame('2.000', $movement->quantity);
        $this->assertGreaterThan(0, (float) $movement->quantity, 'stock_movements.quantity is always positive by CHECK constraint -- direction comes from movement_type, never a sign');
        $default = InventoryLocation::where('store_id', $sale->store_id)->where('is_default', true)->sole();
        $this->assertSame($default->id, $movement->location_id);

        // --- audit_event ----------------------------------------------
        $auditEvent = DB::table('audit_events')->where('event_type', 'SALE_FINALIZED')->sole();
        $this->assertSame($shift->cashier_id, $auditEvent->actor_user_id);
        $this->assertSame($shift->terminal_id, $auditEvent->terminal_id);
        $this->assertSame('sale', $auditEvent->entity_type);
        $this->assertSame($sale->id, $auditEvent->entity_id);

        // --- electronic_journal_entry -----------------------------------
        $journalEntry = DB::table('electronic_journal_entries')->where('event_type', 'INVOICE')->sole();
        $this->assertSame('invoice', $journalEntry->source_type);
        $this->assertSame($invoice->id, $journalEntry->source_id);
        $this->assertSame($auditEvent->id, $journalEntry->audit_event_id);
        $payload = json_decode($journalEntry->payload_json, true);
        $this->assertSame($invoice->invoice_number, $payload['invoice_number']);

        // --- idempotency_records -----------------------------------------
        $idempotencyRecord = DB::table('idempotency_records')
            ->where('terminal_id', $shift->terminal_id)->where('idempotency_key', $idempotencyKey)->sole();
        $this->assertSame('CHECKOUT', $idempotencyRecord->operation_type);
        $this->assertSame('COMPLETED', $idempotencyRecord->status);
        $this->assertSame(64, strlen(rtrim($idempotencyRecord->request_hash)));
        $this->assertSame($sale->id, $idempotencyRecord->result_resource_id);

        // DISC-006 / reconciliation.
        $this->assertSame(
            $sale->grand_total,
            (string) $sale->items()->sum('net_line_amount')
        );
    }

    public function test_checkout_recomputes_totals_server_side_ignoring_client_hints(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();

        $sale = $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '100.00']],
                // Deliberately wrong client hint -- must never be persisted.
                'client_expected_grand_total' => '1.00',
            ]
        );

        $this->assertSame('100.00', $sale->grand_total);
    }

    public function test_replayed_request_returns_the_same_sale_without_creating_another(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $key = (string) Str::uuid();
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '112.00']],
        ];

        $first = $this->checkoutService->finalize($shift->terminal_id, $shift->cashier_id, $key, $payload);
        $second = $this->checkoutService->finalize($shift->terminal_id, $shift->cashier_id, $key, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Sale::count());
        // A replay must return the ORIGINAL transaction_number, never
        // manufacture a second one (Stage 6C ruling, SS7 gate 1).
        $this->assertSame($first->transaction_number, $second->transaction_number);
        $this->assertSame(1, DB::table('sales')->distinct()->count('transaction_number'));
    }

    public function test_transaction_number_has_the_ruled_shape(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();

        $sale = $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '112.00']],
            ]
        );

        // "T-" + a genuine 26-character ULID (Stage 6C ruling, SS7 gate 1)
        // -- distinct in shape from invoices.invoice_number ("000123")
        // and never derived from sale.id (a UUIDv7).
        $this->assertMatchesRegularExpression('/^T-[0-9A-HJKMNP-TV-Z]{26}$/', $sale->transaction_number);
        $this->assertTrue(Str::isUlid(substr($sale->transaction_number, 2)));
        $this->assertNotSame($sale->id, $sale->transaction_number);
    }

    public function test_shift_required_when_none_is_open(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $shift->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->expectException(ShiftRequiredException::class);
        $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '112.00']],
            ]
        );

        $this->assertSame(0, Sale::count());
    }

    /**
     * Module A decision register (Ruling 1) regression: an OPEN shift on
     * the resolved terminal belonging to a DIFFERENT cashier than the
     * one this request authenticated as must not be usable -- checkout
     * must reject exactly as if no shift existed at all, and must not
     * write any row anywhere.
     */
    public function test_shift_belonging_to_a_different_cashier_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $otherCashier = User::factory()->create(['store_id' => $shift->fiscalDay->store_id]);

        $this->expectException(ShiftRequiredException::class);
        try {
            $this->checkoutService->finalize(
                $shift->terminal_id,
                $otherCashier->id,
                (string) Str::uuid(),
                [
                    'items' => [['product_id' => $product->id, 'quantity' => '1']],
                    'payments' => [['method' => 'CASH', 'amount' => '112.00']],
                ]
            );
        } finally {
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, DB::table('sale_items')->count());
            $this->assertSame(0, DB::table('payments')->count());
            $this->assertSame(0, Invoice::count());
            $this->assertSame(0, DB::table('stock_movements')->count());
            $this->assertSame(0, DB::table('audit_events')->count());
            $this->assertSame(0, DB::table('electronic_journal_entries')->count());
            $this->assertSame(0, DB::table('idempotency_records')->where('status', 'COMPLETED')->count());
            $this->assertSame(0, InvoiceSeries::first()->current_number);
        }
    }

    public function test_fiscal_day_not_open_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $shift->fiscalDay->update(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->expectException(FiscalDayNotOpenException::class);
        $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '112.00']],
            ]
        );
    }

    public function test_insufficient_payment_is_rejected_and_creates_no_sale(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();

        $this->expectException(InsufficientPaymentException::class);
        try {
            $this->checkoutService->finalize(
                $shift->terminal_id,
                $shift->cashier_id,
                (string) Str::uuid(),
                [
                    'items' => [['product_id' => $product->id, 'quantity' => '1']],
                    'payments' => [['method' => 'CASH', 'amount' => '50.00']],
                ]
            );
        } finally {
            $this->assertSame(0, Sale::count());
        }
    }

    public function test_non_positive_payment_amount_is_rejected(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();

        $this->expectException(InvalidPaymentTotalException::class);
        $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '1']],
                'payments' => [['method' => 'CASH', 'amount' => '0.00']],
            ]
        );
    }

    public function test_a_failed_checkout_does_not_advance_the_invoice_counter(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $series = InvoiceSeries::first();
        $before = $series->current_number;

        try {
            $this->checkoutService->finalize(
                $shift->terminal_id,
                $shift->cashier_id,
                (string) Str::uuid(),
                [
                    'items' => [['product_id' => $product->id, 'quantity' => '1']],
                    'payments' => [['method' => 'CASH', 'amount' => '1.00']],
                ]
            );
            $this->fail('Expected InsufficientPaymentException.');
        } catch (InsufficientPaymentException) {
            // expected
        }

        $this->assertSame($before, $series->fresh()->current_number);
        $this->assertSame(0, Invoice::count());
    }

    public function test_mixed_tax_and_discount_checkout_reconciles_exactly(): void
    {
        $shift = Shift::factory()->create();
        $storeId = $shift->fiscalDay->store_id;
        $terminalId = $shift->terminal_id;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $storeId]);
        InvoiceSeries::factory()->create(['store_id' => $storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'terminal_id' => $terminalId,
            'fiscal_installation_id' => $fiscalInstallation->id, 'effective_from' => now()->subYear(),
            'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryLocation::factory()->create(['store_id' => $storeId, 'is_default' => true]);
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $storeId, 'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vatable = Product::factory()->create(['store_id' => $storeId, 'selling_price' => '100.00']);
        $exempt = Product::factory()->vatExempt()->create(['store_id' => $storeId, 'selling_price' => '50.00']);

        $sale = $this->checkoutService->finalize(
            $terminalId,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [
                    ['product_id' => $vatable->id, 'quantity' => '1'],
                    ['product_id' => $exempt->id, 'quantity' => '1'],
                ],
                'order_level_discount_amount' => '10.00',
                'payments' => [['method' => 'CASH', 'amount' => '140.00']],
            ]
        );

        // subtotal 150.00, discount 10.00 -> grand_total 140.00 exactly (DISC-006).
        $this->assertSame('140.00', $sale->grand_total);
        $this->assertSame(
            $sale->grand_total,
            (string) $sale->items()->sum('net_line_amount')
        );
        // TAX-NV reconciliation for a VAT-registered sale.
        $taxSum = bcadd(bcadd($sale->taxable_sales, $sale->vat_exempt_sales, 2), $sale->vat_amount, 2);
        $taxSum = bcadd($taxSum, $sale->zero_rated_sales, 2);
        $this->assertSame($sale->grand_total, $taxSum);
        $this->assertSame('0.00', $sale->non_vat_sales);
    }

    /**
     * Adversarial atomicity test (owner requirement): forces a failure
     * AFTER sale/sale_item/payment rows have already been staged inside
     * the transaction (invoice allocation, ADR-003 step 6, comes after
     * step 5's inserts) rather than before any row exists, then proves
     * the whole attempt -- including the idempotency reservation itself
     * -- leaves no trace, and that retrying with the SAME key afterward
     * succeeds exactly once with exactly one complete write-set.
     */
    public function test_late_stage_failure_leaves_no_partial_write_set_and_the_same_key_can_retry(): void
    {
        ['shift' => $shift, 'product' => $product] = $this->readyToCheckout();
        $series = InvoiceSeries::first();
        // Exhaust the series BEFORE checkout even starts, so allocation
        // fails deterministically after sale/sale_item/payment already
        // exist inside the (still open) transaction. current_number must
        // reach ending_number through a value the range CHECKs actually
        // permit (ending_number >= starting_number) -- setting both to 1
        // (one past the fresh bootstrap floor of 0) is the smallest
        // state that is already exhausted on the very next allocation.
        $series->update(['current_number' => 1, 'ending_number' => 1]);

        $key = (string) Str::uuid();
        $payload = [
            'items' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '100.00']],
        ];

        try {
            $this->checkoutService->finalize($shift->terminal_id, $shift->cashier_id, $key, $payload);
            $this->fail('Expected InvoiceSeriesExhaustedException.');
        } catch (InvoiceSeriesExhaustedException) {
            // expected
        }

        // 1-2. No committed business write-set of any kind survives.
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, DB::table('sale_items')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('electronic_journal_entries')->count());

        // 3. The idempotency reservation itself is gone too -- it was
        // the FIRST statement of the SAME now-rolled-back transaction
        // (IdempotencyService's own design), so no IN_PROGRESS orphan
        // is left behind to falsely block or auto-succeed a retry.
        $this->assertSame(
            0,
            DB::table('idempotency_records')->where('terminal_id', $shift->terminal_id)->where('idempotency_key', $key)->count(),
            'a rolled-back attempt must not leave any idempotency_records row behind'
        );

        // Fix the underlying problem (exactly what an operator would do).
        $series->update(['ending_number' => null]);

        // 4-5. Retrying with the SAME key now succeeds, exactly once.
        $sale = $this->checkoutService->finalize($shift->terminal_id, $shift->cashier_id, $key, $payload);

        $this->assertSame(1, Sale::count());
        $this->assertSame(1, DB::table('sale_items')->count());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, DB::table('stock_movements')->count());
        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('electronic_journal_entries')->count());

        $record = DB::table('idempotency_records')->where('terminal_id', $shift->terminal_id)->where('idempotency_key', $key)->sole();
        $this->assertSame('COMPLETED', $record->status);
        $this->assertSame($sale->id, $record->result_resource_id);
    }
}
