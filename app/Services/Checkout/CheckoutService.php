<?php

namespace App\Services\Checkout;

use App\Domain\Exceptions\FiscalDayNotOpenException;
use App\Domain\Exceptions\InsufficientPaymentException;
use App\Domain\Exceptions\InvalidPaymentTotalException;
use App\Domain\Exceptions\ProductInactiveException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\ShiftRequiredException;
use App\Domain\Financial\FinancialCalculator;
use App\Domain\Financial\SaleLineInput;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Terminal;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use App\Services\Inventory\StockLedger;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-003's checkout transaction script, made callable. Orchestrates
 * already-approved Stage 6A/6B components in the exact order ADR-003
 * specifies; this class introduces no new financial, concurrency, or
 * numbering rule of its own -- see docs/06-backend/stage-6c-sale-finalization.md
 * for the full evidence trail and the Stage 6C rulings this
 * implementation follows.
 *
 * Deliberately does not open its own `DB::transaction()`:
 * {@see IdempotencyService::execute()} already wraps its `$operation`
 * closure (this class's own orchestration) in one transaction, from the
 * reservation insert through to the reservation's completion update --
 * exactly the "same transaction" ADR-003/ADR-005 require. Every
 * `SELECT ... FOR UPDATE` lock taken inside {@see performCheckout()}
 * is released only when that outer transaction commits or rolls back.
 */
final class CheckoutService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly CanonicalRequestHasher $requestHasher,
        private readonly FinancialCalculator $financialCalculator,
        private readonly FiscalInstallationResolver $fiscalInstallationResolver,
        private readonly InventoryLocationResolver $inventoryLocationResolver,
        private readonly TaxRegistrationResolver $taxRegistrationResolver,
        private readonly InvoiceSeriesAllocator $invoiceSeriesAllocator,
        private readonly StockLedger $stockLedger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  the already-shape-validated request body
     *                                         (openapi.yaml SaleFinalizeRequest) -- this
     *                                         class enforces domain/business invariants
     *                                         only, not request shape (stage-6c-sale-finalization.md
     *                                         "Gap 7" ruling).
     */
    public function finalize(string $terminalId, string $cashierId, string $idempotencyKey, array $payload): Sale
    {
        $requestHash = $this->requestHasher->hash($payload);

        $result = $this->idempotencyService->execute(
            $terminalId,
            $idempotencyKey,
            IdempotencyOperationType::Checkout,
            $requestHash,
            fn () => $this->performCheckout($terminalId, $cashierId, $idempotencyKey, $payload),
        );

        return Sale::findOrFail($result->resultResourceId);
    }

    private function performCheckout(string $terminalId, string $cashierId, string $idempotencyKey, array $payload): OperationOutcome
    {
        $lockOrder = new GlobalLockOrder;
        $soldAt = Carbon::now();

        // ADR-003 step 2/3: resolve + lock the terminal's currently OPEN
        // shift, then fiscal day, in the frozen order. A single
        // `WHERE status = 'OPEN' ... FOR UPDATE` query is race-safe by
        // itself under PostgreSQL's READ COMMITTED isolation: FOR UPDATE
        // re-evaluates the WHERE clause against the latest committed row
        // version once the lock is granted, so a shift/fiscal_day closed
        // and committed by a concurrent transaction simply stops
        // matching -- no separate "resolve, then re-check" statement is
        // needed to satisfy ADR-003's "re-validate inside the lock".
        //
        // Module A decision register (docs/06-backend/module-a-auth-terminal-initialization.md,
        // Ruling 1): a Shift ties exactly one terminal_id and one
        // cashier_id together (invariants #33/#34), but only proves
        // "this cashier is operating this terminal" if BOTH are checked
        // -- filtering by terminal_id alone would happily use a shift
        // left open by a DIFFERENT cashier than the one this request
        // actually authenticated as (e.g. Cashier A left a shift open,
        // Cashier B is now logged into that terminal). The authenticated
        // $cashierId must match the shift's own cashier, not merely
        // share its terminal.
        $lockOrder->acquire(LockableResource::Shift);
        $shift = DB::table('shifts')
            ->where('terminal_id', $terminalId)
            ->where('cashier_id', $cashierId)
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();
        if ($shift === null) {
            throw ShiftRequiredException::forTerminalAndCashier($terminalId, $cashierId);
        }

        $lockOrder->acquire(LockableResource::FiscalDay);
        $fiscalDay = DB::table('fiscal_days')
            ->where('terminal_id', $terminalId)
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();
        if ($fiscalDay === null) {
            throw FiscalDayNotOpenException::forTerminal($terminalId);
        }

        $terminal = Terminal::findOrFail($terminalId);
        $storeId = $terminal->store_id;

        // Stage 6C ruling, Gap 1: resolve the terminal's effective fiscal
        // installation at sold_at, [effective_from, effective_to).
        $fiscalInstallationId = $this->fiscalInstallationResolver->resolveForTerminal($terminalId, $soldAt->toDateTimeString());

        // Stage 6C ruling, Gap 2: V1 always deducts from the store's
        // single default inventory location.
        $locationId = $this->inventoryLocationResolver->resolveDefaultForStore($storeId);

        // Stage 5 migration's own instruction: "no active row = finalization
        // must fail, a Stage 6 application check" (tax_registrations).
        $taxRegistrationType = $this->taxRegistrationResolver->resolveForStore($storeId, $soldAt->toDateString());

        // ADR-003 step 3 (continued): fetch each product's current snapshot.
        $items = array_values($payload['items']);
        $productIds = collect($items)->pluck('product_id')->unique()->values();
        // Scoped to the terminal's own store (domain-model.md SS2.1): a product from another store is
        // indistinguishable from one that does not exist, never sellable here.
        $products = Product::where('store_id', $storeId)->whereIn('id', $productIds)->get()->keyBy('id');
        foreach ($productIds as $productId) {
            if (! $products->has($productId)) {
                throw ProductNotFoundException::forId($productId);
            }
            if (! $products[$productId]->active) {
                throw ProductInactiveException::forId($productId);
            }
        }

        // ADR-003 step 4: FinancialCalculator is the sole authority
        // (ADR-012) -- the client's own totals, if any, are never trusted.
        $lines = [];
        foreach ($items as $index => $item) {
            $product = $products[$item['product_id']];
            $lines[] = new SaleLineInput(
                lineNumber: $index + 1,
                quantity: new Quantity((string) $item['quantity']),
                unitPrice: Money::fromApiString((string) $product->selling_price),
                lineDiscountAmount: Money::fromApiString((string) ($item['line_discount_amount'] ?? '0.00')),
                orderDiscountEligible: (bool) ($item['order_discount_eligible'] ?? true),
                taxClassification: $product->tax_class,
            );
        }

        $orderLevelDiscountAmount = Money::fromApiString((string) ($payload['order_level_discount_amount'] ?? '0.00'));
        $calculation = $this->financialCalculator->calculateSale($lines, $orderLevelDiscountAmount, $taxRegistrationType);

        // Server-authoritative payment sufficiency (invariant #8).
        $totalPayments = Money::zero();
        foreach ($payload['payments'] as $payment) {
            $amount = Money::fromApiString((string) $payment['amount']);
            if (! $amount->isPositive()) {
                throw InvalidPaymentTotalException::becauseNonPositive($amount->toApiString());
            }
            $totalPayments = $totalPayments->add($amount);
        }
        if ($totalPayments->lessThan($calculation->grandTotal)) {
            throw InsufficientPaymentException::forTotals($totalPayments->toApiString(), $calculation->grandTotal->toApiString());
        }

        // ADR-003 step 5: insert sale, sale_item, payment.
        $sale = Sale::create([
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'fiscal_day_id' => $fiscalDay->id,
            'shift_id' => $shift->id,
            'cashier_id' => $cashierId,
            // Stage 6C ruling (stage-6c-sale-finalization.md SS7 gate 1,
            // APPROVED): transaction_number is a separate operational
            // reference, never derived from sale.id (a UUIDv7 via
            // HasUuids serves database identity; these are deliberately
            // different identifiers for different purposes). Format is
            // "T-" + a ULID -- the prefix keeps this visually
            // unmistakable from invoices.invoice_number ("000123") in
            // logs, receipts, and support conversations. Generated
            // exactly once per genuine execution, inside this same
            // authoritative transaction (never on a replay -- see
            // finalize()'s idempotency wrapping); satisfies the frozen
            // UNIQUE(store_id, transaction_number) scope (Stage 5
            // instruction §64) without a retry-on-conflict loop, and is
            // never a fiscal counter or a chronology guarantee --
            // sold_at/database timestamps and InvoiceSeries remain
            // authoritative for those.
            'transaction_number' => 'T-'.Str::ulid(),
            'sold_at' => $soldAt,
            'subtotal' => $calculation->subtotal->toApiString(),
            'order_level_discount_amount' => $calculation->orderLevelDiscountAmount->toApiString(),
            'discount_total' => $calculation->discountTotal->toApiString(),
            'taxable_sales' => $calculation->taxableSales->toApiString(),
            'vat_exempt_sales' => $calculation->vatExemptSales->toApiString(),
            'zero_rated_sales' => $calculation->zeroRatedSales->toApiString(),
            'vat_amount' => $calculation->vatAmount->toApiString(),
            'non_vat_sales' => $calculation->nonVatSales->toApiString(),
            'grand_total' => $calculation->grandTotal->toApiString(),
            'status' => 'COMPLETED',
            'idempotency_key' => $idempotencyKey,
            'buyer_name' => $payload['buyer_name'] ?? null,
            'buyer_address' => $payload['buyer_address'] ?? null,
            'buyer_tin' => $payload['buyer_tin'] ?? null,
            'buyer_business_style' => $payload['buyer_business_style'] ?? null,
        ]);

        foreach ($lines as $line) {
            $lineResult = $calculation->lines[$line->lineNumber];
            $product = $products[$items[$line->lineNumber - 1]['product_id']];

            SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'line_number' => $lineResult->lineNumber,
                'product_name_snapshot' => $product->name,
                'sku_snapshot' => $product->sku,
                'barcode_snapshot' => $product->barcode,
                'unit_of_measure_snapshot' => $product->unit_of_measure,
                'quantity' => $lineResult->quantity->toApiString(),
                'unit_price_snapshot' => $lineResult->unitPrice->toApiString(),
                'gross_line_amount' => $lineResult->grossLineAmount->toApiString(),
                'line_discount_amount' => $lineResult->lineDiscountAmount->toApiString(),
                'order_discount_eligible' => $lineResult->orderDiscountEligible,
                'allocated_order_discount_amount' => $lineResult->allocatedOrderDiscountAmount->toApiString(),
                'net_line_amount' => $lineResult->netLineAmount->toApiString(),
                'tax_classification_snapshot' => $lineResult->taxClassification,
                // TaxCalculator's frozen VAT rate constant (0.12) is the
                // only nonzero rate this domain currently has -- every
                // non-VATABLE classification always has tax_amount = 0.
                'tax_rate_snapshot' => $lineResult->taxClassification === 'VATABLE' ? '0.1200' : '0.0000',
                'taxable_base' => $lineResult->taxableBase->toApiString(),
                'tax_amount' => $lineResult->taxAmount->toApiString(),
                'unit_cost_snapshot' => $product->cost,
            ]);
        }

        foreach ($payload['payments'] as $payment) {
            Payment::create([
                'sale_id' => $sale->id,
                'method' => $payment['method'],
                'amount' => (string) $payment['amount'],
                'reference_note' => $payment['external_reference'] ?? null,
                'recorded_at' => $soldAt,
            ]);
        }

        $saleItems = $sale->items()->get();

        // ADR-003 step 6: allocate the invoice number, insert invoice.
        $allocated = $this->invoiceSeriesAllocator->allocateForFiscalInstallation($storeId, $fiscalInstallationId, $lockOrder);

        $storeSettings = DB::table('store_settings')->where('store_id', $storeId)->first();

        $invoiceSnapshot = [
            'schema_version' => 1,
            'sale_id' => $sale->id,
            'invoice_number' => $allocated->formattedNumber,
            'issued_at' => $soldAt->toIso8601String(),
            'seller_registered_name' => $storeSettings->registered_name ?? null,
            'seller_tin' => $storeSettings->tin ?? null,
            'seller_address' => $storeSettings->business_address ?? null,
            // Optional, additive keys of schema_version 1: an invoice issued before these existed simply lacks
            // them and its renderer prints nothing for them (ADR-006). Header and footer are the store's own
            // free text (Module B); the branch code is part of the seller's identity.
            'seller_branch_code' => $storeSettings->branch_code ?? null,
            'invoice_header' => $storeSettings->invoice_header ?? null,
            'invoice_footer' => $storeSettings->invoice_footer ?? null,
            'tax_registration_type' => $taxRegistrationType,
            'terminal_code' => $terminal->terminal_code,
            'buyer_name' => $sale->buyer_name,
            'buyer_address' => $sale->buyer_address,
            'buyer_tin' => $sale->buyer_tin,
            'buyer_business_style' => $sale->buyer_business_style,
            'items' => $saleItems->map(fn (SaleItem $i) => [
                'line_number' => $i->line_number,
                'product_name' => $i->product_name_snapshot,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price_snapshot,
                'net_line_amount' => $i->net_line_amount,
                'tax_classification' => $i->tax_classification_snapshot,
                'tax_amount' => $i->tax_amount,
            ])->all(),
            'subtotal' => $sale->subtotal,
            'discount_total' => $sale->discount_total,
            'taxable_sales' => $sale->taxable_sales,
            'vat_exempt_sales' => $sale->vat_exempt_sales,
            'zero_rated_sales' => $sale->zero_rated_sales,
            'vat_amount' => $sale->vat_amount,
            'non_vat_sales' => $sale->non_vat_sales,
            'grand_total' => $sale->grand_total,
        ];

        $invoice = Invoice::create([
            'store_id' => $storeId,
            'sale_id' => $sale->id,
            'invoice_series_id' => $allocated->invoiceSeriesId,
            'fiscal_installation_id' => $fiscalInstallationId,
            'invoice_number' => $allocated->formattedNumber,
            'issued_at' => $soldAt,
            'terminal_id' => $terminalId,
            'seller_registered_name_snapshot' => $storeSettings->registered_name ?? '',
            'tax_registration_type_snapshot' => $taxRegistrationType,
            'terminal_code_snapshot' => $terminal->terminal_code,
            'invoice_snapshot_json' => $invoiceSnapshot,
        ]);

        // ADR-003 step 7: one SALE-type stock_movement per sale_item,
        // all against the resolved default location. Written through the
        // StockLedger so stock_balances moves in the same transaction
        // (invariant #44); it was previously left untouched by sales.
        foreach ($saleItems as $saleItem) {
            $this->stockLedger->record([
                'product_id' => $saleItem->product_id,
                'location_id' => $locationId,
                'terminal_id' => $terminalId,
                'movement_type' => 'SALE',
                'quantity' => (string) $saleItem->quantity,
                'reference_type' => 'sale_item',
                'reference_id' => $saleItem->id,
                'unit_cost' => $saleItem->unit_cost_snapshot,
                'created_by' => $cashierId,
            ]);
        }

        // ADR-003 step 8: audit_event + electronic_journal_entry (ADR-005:
        // synchronous, same transaction, never queued).
        $auditEvent = AuditEvent::create([
            'store_id' => $storeId,
            'event_type' => 'SALE_FINALIZED',
            'actor_user_id' => $cashierId,
            'terminal_id' => $terminalId,
            'entity_type' => 'sale',
            'entity_id' => $sale->id,
            'after_metadata' => ['sale_id' => $sale->id, 'invoice_number' => $allocated->formattedNumber],
        ]);

        ElectronicJournalEntry::create([
            'store_id' => $storeId,
            'terminal_id' => $terminalId,
            'event_type' => 'INVOICE',
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'audit_event_id' => $auditEvent->id,
            'payload_json' => $invoiceSnapshot,
        ]);

        // ADR-003 step 9 (commit) happens implicitly when
        // IdempotencyService::execute()'s enclosing transaction commits.
        return new OperationOutcome(resultType: 'sale', resultResourceId: $sale->id);
    }
}
