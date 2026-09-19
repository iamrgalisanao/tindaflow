<?php

namespace App\Domain\Exceptions;

/** error-catalog.md STOCK_ADJUSTMENT_REASON_REQUIRED (invariant #46). */
final class StockAdjustmentReasonRequiredException extends DomainException
{
    public static function make(string $movementType): self
    {
        return new self(
            'A reason is required for a stock adjustment, damage or expiry.',
            ['movement_type' => $movementType],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_ADJUSTMENT_REASON_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
