<?php

namespace Tests\Database;

use App\Domain\Exceptions\FiscalDayNotOpenException;
use App\Domain\Exceptions\InsufficientPaymentException;
use App\Domain\Exceptions\InvalidPaymentTotalException;
use App\Domain\Exceptions\ShiftRequiredException;
use App\Domain\Financial\FinancialCalculator;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
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

        $sale = $this->checkoutService->finalize(
            $shift->terminal_id,
            $shift->cashier_id,
            (string) Str::uuid(),
            [
                'items' => [['product_id' => $product->id, 'quantity' => '2']],
                'payments' => [['method' => 'CASH', 'amount' => '200.00']],
            ]
        );

        $this->assertSame('COMPLETED', $sale->status);
        // unit_price is VAT-inclusive (TaxCalculator::decompose divides
        // it out of the total rather than adding on top), so 2 x 100.00
        // stays 200.00 -- the VAT is a breakdown of that total, not an
        // addition to it.
        $this->assertSame('200.00', $sale->grand_total);
        $this->assertCount(1, $sale->items()->get());
        $this->assertCount(1, $sale->payments()->get());
        $this->assertNotNull($sale->invoice);
        $this->assertMatchesRegularExpression('/^\d{6,}$/', $sale->invoice->invoice_number);

        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'sale_item')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'SALE_FINALIZED')->count());
        $this->assertSame(1, DB::table('electronic_journal_entries')->where('event_type', 'INVOICE')->count());

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
}
