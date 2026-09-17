<?php

namespace App\Domain\Exceptions;

/**
 * invariants.md #19 / error-catalog.md INVOICE_SERIES_EXHAUSTED --
 * `invoice_series.ending_number` reached; the next allocation cannot
 * proceed. A genuine, expected, customer-facing runtime outcome (a
 * bounded series can legitimately run out), not a configuration defect
 * -- unlike {@see InvoiceSeriesResolutionException}, this is exactly
 * why the frozen Stage 4 contract already reserves a public code for
 * it (never wrap around, reuse, or silently switch series).
 */
final class InvoiceSeriesExhaustedException extends DomainException
{
    public static function forSeries(string $invoiceSeriesId, int $attemptedSerial, int $endingNumber): self
    {
        return new self(
            "Invoice series \"{$invoiceSeriesId}\" has reached its configured ending number ({$endingNumber}); ".
            "cannot allocate serial {$attemptedSerial}.",
            ['invoice_series_id' => $invoiceSeriesId, 'attempted_serial' => $attemptedSerial, 'ending_number' => $endingNumber]
        );
    }

    public function errorCode(): string
    {
        return 'INVOICE_SERIES_EXHAUSTED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
