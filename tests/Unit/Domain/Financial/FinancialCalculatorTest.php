<?php

namespace Tests\Unit\Domain\Financial;

use App\Domain\Exceptions\InvalidTaxConfigurationException;
use App\Domain\Financial\FinancialCalculator;
use App\Domain\Financial\SaleLineInput;
use App\Domain\Money;
use App\Domain\Quantity;
use Tests\TestCase;

class FinancialCalculatorTest extends TestCase
{
    private FinancialCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FinancialCalculator;
    }

    public function test_simple_single_line_vatable_sale(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '1.000', '112.00', taxClassification: 'VATABLE')],
            Money::zero(),
            'VAT'
        );

        $this->assertSame('112.00', $result->subtotal->toApiString());
        $this->assertSame('112.00', $result->grandTotal->toApiString());
        // 112.00 / 1.12 = 100.00 exactly
        $this->assertSame('100.00', $result->taxableSales->toApiString());
        $this->assertSame('12.00', $result->vatAmount->toApiString());
        $this->assertTrue($result->nonVatSales->isZero());
        $this->assertTrue($this->calculator->reconcileGrandTotal($result));
    }

    public function test_fractional_quantity_line(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '0.500', '250.00', taxClassification: 'VAT_EXEMPT')],
            Money::zero(),
            'VAT'
        );

        $this->assertSame('125.00', $result->lines[1]->grossLineAmount->toApiString());
        $this->assertSame('125.00', $result->vatExemptSales->toApiString());
    }

    public function test_line_discount_reduces_net_line_amount_independent_of_order_discount(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', lineDiscountAmount: '10.00', taxClassification: 'VAT_EXEMPT')],
            Money::zero(),
            'VAT'
        );

        $this->assertSame('90.00', $result->lines[1]->netLineAmount->toApiString());
        $this->assertSame('10.00', $result->discountTotal->toApiString());
    }

    public function test_vat_exempt_line_has_no_tax(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', taxClassification: 'VAT_EXEMPT')],
            Money::zero(),
            'VAT'
        );

        $this->assertTrue($result->lines[1]->taxAmount->isZero());
        $this->assertSame('100.00', $result->lines[1]->taxableBase->toApiString());
        $this->assertSame('100.00', $result->vatExemptSales->toApiString());
        $this->assertTrue($result->taxableSales->isZero());
        $this->assertTrue($result->vatAmount->isZero());
    }

    public function test_zero_rated_line_has_no_tax(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', taxClassification: 'ZERO_RATED')],
            Money::zero(),
            'VAT'
        );

        $this->assertTrue($result->lines[1]->taxAmount->isZero());
        $this->assertSame('100.00', $result->zeroRatedSales->toApiString());
    }

    public function test_order_discount_allocation_residual_of_one_centavo(): void
    {
        // 1 peso split three equal ways -- classic one-centavo residual.
        $result = $this->calculator->calculateSale(
            [
                $this->line(1, '1.000', '100.00', taxClassification: 'VAT_EXEMPT'),
                $this->line(2, '1.000', '100.00', taxClassification: 'VAT_EXEMPT'),
                $this->line(3, '1.000', '100.00', taxClassification: 'VAT_EXEMPT'),
            ],
            new Money('1.00'),
            'VAT'
        );

        $this->assertSame('0.34', $result->lines[1]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('0.33', $result->lines[2]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('0.33', $result->lines[3]->allocatedOrderDiscountAmount->toApiString());
    }

    public function test_order_discount_tie_broken_by_ascending_line_number(): void
    {
        // Mirrors the frozen mixed-tax fixture's exact tie shape on a
        // simpler 3-equal-line basket to isolate the tie-break rule alone.
        $result = $this->calculator->calculateSale(
            [
                $this->line(1, '1.000', '160.00', taxClassification: 'VAT_EXEMPT'),
                $this->line(2, '1.000', '160.00', taxClassification: 'VAT_EXEMPT'),
                $this->line(3, '1.000', '160.00', taxClassification: 'VAT_EXEMPT'),
            ],
            new Money('20.00'),
            'VAT'
        );

        // 20 * 160/480 = 6.6666...  floor 6.66, residual 2 centavos ->
        // lines 1 and 2 (ascending line_number) get the extra centavo.
        $this->assertSame('6.67', $result->lines[1]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('6.67', $result->lines[2]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('6.66', $result->lines[3]->allocatedOrderDiscountAmount->toApiString());
    }

    /**
     * Regression fixture: docs/05-api/examples/checkout-mixed-tax-and-payment.json.
     * One VATABLE + one VAT_EXEMPT + one ZERO_RATED line, an order-level
     * discount allocated across all three, sum-then-decompose VAT. Every
     * expected figure below is copied verbatim from that frozen example.
     */
    public function test_frozen_mixed_tax_basket_regression_fixture(): void
    {
        $result = $this->calculator->calculateSale(
            [
                $this->line(1, '2.000', '65.00', taxClassification: 'VATABLE'),
                $this->line(2, '1.000', '250.00', taxClassification: 'VAT_EXEMPT'),
                $this->line(3, '1.000', '100.00', taxClassification: 'ZERO_RATED'),
            ],
            new Money('20.00'),
            'VAT'
        );

        $this->assertSame('480.00', $result->subtotal->toApiString());
        $this->assertSame('20.00', $result->discountTotal->toApiString());
        $this->assertSame('460.00', $result->grandTotal->toApiString());
        $this->assertSame('111.23', $result->taxableSales->toApiString());
        $this->assertSame('239.58', $result->vatExemptSales->toApiString());
        $this->assertSame('95.84', $result->zeroRatedSales->toApiString());
        $this->assertSame('13.35', $result->vatAmount->toApiString());
        $this->assertTrue($result->nonVatSales->isZero());

        $this->assertSame('130.00', $result->lines[1]->grossLineAmount->toApiString());
        $this->assertSame('5.42', $result->lines[1]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('124.58', $result->lines[1]->netLineAmount->toApiString());
        $this->assertSame('111.23', $result->lines[1]->taxableBase->toApiString());
        $this->assertSame('13.35', $result->lines[1]->taxAmount->toApiString());

        $this->assertSame('10.42', $result->lines[2]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('239.58', $result->lines[2]->netLineAmount->toApiString());

        $this->assertSame('4.16', $result->lines[3]->allocatedOrderDiscountAmount->toApiString());
        $this->assertSame('95.84', $result->lines[3]->netLineAmount->toApiString());

        $this->assertTrue($this->calculator->reconcileGrandTotal($result));

        // tax_summary reconciles to grand_total too, per the fixture's own note.
        $taxSummarySum = $result->taxableSales->add($result->vatAmount)
            ->add($result->vatExemptSales)
            ->add($result->zeroRatedSales);
        $this->assertTrue($taxSummarySum->equals($result->grandTotal));
    }

    /**
     * Stage 2/4/5 NON_VAT amendment (domain-model.md §4a): a
     * NON_VAT-registered store's simple sale zeroes every VAT bucket and
     * reports the entire consideration under non_vat_sales, per
     * TAX-NV-003 -- verbatim match to checkout-non-vat-sale.json.
     */
    public function test_non_vat_simple_sale(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '1.000', '250.00', taxClassification: 'NON_VAT')],
            Money::zero(),
            'NON_VAT'
        );

        $this->assertSame('250.00', $result->grandTotal->toApiString());
        $this->assertSame('250.00', $result->nonVatSales->toApiString());
        $this->assertTrue($result->taxableSales->isZero());
        $this->assertTrue($result->vatExemptSales->isZero());
        $this->assertTrue($result->zeroRatedSales->isZero());
        $this->assertTrue($result->vatAmount->isZero());
        $this->assertTrue($result->lines[1]->taxAmount->isZero());
        $this->assertSame('250.00', $result->lines[1]->taxableBase->toApiString());
        $this->assertTrue($this->calculator->reconcileGrandTotal($result));
    }

    public function test_non_vat_discounted_sale_reconciles_to_grand_total(): void
    {
        $result = $this->calculator->calculateSale(
            [
                $this->line(1, '1.000', '150.00', taxClassification: 'NON_VAT'),
                $this->line(2, '1.000', '150.00', lineDiscountAmount: '10.00', taxClassification: 'NON_VAT'),
            ],
            new Money('20.00'),
            'NON_VAT'
        );

        $this->assertSame('270.00', $result->grandTotal->toApiString());
        $this->assertSame('270.00', $result->nonVatSales->toApiString());
        $this->assertTrue($result->taxableSales->isZero());
        $this->assertTrue($result->vatExemptSales->isZero());
        $this->assertTrue($result->zeroRatedSales->isZero());
        $this->assertTrue($result->vatAmount->isZero());
        $this->assertTrue($this->calculator->reconcileGrandTotal($result));
    }

    public function test_non_vat_fractional_quantity_sale_reconciles_to_grand_total(): void
    {
        $result = $this->calculator->calculateSale(
            [$this->line(1, '2.500', '40.00', taxClassification: 'NON_VAT')],
            Money::zero(),
            'NON_VAT'
        );

        $this->assertSame('100.00', $result->grandTotal->toApiString());
        $this->assertSame('100.00', $result->nonVatSales->toApiString());
        $this->assertTrue($this->calculator->reconcileGrandTotal($result));
    }

    /**
     * TAX-NV-002: a VAT-registered store may never finalize a NON_VAT
     * line -- this combination is an unreachable-from-valid-configuration
     * state, not a case to reinterpret automatically.
     */
    public function test_vat_registration_with_non_vat_line_is_rejected(): void
    {
        $this->expectException(InvalidTaxConfigurationException::class);
        $this->expectExceptionMessageMatches('/incompatible/');

        $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', taxClassification: 'NON_VAT')],
            Money::zero(),
            'VAT'
        );
    }

    /**
     * TAX-NV-003: a NON_VAT-registered store may never finalize a
     * VATABLE/VAT_EXEMPT/ZERO_RATED line -- same rule, opposite
     * direction, also never automatically reinterpreted.
     */
    public function test_non_vat_registration_with_vatable_line_is_rejected(): void
    {
        $this->expectException(InvalidTaxConfigurationException::class);
        $this->expectExceptionMessageMatches('/incompatible/');

        $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', taxClassification: 'VATABLE')],
            Money::zero(),
            'NON_VAT'
        );
    }

    public function test_unknown_tax_registration_type_is_rejected(): void
    {
        $this->expectException(InvalidTaxConfigurationException::class);

        $this->calculator->calculateSale(
            [$this->line(1, '1.000', '100.00', taxClassification: 'VATABLE')],
            Money::zero(),
            'BOGUS'
        );
    }

    public function test_grand_total_reconciles_exactly_disc006(): void
    {
        $result = $this->calculator->calculateSale(
            [
                $this->line(1, '3.000', '33.33', taxClassification: 'VATABLE'),
                $this->line(2, '1.000', '17.77', lineDiscountAmount: '2.00', taxClassification: 'VAT_EXEMPT'),
            ],
            new Money('5.00'),
            'VAT'
        );

        $this->assertTrue($this->calculator->reconcileGrandTotal($result));
    }

    public function test_no_lines_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculateSale([], Money::zero(), 'VAT');
    }

    private function line(
        int $lineNumber,
        string $quantity,
        string $unitPrice,
        string $lineDiscountAmount = '0.00',
        bool $orderDiscountEligible = true,
        string $taxClassification = 'VATABLE',
    ): SaleLineInput {
        return new SaleLineInput(
            lineNumber: $lineNumber,
            quantity: new Quantity($quantity),
            unitPrice: new Money($unitPrice),
            lineDiscountAmount: new Money($lineDiscountAmount),
            orderDiscountEligible: $orderDiscountEligible,
            taxClassification: $taxClassification,
        );
    }
}
