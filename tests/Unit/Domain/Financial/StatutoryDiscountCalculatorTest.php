<?php

namespace Tests\Unit\Domain\Financial;

use App\Domain\Financial\StatutoryDiscountCalculator;
use App\Domain\Money;
use Tests\TestCase;

class StatutoryDiscountCalculatorTest extends TestCase
{
    private StatutoryDiscountCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new StatutoryDiscountCalculator;
    }

    public function test_vatable_line_removes_vat_then_takes_twenty_percent(): void
    {
        // A 112.00 VAT-inclusive line: first pass would already report taxableSales 100.00, vatAmount 12.00.
        $discount = $this->calculator->computeDiscount(
            vatExclusiveVatableBase: Money::fromApiString('100.00'),
            vatPortion: Money::fromApiString('12.00'),
            otherEligibleNet: Money::zero(),
        );

        // 12.00 (the VAT, removed entirely) + 20.00 (20% of the 100.00 VAT-exclusive base) = 32.00.
        // Fed back as an order-level discount against the original 112.00 gross: 112.00 - 32.00 = 80.00,
        // exactly 100.00 x 0.80 -- the customer pays 20% off the VAT-exclusive price, not 20% off the
        // VAT-inclusive shelf price.
        $this->assertSame('32.00', $discount->toApiString());
    }

    public function test_non_vat_line_has_no_vat_to_remove(): void
    {
        // A NON_VAT store: no VATABLE line can exist there (TAX-NV-002/003), so the caller passes zero for
        // both VAT-shaped inputs and the whole net through otherEligibleNet.
        $discount = $this->calculator->computeDiscount(
            vatExclusiveVatableBase: Money::zero(),
            vatPortion: Money::zero(),
            otherEligibleNet: Money::fromApiString('100.00'),
        );

        $this->assertSame('20.00', $discount->toApiString());
    }

    public function test_mixed_vatable_and_vat_exempt_lines_combine(): void
    {
        $discount = $this->calculator->computeDiscount(
            vatExclusiveVatableBase: Money::fromApiString('100.00'),
            vatPortion: Money::fromApiString('12.00'),
            otherEligibleNet: Money::fromApiString('50.00'), // an already VAT_EXEMPT or ZERO_RATED line
        );

        // 12.00 + 20.00 (VATABLE portion) + 10.00 (20% of the already-exempt 50.00) = 42.00.
        $this->assertSame('42.00', $discount->toApiString());
    }

    public function test_no_eligible_amount_is_zero_discount(): void
    {
        $discount = $this->calculator->computeDiscount(Money::zero(), Money::zero(), Money::zero());

        $this->assertTrue($discount->isZero());
    }

    public function test_a_fractional_vat_base_rounds_half_up_via_percentage_of(): void
    {
        // 33.33 x 0.20 = 6.666 -> rounds to 6.67 (Money::percentageOf's own governed rounding, not this
        // class inventing one); discount = 4.00 (vatPortion) + 6.67 = 10.67.
        $discount = $this->calculator->computeDiscount(
            vatExclusiveVatableBase: Money::fromApiString('33.33'),
            vatPortion: Money::fromApiString('4.00'),
            otherEligibleNet: Money::zero(),
        );

        $this->assertSame('10.67', $discount->toApiString());
    }
}
