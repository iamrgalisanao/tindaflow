<?php

namespace App\Domain\Exceptions;

/** error-catalog.md STOCK_TRANSFER_NOT_FOUND. Identical for a missing transfer and one in another store. */
final class StockTransferNotFoundException extends DomainException
{
    public static function forId(string $stockTransferId): self
    {
        return new self(
            "Stock transfer \"{$stockTransferId}\" was not found.",
            ['stock_transfer_id' => $stockTransferId],
        );
    }

    public function errorCode(): string
    {
        return 'STOCK_TRANSFER_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
