<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * A store has zero active `tax_registrations` rows at the point of sale.
 * The frozen migration comment (2026_01_01_000060_create_tax_registrations_table.php)
 * states this explicitly by name: "no active row = finalization must
 * fail, a Stage 6 application check, not a schema one" -- this is that
 * check. Deliberately NOT a {@see DomainException} subclass, for the
 * same reason as the other resolution exceptions in this directory: a
 * properly configured store should always have a current tax
 * registration before it is ever allowed to process a sale.
 *
 * Disclosed contract gap (stage-6c-sale-finalization.md): the frozen
 * Stage 4 error catalog has no dedicated code for this condition either
 * -- same treatment as {@see InventoryLocationResolutionException}.
 */
final class NoActiveTaxRegistrationException extends RuntimeException
{
    public static function forStore(string $storeId): self
    {
        return new self(
            "Store \"{$storeId}\" has no active tax_registration at the point of sale. Every store must have a ".
            'current tax registration configured before a sale can ever be finalized against it -- this is a '.
            'setup defect, not a runtime condition checkout should ever encounter.'
        );
    }
}
