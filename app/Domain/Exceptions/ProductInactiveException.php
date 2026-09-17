<?php

namespace App\Domain\Exceptions;

/** error-catalog.md PRODUCT_INACTIVE: referenced product has been deactivated. */
final class ProductInactiveException extends DomainException
{
    public static function forId(string $productId): self
    {
        return new self("Product \"{$productId}\" is inactive.", ['product_id' => $productId]);
    }

    public function errorCode(): string
    {
        return 'PRODUCT_INACTIVE';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
