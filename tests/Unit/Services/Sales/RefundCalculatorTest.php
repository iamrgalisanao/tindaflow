<?php

namespace Tests\Unit\Services\Sales;

use App\Domain\Exceptions\RefundExceedsRemainingAmountException;
use App\Domain\Exceptions\RefundExceedsRemainingQuantityException;
use App\Domain\Money;
use App\Domain\Quantity;
use App\Services\Sales\RefundCalculator;
use Tests\TestCase;

/** domain-model.md SS2.9: cumulative-recompute-then-subtract, DISC-004/DISC-005. */
class RefundCalculatorTest extends TestCase
{
    private function amount(string $net, string $quantity, string $priorQuantity, string $priorAmount, string $requested): Money
    {
        return (new RefundCalculator)->amountForEvent(
            'item',
            new Money($net),
            new Quantity($quantity),
            new Quantity($priorQuantity),
            new Money($priorAmount),
            new Quantity($requested),
        );
    }

    public function test_a_full_return_gives_back_exactly_the_net_line_amount(): void
    {
        $this->assertSame('100.00', $this->amount('100.00', '3', '0', '0.00', '3')->toApiString());
    }

    public function test_a_partial_return_is_the_proportional_share(): void
    {
        $this->assertSame('50.00', $this->amount('100.00', '4', '0', '0.00', '2')->toApiString());
    }

    public function test_partial_returns_across_events_sum_to_exactly_the_line_amount(): void
    {
        // 100.00 over 3 units returned one at a time: 33.33 + 33.34 + 33.33, never 33.33 x 3.
        $first = $this->amount('100.00', '3', '0', '0.00', '1');
        $second = $this->amount('100.00', '3', '1', $first->toApiString(), '1');
        $third = $this->amount('100.00', '3', '2', $first->add($second)->toApiString(), '1');

        $this->assertSame('33.33', $first->toApiString());
        $this->assertSame('33.34', $second->toApiString());
        $this->assertSame('33.33', $third->toApiString());
        $this->assertSame('100.00', $first->add($second)->add($third)->toApiString());
    }

    public function test_the_residual_lands_on_whichever_event_completes_the_line(): void
    {
        $first = $this->amount('10.00', '3', '0', '0.00', '2'); // 6.67
        $second = $this->amount('10.00', '3', '2', $first->toApiString(), '1');

        $this->assertSame('6.67', $first->toApiString());
        $this->assertSame('3.33', $second->toApiString());
    }

    public function test_fractional_quantities_are_supported(): void
    {
        $this->assertSame('30.00', $this->amount('60.00', '2.000', '0', '0.00', '1.000')->toApiString());
        $this->assertSame('15.00', $this->amount('60.00', '2.000', '0', '0.00', '0.500')->toApiString());
    }

    public function test_more_than_remains_is_rejected_by_quantity(): void
    {
        $this->expectException(RefundExceedsRemainingQuantityException::class);

        $this->amount('100.00', '3', '2', '66.67', '2');
    }

    public function test_returning_from_a_fully_returned_line_is_rejected(): void
    {
        $this->expectException(RefundExceedsRemainingQuantityException::class);

        $this->amount('100.00', '3', '3', '100.00', '1');
    }

    public function test_a_prior_total_above_what_the_quantity_owes_is_rejected_by_amount(): void
    {
        // Inconsistent history (more money out than 1 of 3 units is worth): the next event would be negative.
        $this->expectException(RefundExceedsRemainingAmountException::class);

        $this->amount('100.00', '3', '1', '90.00', '1');
    }

    public function test_remaining_reports_what_is_left(): void
    {
        $remaining = (new RefundCalculator)->remaining(new Money('100.00'), new Quantity('3'), new Quantity('1'), new Money('33.33'));

        $this->assertSame('2.000', $remaining['quantity']->toApiString());
        $this->assertSame('66.67', $remaining['amount']->toApiString());
    }
}
