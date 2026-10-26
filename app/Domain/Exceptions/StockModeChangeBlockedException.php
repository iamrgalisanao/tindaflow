<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md STOCK_MODE_CHANGE_BLOCKED (ADR-017 section 3): a product or dish cannot change between running and daily stock (or switch daily
 * stock off) while it still holds stock, because the stock would be silently orphaned. `details.position` is the figure that is not zero. The manager
 * adjusts it out first.
 */
final class StockModeChangeBlockedException extends DomainException
{
    public static function because(string $message, array $details = []): self
    {
        return new self($message, $details);
    }

    public function errorCode(): string
    {
        return 'STOCK_MODE_CHANGE_BLOCKED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
