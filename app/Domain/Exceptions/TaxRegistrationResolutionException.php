<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * A store has zero (or, defensively, more than one) effective
 * `tax_registrations` mapping at a given point in time. Deliberately NOT
 * a {@see DomainException} subclass, for the same reason as
 * {@see FiscalInstallationResolutionException}.
 *
 * The frozen migration comment (2026_01_01_000060_create_tax_registrations_table.php)
 * states the "zero matches" case explicitly by name: "no active row =
 * finalization must fail, a Stage 6 application check, not a schema
 * one." The migration's own partial unique index
 * (`tax_registrations_one_current_per_store`) only guarantees at most
 * one row with `effective_to IS NULL` ("current") per store -- it does
 * NOT prevent two overlapping *closed* historical intervals (the same
 * gap the migration's own comment discloses: "a genuinely overlapping
 * closed interval is a transactional/application concern (Stage 6)").
 * This is that check's ambiguous-data half. Resolution uses the same
 * inclusive-start, exclusive-end interval convention as
 * {@see FiscalInstallationResolver}: `[effective_from, effective_to)`.
 *
 * Disclosed contract gap (stage-6c-sale-finalization.md): the frozen
 * Stage 4 error catalog has no dedicated code for either condition.
 */
final class TaxRegistrationResolutionException extends RuntimeException
{
    public static function noActiveRegistrationForStore(string $storeId, string $soldAt): self
    {
        return new self(
            "Store \"{$storeId}\" has no active tax_registration at {$soldAt}. Every store must have a current ".
            'tax registration configured before a sale can ever be finalized against it -- this is a setup '.
            'defect, not a runtime condition checkout should ever encounter.'
        );
    }

    public static function ambiguousRegistrationForStore(string $storeId, string $soldAt, int $matchCount): self
    {
        return new self(
            "Store \"{$storeId}\" has {$matchCount} effective tax_registrations at {$soldAt}. Overlapping ".
            'effective-dated rows for the same store should never coexist -- this is a data-integrity problem '.
            'to investigate and fix directly, never a normal application state to resolve by picking one.'
        );
    }
}
