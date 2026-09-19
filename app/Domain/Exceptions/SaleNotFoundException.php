<?php

namespace App\Domain\Exceptions;

/** error-catalog.md SALE_NOT_FOUND: the sale does not exist, or exists only in another store (indistinguishable, so a foreign id never confirms a record). */
final class SaleNotFoundException extends DomainException
{
    public static function forId(string $saleId): self
    {
        return new self('The referenced sale does not exist.', ['sale_id' => $saleId]);
    }

    public function errorCode(): string
    {
        return 'SALE_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
