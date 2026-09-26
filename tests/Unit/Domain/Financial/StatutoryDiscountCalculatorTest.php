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

    public function test_basic_necessities_rule_is_five_percent_of_the_vat_inclusive_price(): void
    {
        $discount = $this->calculator->computeBasicNecessitiesDiscount(Money::fromApiString('112.00'), Money::zero());

        $this->assertSame('5.60', $discount->toApiString());
    }

    public function test_basic_necessities_discount_is_limited_to_the_rest_of_the_weekly_cap(): void
    {
        // 5% of 2,000.00 = 100.00, but only 125.00 - 60.00 = 65.00 of the week's cap is left.
        $discount = $this->calculator->computeBasicNecessitiesDiscount(Money::fromApiString('2000.00'), Money::fromApiString('60.00'));

        $this->assertSame('65.00', $discount->toApiString());
    }

    public function test_basic_necessities_discount_is_zero_once_the_cap_is_spent(): void
    {
        $this->assertTrue($this->calculator->computeBasicNecessitiesDiscount(Money::fromApiString('500.00'), Money::fromApiString('125.00'))->isZero());
        $this->assertTrue($this->calculator->computeBasicNecessitiesDiscount(Money::fromApiString('500.00'), Money::fromApiString('130.00'))->isZero());
    }

    public function test_basic_necessities_discount_never_exceeds_the_cap_on_a_large_sale(): void
    {
        // The 2,500.00 purchase cap in the JAO is the same limit as 125.00 of discount at 5%.
        $discount = $this->calculator->computeBasicNecessitiesDiscount(Money::fromApiString('10000.00'), Money::zero());

        $this->assertSame('125.00', $discount->toApiString());
    }
}
