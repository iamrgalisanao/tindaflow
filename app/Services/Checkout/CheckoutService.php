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
use App\Domain\Financial\StatutoryDiscountCalculator;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\FiscalInstallation;
use App\Models\FiscalInstallationAccreditation;
use App\Models\FiscalInstallationPermitToUse;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Terminal;
use App\Models\User;
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
use Illuminate\Support\Facades\Gate;
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
        private readonly StatutoryDiscountCalculator $statutoryDiscountCalculator = new StatutoryDiscountCalculator,
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

        // A discount -- line or order-level -- may only be applied by a user holding DISCOUNT_OVERRIDE
        // (RoleCapabilityCatalog grants it to MANAGER/ADMIN only, never CASHIER; openapi.yaml's own
        // `override_reason` field description already says the capability check applies here). Mirrors
        // CashMovementService's Gate::forUser() pattern for CASH_OUT: the acting cashier must personally
        // hold the capability, there is no separate "manager PIN" mechanism in this contract to authorize
        // on someone else's behalf. Checked against the server-computed total, never the client's raw
        // request fields, so a client cannot dodge the check by lying about which line produced it.
        //
        // Deliberately checked against THIS pass -- manual discounts only, before any statutory discount
        // below is added. A Senior Citizen/PWD discount is the customer's own legal entitlement (RA 9994/
        // RA 10754), not a discretionary override a cashier is granted, so it is computed after this gate
        // and never subject to it; see stage-36-statutory-discount.md's authorization design decision.
        if (! $calculation->discountTotal->isZero()) {
            Gate::forUser(User::findOrFail($cashierId))->authorize('DISCOUNT_OVERRIDE');
        }

        // Stage 36: RA 9994 (Senior Citizen) / RA 10754 (PWD) -- 20% off the VAT-exclusive price, VAT
        // exempt where the store charges VAT at all. Computed from the pass above's own already-governed
        // figures (taxableSales/vatAmount/vatExemptSales/zeroRatedSales/nonVatSales), never re-derived by
        // hand -- see StatutoryDiscountCalculator. FinancialCalculator (ADR-012's sole authority for this
        // arithmetic) is then called a second time, with the affected lines reclassified VAT_EXEMPT and
        // the computed amount folded into the order-level discount, so the existing allocation/rounding/
        // reconciliation machinery produces the final per-line figures -- never a second, parallel
        // calculation path, and DISC-006 (sum of net_line_amount = grand_total) holds by the same
        // construction it always does.
        $discountBeneficiary = null;
        $statutoryRule = $payload['statutory_discount']['rule'] ?? 'STANDARD_20';
        if (isset($payload['statutory_discount'])) {

            // Stage 38: the 5% basic-necessities rule (JAO 24-02) keeps VAT, so no reclassification below.
            $statutoryDiscountAmount = $statutoryRule === 'BNPC_5'
                ? $this->statutoryDiscountCalculator->computeBasicNecessitiesDiscount(
                    $calculation->grandTotal,
                    Money::fromApiString((string) ($payload['statutory_discount']['weekly_discount_used'] ?? '0.00')),
                )
                : $this->statutoryDiscountCalculator->computeDiscount(
                    $calculation->taxableSales,
                    $calculation->vatAmount,
                    $calculation->vatExemptSales->add($calculation->zeroRatedSales)->add($calculation->nonVatSales),
                );

            if ($statutoryRule === 'STANDARD_20' && $taxRegistrationType === 'VAT') {
                $lines = array_map(
                    fn (SaleLineInput $line) => $line->taxClassification === 'VATABLE'
                        ? new SaleLineInput(
                            $line->lineNumber, $line->quantity, $line->unitPrice,
                            $line->lineDiscountAmount, $line->orderDiscountEligible, 'VAT_EXEMPT',
                        )
                        : $line,
                    $lines,
                );
            }

            $orderLevelDiscountAmount = $orderLevelDiscountAmount->add($statutoryDiscountAmount);
            $calculation = $this->financialCalculator->calculateSale($lines, $orderLevelDiscountAmount, $taxRegistrationType);

            // 'type' is printed verbatim by InvoiceSnapshotV2Renderer::beneficiaryBlock() ("Discount: <type>"),
            // so it is translated to plain English here rather than leaving the wire enum on the receipt.
            $discountBeneficiary = [
                'type' => match ($payload['statutory_discount']['type']) {
                    'SENIOR_CITIZEN' => 'Senior Citizen',
                    'PWD' => 'PWD',
                }.($statutoryRule === 'BNPC_5' ? ' (5% basic necessities)' : ''),
                'name' => $payload['statutory_discount']['name'],
                'id_number' => $payload['statutory_discount']['id_number'],
                'tin' => null,
            ];
        }

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
            'schema_version' => 2,
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
            // Schema version 2 (docs/06-backend/stage-24-owner-decisions.md, decision 4): everything above is version
            // 1's shape. These four are new and nullable. The registration data is copied from the terminal's
            // fiscal installation as it stood at the moment of sale, so a later change never rewrites an issued
            // invoice; nothing is invented for a terminal that has none on file.
            'registration' => $this->registrationSnapshot($fiscalInstallationId, $soldAt->toDateString()),
            'payments' => array_map(fn (array $payment) => ['method' => $payment['method'], 'amount' => (string) $payment['amount']], $payload['payments']),
            'amount_tendered' => $tendered = $this->sumAmounts($payload['payments']),
            'change' => bccomp($tendered, $sale->grand_total, 2) === 1 ? bcsub($tendered, $sale->grand_total, 2) : '0.00',
            'discount_beneficiary' => $discountBeneficiary,
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
        //
        // Stage 28 (owner-approved exception to this frozen file): all of the sale's movements go to the ledger in ONE
        // call, which writes them in the canonical stock order (location, then product) instead of the order the
        // cashier scanned. Each write holds its balance row's lock until commit, so two transactions that took the same
        // two rows in opposite orders would deadlock; PostgreSQL's own advice is to acquire locks in a consistent
        // order. The movements themselves are unchanged: still one per sale line.
        // docs/06-backend/stage-28-stock-write-ordering.md.
        $this->stockLedger->recordMany($saleItems->map(fn ($saleItem) => [
            'product_id' => $saleItem->product_id,
            'location_id' => $locationId,
            'terminal_id' => $terminalId,
            'movement_type' => 'SALE',
            'quantity' => (string) $saleItem->quantity,
            'reference_type' => 'sale_item',
            'reference_id' => $saleItem->id,
            'unit_cost' => $saleItem->unit_cost_snapshot,
            'created_by' => $cashierId,
        ])->all());

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

        // domain-model.md SS2.10's reserved DISCOUNT_APPLIED type, written once per sale (not per line) --
        // the DISCOUNT_OVERRIDE check above already gated the write; this is its audit trail. A sale with
        // no discount writes nothing here, matching ProductAuditor's "a save that changes nothing writes
        // nothing" discipline.
        if (! $calculation->discountTotal->isZero()) {
            AuditEvent::create([
                'store_id' => $storeId,
                'event_type' => 'DISCOUNT_APPLIED',
                'actor_user_id' => $cashierId,
                'terminal_id' => $terminalId,
                'entity_type' => 'sale',
                'entity_id' => $sale->id,
                'after_metadata' => array_filter([
                    'sale_id' => $sale->id,
                    'invoice_number' => $allocated->formattedNumber,
                    'line_discount_total' => $calculation->discountTotal->subtract($calculation->orderLevelDiscountAmount)->toApiString(),
                    'order_level_discount_amount' => $calculation->orderLevelDiscountAmount->toApiString(),
                    'discount_total' => $calculation->discountTotal->toApiString(),
                    // Stage 36: present only when this sale carried a Senior Citizen/PWD discount, so an
                    // ordinary discount's audit row is unchanged from before this stage.
                    'statutory_discount_type' => $discountBeneficiary['type'] ?? null,
                    'statutory_discount_beneficiary_name' => $discountBeneficiary['name'] ?? null,
                    'statutory_discount_rule' => $discountBeneficiary !== null ? $statutoryRule : null,
                    'statutory_weekly_discount_used' => $statutoryRule === 'BNPC_5' ? ($payload['statutory_discount']['weekly_discount_used'] ?? '0.00') : null,
                ], fn ($value) => $value !== null),
            ]);
        }

        // ADR-003 step 9 (commit) happens implicitly when
        // IdempotencyService::execute()'s enclosing transaction commits.
        return new OperationOutcome(resultType: 'sale', resultResourceId: $sale->id);
    }

    /**
     * The machine registration data on file for a fiscal installation on a given date: MIN and PTU from its Permit to
     * Use, accreditation number and validity, machine serial and software version. Absent data is null, never guessed.
     *
     * @return array<string, string|null>
     */
    private function registrationSnapshot(string $fiscalInstallationId, string $onDate): array
    {
        $effective = fn ($model) => $model::where('fiscal_installation_id', $fiscalInstallationId)
            ->where('effective_from', '<=', $onDate)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $onDate))
            ->orderByDesc('effective_from')
            ->first();

        $installation = FiscalInstallation::find($fiscalInstallationId);
        $permit = $effective(FiscalInstallationPermitToUse::class);
        $accreditation = $effective(FiscalInstallationAccreditation::class);

        return [
            'min' => $permit?->min,
            'machine_serial_number' => $installation?->machine_serial_number,
            'software_version' => $installation?->software_version,
            'ptu_number' => $permit?->number,
            'ptu_date' => $permit?->date?->toDateString(),
            'accreditation_number' => $accreditation?->number,
            'accreditation_date' => $accreditation?->date?->toDateString(),
            'accreditation_valid_from' => $accreditation?->effective_from?->toDateString(),
            'accreditation_valid_to' => $accreditation?->effective_to?->toDateString(),
        ];
    }

    /** @param  list<array<string, mixed>>  $payments */
    private function sumAmounts(array $payments): string
    {
        return array_reduce($payments, fn (string $carry, array $payment) => bcadd($carry, (string) $payment['amount'], 2), '0.00');
    }
}
