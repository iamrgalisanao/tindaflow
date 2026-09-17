<?php

namespace Tests\Unit\Domain\Financial;

use App\Domain\Financial\TaxCalculator;
use App\Domain\Money;
use App\Domain\Quantity;
use Tests\TestCase;

/**
 * Stage 6A hardening (owner review): the frozen mixed-tax fixture
 * regression test proves the calculator matches one known-correct
 * example, but a fixture can still pass even if an implementation
 * rounds too early somewhere the fixture's own numbers happen not to
 * expose. Every case here is deliberately chosen so that rounding at
 * an intermediate step (rather than carrying >=6dp precision through
 * to the frozen materialization point) produces a DIFFERENT, WRONG
 * final centavo -- proving the implementation actually follows
 * domain-model.md §3.1's rule, not merely that today's fixture
 * reconciles.
 */
class AdversarialPrecisionTest extends TestCase
{
    /**
     * Case: fractional Quantity x unit price.
     * Exact product: 33.33 x 3.003 = 100.08999 (5 decimal places).
     * Materialization point: 1 (gross_line_amount).
     * Rounding mode: round-half-up, applied ONCE after >=6dp
     * intermediate multiplication.
     * Expected authoritative result: 100.09.
     * Adversarial contrast: computing the product directly at 2dp
     * scale (bcmul(..., 2), which TRUNCATES rather than rounds) yields
     * 100.08 -- a different, wrong centavo. This test proves the real
     * implementation does not take that shortcut.
     */
    public function test_fractional_quantity_times_unit_price_does_not_round_prematurely(): void
    {
        $result = (new Money('33.33'))->multiplyByQuantity(new Quantity('3.003'));

        $this->assertSame('100.09', $result->toApiString());

        // Prove the naive 2dp-scale shortcut really would have been wrong.
        $naiveShortcut = bcmul('33.33', '3.003', 2);
        $this->assertSame('100.08', $naiveShortcut, 'sanity check: the naive shortcut is indeed different from the correct result');
        $this->assertNotSame($naiveShortcut, $result->toApiString());
    }

    /**
     * Case: percentage discount producing a sub-centavo intermediate.
     * Exact product: 133.33 x 0.075 (7.5%) = 9.99975.
     * Materialization point: 2 (line_discount_amount, "computed from a
     * percentage and rounded, at the point it's applied").
     * Rounding mode: round-half-up, once, after >=6dp intermediate
     * multiplication.
     * Expected authoritative result: 10.00 (9.99975 rounds UP across
     * the peso boundary -- the kind of case a truncating shortcut gets
     * wrong in the most visible way possible).
     * Adversarial contrast: bcmul(..., 2) truncates to 9.99.
     */
    public function test_percentage_discount_sub_centavo_intermediate_rounds_correctly(): void
    {
        $result = (new Money('133.33'))->percentageOf('0.075');

        $this->assertSame('10.00', $result->toApiString());

        $naiveShortcut = bcmul('133.33', '0.075', 2);
        $this->assertSame('9.99', $naiveShortcut, 'sanity check: the naive shortcut truncates across the peso boundary incorrectly');
        $this->assertNotSame($naiveShortcut, $result->toApiString());
    }

