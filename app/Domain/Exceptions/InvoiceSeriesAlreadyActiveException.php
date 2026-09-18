<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md INVOICE_SERIES_ALREADY_ACTIVE. `invoice_series`'s own
 * partial unique index (`invoice_series_one_active_per_installation`)
 * allows at most one ACTIVE series per fiscal installation -- creating a
 * second one requires explicitly closing the current one first
 * (`invoiceSeriesClose`), never an implicit auto-supersede, since silently
 * retiring a fiscally-significant numbering sequence is not a decision
 * this API should make on the admin's behalf.
 */
final class InvoiceSeriesAlreadyActiveException extends DomainException
{
    public static function forFiscalInstallation(string $fiscalInstallationId): self
    {
        return new self(
            'This fiscal installation already has an active invoice series. Close it before activating another.',
            ['fiscal_installation_id' => $fiscalInstallationId],
        );
    }

    public function errorCode(): string
    {
        return 'INVOICE_SERIES_ALREADY_ACTIVE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
