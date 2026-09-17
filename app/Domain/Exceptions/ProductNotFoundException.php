<?php

namespace App\Domain\Exceptions;

/** error-catalog.md PRODUCT_NOT_FOUND. */
final class ProductNotFoundException extends DomainException
{
    public static function forId(string $productId): self
    {
        return new self("Product \"{$productId}\" was not found.", ['product_id' => $productId]);
    }

    public function errorCode(): string
    {
        return 'PRODUCT_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
