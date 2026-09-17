<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * A `fiscal_installation` has zero ACTIVE `invoice_series` rows (or,
 * defensively, more than one -- the
 * `invoice_series_one_active_per_installation` partial unique index
 * should make that DB-unreachable, but the allocator still asserts it).
 * Deliberately NOT a {@see DomainException} subclass, for the same
 * reason as {@see InvalidTaxConfigurationException}: this is a fiscal
 * installation setup defect a properly configured installation should
 * never reach, not a business outcome a checkout caller should receive
 * as a stable HTTP error. A command handler that reaches this should
 * log/alert deliberately rather than translate it into an HTTP error.
 */
final class InvoiceSeriesResolutionException extends RuntimeException
{
    public static function noActiveSeriesForFiscalInstallation(string $fiscalInstallationId): self
    {
        return new self(
            "Fiscal installation \"{$fiscalInstallationId}\" has no ACTIVE invoice_series. Every fiscal ".
            'installation must have exactly one ACTIVE invoice_series configured before a sale can ever be '.
            'finalized against it -- this is a setup defect, not a runtime condition checkout should ever encounter.'
        );
    }

    public static function ambiguousActiveSeriesForFiscalInstallation(string $fiscalInstallationId, int $activeCount): self
    {
        return new self(
            "Fiscal installation \"{$fiscalInstallationId}\" has {$activeCount} ACTIVE invoice_series rows. ".
            'invoice_series_one_active_per_installation (a database partial unique index) guarantees this cannot '.
            'happen -- reaching this branch means that database invariant was bypassed or is missing, which is a '.
            'database invariant violation to investigate directly, never a normal application state to resolve '.
            'by picking one arbitrarily.'
        );
    }
}
