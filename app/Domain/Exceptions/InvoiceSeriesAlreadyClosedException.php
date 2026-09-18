<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INVOICE_SERIES_ALREADY_CLOSED. */
final class InvoiceSeriesAlreadyClosedException extends DomainException
{
    public static function forId(string $invoiceSeriesId): self
    {
        return new self(
            "Invoice series \"{$invoiceSeriesId}\" is already closed.",
            ['invoice_series_id' => $invoiceSeriesId],
        );
    }

    public function errorCode(): string
    {
        return 'INVOICE_SERIES_ALREADY_CLOSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
