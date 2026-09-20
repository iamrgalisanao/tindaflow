<?php

namespace App\Domain\Exceptions;

/** error-catalog.md STOCK_COUNT_NOT_OPEN: only an OPEN count can be edited, posted or cancelled. */
final class StockCountNotOpenException extends DomainException
{
    public static function forCount(string $stockCountId, string $status): self
    {
        return new self(
            'This stock count is '.strtolower($status).' and can no longer be changed.',
            ['stock_count_id' => $stockCountId, 'status' => $status],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_COUNT_NOT_OPEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
