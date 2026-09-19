<?php

namespace App\Domain\Exceptions;

/** invariants.md #21 / error-catalog.md SALE_ALREADY_VOIDED: a void already succeeded for this sale. */
final class SaleAlreadyVoidedException extends DomainException
{
    public static function forSale(string $saleId): self
    {
        return new self('This sale has already been voided.', ['sale_id' => $saleId]);
    }

    public function errorCode(): string
    {
        return 'SALE_ALREADY_VOIDED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
