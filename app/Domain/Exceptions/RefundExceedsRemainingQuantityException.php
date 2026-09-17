<?php

namespace App\Domain\Exceptions;

/** invariants.md #27 / error-catalog.md REFUND_EXCEEDS_REMAINING_QUANTITY. */
final class RefundExceedsRemainingQuantityException extends DomainException
{
    public static function forSaleItem(string $saleItemId, string $requested, string $remaining): self
    {
        return new self(
            'The requested refund quantity exceeds what remains for this line.',
            ['sale_item_id' => $saleItemId, 'requested_quantity' => $requested, 'remaining_quantity' => $remaining]
        );
    }

    public function errorCode(): string
    {
        return 'REFUND_EXCEEDS_REMAINING_QUANTITY';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
