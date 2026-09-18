<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INVOICE_SERIES_NOT_FOUND. */
final class InvoiceSeriesNotFoundException extends DomainException
{
    public static function forId(string $invoiceSeriesId): self
    {
        return new self(
            "Invoice series \"{$invoiceSeriesId}\" was not found.",
            ['invoice_series_id' => $invoiceSeriesId],
        );
    }

    public function errorCode(): string
    {
        return 'INVOICE_SERIES_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
