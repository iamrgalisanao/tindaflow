<?php

namespace App\Services\Sales;

use App\Domain\Exceptions\RefundExceedsRemainingAmountException;
use App\Domain\Exceptions\RefundExceedsRemainingQuantityException;
use App\Domain\Money;
use App\Domain\Quantity;

/**
 * domain-model.md SS2.9's refund basis: a refund derives only from the original sale_item's
 * net_line_amount and never from current prices or discounts. The amount for one refund event is
 * the cumulative-recompute-then-subtract rule, so fully returning a line across any number of
 * partial refunds sums to exactly net_line_amount (DISC-005).
 */
final class RefundCalculator
{
    /**
     * @param  Money  $priorAmount  SUM(unit_refund_amount) over COMPLETED refunds of this line
     * @param  Quantity  $priorQuantity  SUM(quantity_returned) over COMPLETED refunds of this line
     *
     * @throws RefundExceedsRemainingQuantityException invariant #27
     * @throws RefundExceedsRemainingAmountException invariant #28
     */
    public function amountForEvent(
        string $saleItemId,
        Money $netLineAmount,
        Quantity $saleQuantity,
        Quantity $priorQuantity,
        Money $priorAmount,
        Quantity $requestedQuantity,
    ): Money {
        $remainingQuantity = $saleQuantity->subtract($priorQuantity);
        if ($requestedQuantity->greaterThan($remainingQuantity)) {
            throw RefundExceedsRemainingQuantityException::forSaleItem($saleItemId, $requestedQuantity->toApiString(), $remainingQuantity->toApiString());
        }

        $cumulative = $priorQuantity->add($requestedQuantity);
        $owed = new Money(Money::roundHalfUp(bcdiv(bcmul($netLineAmount->amount(), $cumulative->value(), 6), $saleQuantity->value(), 6)));
        $amount = $owed->subtract($priorAmount);

        $remainingAmount = $netLineAmount->subtract($priorAmount);
        if ($amount->isNegative() || $amount->greaterThan($remainingAmount)) {
            throw RefundExceedsRemainingAmountException::forSaleItem($saleItemId, $amount->toApiString(), $remainingAmount->toApiString());
        }

        return $amount;
    }

    /** @return array{quantity: Quantity, amount: Money} what can still be refunded on the line */
    public function remaining(Money $netLineAmount, Quantity $saleQuantity, Quantity $priorQuantity, Money $priorAmount): array
    {
        return [
            'quantity' => $saleQuantity->subtract($priorQuantity),
            'amount' => $netLineAmount->subtract($priorAmount),
        ];
    }
}
