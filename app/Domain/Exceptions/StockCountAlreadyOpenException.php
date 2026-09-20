<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md STOCK_COUNT_ALREADY_OPEN: a location has at most one count in progress, so two people
 * counting the same shelf share it instead of each posting a correction against the same stock.
 */
final class StockCountAlreadyOpenException extends DomainException
{
    public static function forLocation(string $locationId, string $openStockCountId): self
    {
        return new self(
            'This location already has a stock count in progress.',
            ['inventory_location_id' => $locationId, 'stock_count_id' => $openStockCountId],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_COUNT_ALREADY_OPEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
