<?php

namespace App\Domain\Exceptions;

/** invariants.md #28/DISC-004 / error-catalog.md REFUND_EXCEEDS_REMAINING_AMOUNT. */
final class RefundExceedsRemainingAmountException extends DomainException
{
    public static function forSaleItem(string $saleItemId, string $requested, string $remaining): self
    {
        return new self(
            'The requested refund amount exceeds this line\'s remaining net_line_amount.',
            ['sale_item_id' => $saleItemId, 'requested_amount' => $requested, 'remaining_amount' => $remaining]
        );
    }

    public function errorCode(): string
    {
        return 'REFUND_EXCEEDS_REMAINING_AMOUNT';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