    /**
     * Case: proportional allocation across a basis that produces a
     * repeating decimal, structured so premature 2dp rounding would
     * OVERSHOOT the total -- violating the frozen algorithm's own
     * "sum of floors never exceeds T" invariant (domain-model.md
     * §2.7a step 3), not merely producing a slightly-off answer.
     *
     * T = 10.00 split across 7 equal-weight lines.
     * Exact share each: 10.00 x 100/700 = 1.4285714285714... (repeating).
     * Materialization point: 3/6 (order-discount / tax allocation).
     * Rounding mode: NOT round-half-up per item -- floor to 2dp per
     * item, then distribute the residual by largest remainder
     * (domain-model.md §2.7a steps 2-6), which is a deliberately
     * different, narrower rule than Money's general rounding mode.
     * Expected authoritative result: 6 lines at 1.43, 1 line at 1.42
     * (residual = 6 centavos), summing to exactly 10.00.
     * Adversarial contrast: if the "exact share" were rounded to 2dp
     * BEFORE the floor step (1.428571 rounds to 1.43 under round-half-
     * up), treating that rounded value as already-floored would give
     * every one of the 7 lines 1.43 -- summing to 10.01, one centavo
     * OVER the total. This is not just "a different answer," it is a
     * violation of a stated frozen invariant.
     */
    public function test_seven_way_allocation_does_not_overshoot_the_total(): void
    {
        $weights = array_fill_keys(range(1, 7), '100');

        $result = (new Money('10.00'))->allocate($weights);

        for ($line = 1; $line <= 6; $line++) {
            $this->assertSame('1.43', $result[$line]->toApiString(), "line {$line} should receive the residual centavo");
        }
        $this->assertSame('1.42', $result[7]->toApiString(), 'line 7 (last in ascending order) should not receive a residual centavo');

        $sum = array_reduce($result, fn (Money $carry, Money $m) => $carry->add($m), Money::zero());
        $this->assertTrue($sum->equals(new Money('10.00')), 'the allocation must sum to exactly the total, never overshooting it');

        // Sanity check: prove the naive "round the exact share, treat it as the floor" shortcut really would overshoot.
        $naiveShare = Money::roundHalfUp(bcdiv(bcmul('10.00', '100', 6), '700', 6));
        $this->assertSame('1.43', $naiveShare);
        $naiveSum = bcmul($naiveShare, '7', 2);
        $this->assertSame('10.01', $naiveSum, 'sanity check: the naive shortcut overshoots the total by one centavo');
    }

    /**
     * Case: tax decomposition (sum-then-decompose) over a sum that
     * divides into a repeating decimal.
     * Exact division: 100.00 / 1.12 = 89.2857142857142857... (repeating).
     * Materialization point: 5 (sale-level taxable_sales/vat_amount).
     * Rounding mode: round-half-up, once, after >=6dp intermediate
     * division.
     * Expected authoritative result: net = 89.29, vat = 10.71 (net + vat
     * = 100.00 exactly, by construction of vat = gross - net).
     * Adversarial contrast: bcdiv(..., 2) truncates directly to 89.28 --
     * one centavo short of the correctly-rounded value, with the error
     * landing in the government's VAT remittance figure.
     */
    public function test_tax_decomposition_repeating_decimal_rounds_correctly(): void
    {
        $calculator = new TaxCalculator;

        $result = $calculator->decompose(new Money('100.00'));

        $this->assertSame('89.29', $result->net->toApiString());
        $this->assertSame('10.71', $result->vat->toApiString());
        $this->assertTrue($result->net->add($result->vat)->equals(new Money('100.00')));

        $naiveShortcut = bcdiv('100.00', '1.12', 2);
        $this->assertSame('89.28', $naiveShortcut, 'sanity check: naive truncated division is one centavo short of the correct rounded value');
        $this->assertNotSame($naiveShortcut, $result->net->toApiString());
    }

    /**
     * Case: refund basis derived from a finalized allocated amount
     * (DISC-004) -- proves the "remaining refundable" calculation stays
     * exact when built purely from already-materialized 2dp values
     * (net_line_amount minus prior refunds), never re-deriving from
     * quantity x current price or re-splitting the order discount
     * again. Uses the frozen mixed-tax fixture's own line 1
     * net_line_amount (124.58) as the finalized basis.
     * Materialization point: 7 (refund_item.unit_refund_amount, via the
     * cumulative-recompute-then-subtract rule, domain-model.md §2.9) --
     * Stage 6A does not implement the refund service itself, but this
     * proves the Money arithmetic it will depend on introduces no
     * rounding artifact of its own.
     * Rounding mode: none needed -- subtracting two already-2dp values
     * is exact by construction (domain-model.md §3.1 materialization
     * point 4's same "no separate rounding" reasoning applies here).
     * Expected authoritative result: 124.58 - 50.00 - 30.00 = 44.58 exactly.
     */
    public function test_refund_remaining_basis_from_finalized_net_line_amount_stays_exact(): void
    {
        $netLineAmount = new Money('124.58'); // frozen fixture's line 1
        $priorRefund1 = new Money('50.00');
        $priorRefund2 = new Money('30.00');

        $remaining = $netLineAmount->subtract($priorRefund1)->subtract($priorRefund2);

        $this->assertSame('44.58', $remaining->toApiString());
    }
}
