<?php

namespace App\Domain\Exceptions;

/**
 * A store has zero (or, defensively, more than one) effective
 * `tax_registrations` mapping at a given point in time: error-catalog.md
 * TAX_REGISTRATION_UNRESOLVED.
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
 * This is that check's ambiguous-data half. The interval is inclusive at
 * both ends, unlike a fiscal installation's: see TaxRegistrationResolver.
 *
 * The message reaches the till, so it carries no internal id. The store is
 * named in the server log instead ({@see context()}).
 */
final class TaxRegistrationResolutionException extends DomainException
{
    private ?string $storeId = null;

    public static function noActiveRegistrationForStore(string $storeId, string $soldAtDate): self
    {
        return (new self(
            "This store has no tax registration in effect on {$soldAtDate}, so the sale cannot be completed. Nothing was sold. ".
            "Ask your administrator to check the store's tax registration.",
            ['reason' => 'NONE', 'date' => $soldAtDate],
        ))->forStore($storeId);
    }

    public static function ambiguousRegistrationForStore(string $storeId, string $soldAtDate, int $matchCount): self
    {
        return (new self(
            "This store has {$matchCount} tax registrations in effect on {$soldAtDate}, so the sale cannot be completed. Nothing was sold. ".
            "Ask your administrator to check the store's tax registration.",
            ['reason' => 'OVERLAPPING', 'date' => $soldAtDate],
        ))->forStore($storeId);
    }

    /** @return array{store_id: ?string} added to the log entry by the exception handler */
    public function context(): array
    {
        return ['store_id' => $this->storeId];
    }

    private function forStore(string $storeId): self
    {
        $this->storeId = $storeId;

        return $this;
    }

    public function errorCode(): string
    {
        return 'TAX_REGISTRATION_UNRESOLVED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
