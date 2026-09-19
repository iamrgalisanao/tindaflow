<?php

namespace App\Domain\Exceptions;

/** error-catalog.md BARCODE_NOT_FOUND: no product of this store carries the scanned barcode. */
final class BarcodeNotFoundException extends DomainException
{
    public static function forBarcode(string $barcode): self
    {
        return new self('No product matches that barcode.', ['barcode' => $barcode]);
    }

    public function errorCode(): string
    {
        return 'BARCODE_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
