<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INVOICE_NOT_FOUND: the invoice does not exist, or belongs to another store (indistinguishable). */
final class InvoiceNotFoundException extends DomainException
{
    public static function forId(string $invoiceId): self
    {
        return new self('The referenced invoice does not exist.', ['invoice_id' => $invoiceId]);
    }

    public function errorCode(): string
    {
        return 'INVOICE_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
