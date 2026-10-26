<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md BILLING_RECONCILIATION_FAILED (BILL-001): the statement built from the sale snapshots does not equal the credit ledger's
 * CHARGE minus REVERSAL for the same range. The statement is never shown or saved in that case; the ledger is the source of truth and a
 * disagreement is a defect to investigate, not a number to print.
 */
final class BillingReconciliationException extends DomainException
{
    /** @param  array<string, string>  $figures */
    public static function mismatch(string $what, array $figures): self
    {
        return new self("The billing statement does not reconcile with the credit ledger ({$what}). Nothing was shown or saved.", $figures);
    }

    public function errorCode(): string
    {
        return 'BILLING_RECONCILIATION_FAILED';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
