<?php

namespace App\Domain\Exceptions;

/** error-catalog.md STOCK_COUNT_NOT_FOUND. Identical for a missing count and one in another store. */
final class StockCountNotFoundException extends DomainException
{
    public static function forId(string $stockCountId): self
    {
        return new self(
            "Stock count \"{$stockCountId}\" was not found.",
            ['stock_count_id' => $stockCountId],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_COUNT_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
