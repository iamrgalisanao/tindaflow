<?php

namespace App\Domain\Financial;

use App\Domain\Exceptions\InvalidTaxConfigurationException;
use App\Domain\Money;
use InvalidArgumentException;

/**
 * The single authoritative FinancialCalculator (ADR-012). Composes
 * Money, Quantity, DiscountAllocator, and TaxCalculator to run every
 * monetary computation Checkout/Refund need, exactly once. No
 * controller, service, or report may reimplement rounding, allocation,
 * or decomposition -- they call in here and persist the result.
 *
 * Stage 6A scope: line gross calculation, order-discount allocation,
 * tax decomposition/allocation, and grand-total reconciliation. Refund
 * financial-basis calculation (domain-model.md §2.9) is a Stage 6D
 * concern once Refund's service layer exists to call it.
 */
final class FinancialCalculator
{
    public function __construct(
        private readonly DiscountAllocator $discountAllocator = new DiscountAllocator,
        private readonly TaxCalculator $taxCalculator = new TaxCalculator,
    ) {}

    /**
     * @param  array<int, SaleLineInput>  $lines  MUST be supplied in ascending line_number order
     * @param  'VAT'|'NON_VAT'  $taxRegistrationType  The Store TaxRegistration effective at finalization
     *                                                (domain-model.md §4/§4a) -- the caller must resolve this itself; the calculator never infers
     *                                                registration from totals or from a majority/plurality of line classifications.
     */
    public function calculateSale(array $lines, Money $orderLevelDiscountAmount, string $taxRegistrationType): SaleCalculationResult
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Cannot calculate a sale with no line items.');
        }

        if (! in_array($taxRegistrationType, ['VAT', 'NON_VAT'], true)) {
            throw InvalidTaxConfigurationException::unknownRegistrationType($taxRegistrationType);
        }

        // TAX-NV-002/003: a VAT-registered store may only finalize
        // VATABLE/VAT_EXEMPT/ZERO_RATED lines; a NON_VAT-registered store
        // may only finalize NON_VAT lines. This combination should be
        // unreachable from valid configured products (a product's tax
        // classification is expected to already match its store's
        // registration before checkout ever reaches this calculator), so
        // an explicit configuration exception here -- rather than a
        // public API error code -- is the correct Stage 6A behavior.
        foreach ($lines as $line) {
            $compatible = match ($taxRegistrationType) {
                'VAT' => in_array($line->taxClassification, ['VATABLE', 'VAT_EXEMPT', 'ZERO_RATED'], true),
                'NON_VAT' => $line->taxClassification === 'NON_VAT',
            };

            if (! $compatible) {
                throw InvalidTaxConfigurationException::incompatibleClassification(
                    $line->lineNumber,
                    $line->taxClassification,
                    $taxRegistrationType,
                );
            }
        }

        // Materialization point 1: gross_line_amount per line.
        $grossLines = [];
        foreach ($lines as $line) {
            $grossLines[$line->lineNumber] = $line->unitPrice->multiplyByQuantity($line->quantity);
        }

        // Materialization point 3: order-discount allocation.
        $discountLines = array_map(
            fn (SaleLineInput $line) => new DiscountAllocationLine(
                lineNumber: $line->lineNumber,
                grossLineAmount: $grossLines[$line->lineNumber],
                lineDiscountAmount: $line->lineDiscountAmount,
                eligible: $line->orderDiscountEligible,
            ),
            $lines
        );
        $allocatedDiscounts = $this->discountAllocator->allocateOrderDiscount($orderLevelDiscountAmount, $discountLines);

        // Materialization point 4: net_line_amount (exact subtraction, no rounding).
        $netLines = [];
        foreach ($lines as $line) {
            $netLines[$line->lineNumber] = $grossLines[$line->lineNumber]
                ->subtract($line->lineDiscountAmount)
                ->subtract($allocatedDiscounts[$line->lineNumber]);
        }

        // Category sums (already-exact net_line_amount values, summed before any tax rounding).
        // NON_VAT closes the gap resolved by the Stage 2/4/5 NON_VAT
        // amendment (domain-model.md §4a): it now has its own aggregate
        // bucket (sale.non_vat_sales / TaxSummary.non_vat_sales), the
        // per-line-compatibility guard above guarantees every NON_VAT
        // line here belongs to a NON_VAT-registered store (so this sum
        // is never mixed with VATABLE/VAT_EXEMPT/ZERO_RATED lines on the
        // same sale), and per invariant #65, a NON_VAT line is excluded
        // from the VAT allocation basis exactly like VAT_EXEMPT/
        // ZERO_RATED -- it always has tax_amount = 0, taxable_base =
        // net_line_amount (handled by the fallback in the line-result
        // loop below, unchanged).
        $vatableNet = [];
        $vatExemptSum = Money::zero();
        $zeroRatedSum = Money::zero();
        $nonVatSum = Money::zero();
        foreach ($lines as $line) {
            $net = $netLines[$line->lineNumber];
            match ($line->taxClassification) {
                'VATABLE' => $vatableNet[$line->lineNumber] = $net,
                'VAT_EXEMPT' => $vatExemptSum = $vatExemptSum->add($net),
                'ZERO_RATED' => $zeroRatedSum = $zeroRatedSum->add($net),
                'NON_VAT' => $nonVatSum = $nonVatSum->add($net),
                default => throw new InvalidArgumentException("Unknown tax classification \"{$line->taxClassification}\" for line {$line->lineNumber}."),
            };
        }
        $vatableGrossSum = array_reduce($vatableNet, fn (Money $carry, Money $net) => $carry->add($net), Money::zero());

        // Materialization point 5: sum-then-decompose.
        $decomposition = $this->taxCalculator->decompose($vatableGrossSum);

        // Materialization point 6: per-line VAT allocation.
        $taxAllocations = $this->taxCalculator->allocateVatToLines($decomposition->vat, $vatableNet);

        $lineResults = [];
        foreach ($lines as $line) {
            $net = $netLines[$line->lineNumber];
            $tax = $taxAllocations[$line->lineNumber] ?? new TaxAllocationLine(taxableBase: $net, taxAmount: Money::zero());

            $lineResults[$line->lineNumber] = new SaleLineResult(
                lineNumber: $line->lineNumber,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                grossLineAmount: $grossLines[$line->lineNumber],
                lineDiscountAmount: $line->lineDiscountAmount,
                orderDiscountEligible: $line->orderDiscountEligible,
                allocatedOrderDiscountAmount: $allocatedDiscounts[$line->lineNumber],
                netLineAmount: $net,
                taxClassification: $line->taxClassification,
                taxableBase: $tax->taxableBase,
                taxAmount: $tax->taxAmount,
            );
        }

        $subtotal = array_reduce($grossLines, fn (Money $carry, Money $gross) => $carry->add($gross), Money::zero());
        $lineDiscountTotal = array_reduce($lines, fn (Money $carry, SaleLineInput $l) => $carry->add($l->lineDiscountAmount), Money::zero());
        $discountTotal = $lineDiscountTotal->add($orderLevelDiscountAmount);
        $grandTotal = array_reduce($netLines, fn (Money $carry, Money $net) => $carry->add($net), Money::zero());

        return new SaleCalculationResult(
            subtotal: $subtotal,
            orderLevelDiscountAmount: $orderLevelDiscountAmount,
            discountTotal: $discountTotal,
            taxableSales: $decomposition->net,
            vatExemptSales: $vatExemptSum,
            zeroRatedSales: $zeroRatedSum,
            vatAmount: $decomposition->vat,
            nonVatSales: $nonVatSum,
            grandTotal: $grandTotal,
            lines: $lineResults,
        );
    }

    /**
     * DISC-006: SUM(sale_item.net_line_amount) = sale.grand_total,
     * exactly, by construction -- this is a verification aid for tests,
     * not a corrective recomputation the write path depends on.
     */
    public function reconcileGrandTotal(SaleCalculationResult $result): bool
    {
        $sum = array_reduce(
            $result->lines,
            fn (Money $carry, SaleLineResult $line) => $carry->add($line->netLineAmount),
            Money::zero()
        );

        return $sum->equals($result->grandTotal);
    }
}
