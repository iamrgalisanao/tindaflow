<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * A store has zero (or, defensively, more than one -- the
 * `inventory_locations_one_default_per_store` partial unique index should
 * make that DB-unreachable going forward) default `inventory_location` at
 * checkout time. Deliberately NOT a {@see DomainException} subclass.
 *
 * Disclosed contract gap (stage-6c-sale-finalization.md SS"Gap 2"): the
 * frozen Stage 4 error catalog has no code for "no default inventory
 * location configured for this store." Per the Stage 6C ruling, no new
 * wire-level error code is invented here -- this exception is not yet
 * mapped through the normal DomainException-to-HTTP-envelope path, and a
 * store with zero default locations will surface as an unhandled error
 * until a smallest-amendment error-catalog addition is proposed and
 * approved for Stage 4.
 */
final class InventoryLocationResolutionException extends RuntimeException
{
    public static function noDefaultForStore(string $storeId): self
    {
        return new self(
            "Store \"{$storeId}\" has no default inventory_location (is_default = true). Every store must have ".
            'exactly one default inventory location configured before a sale can ever be finalized against it -- '.
            'this is a setup defect, not a runtime condition checkout should ever encounter.'
        );
    }

    public static function ambiguousDefaultForStore(string $storeId, int $defaultCount): self
    {
        return new self(
            "Store \"{$storeId}\" has {$defaultCount} default inventory_location rows. ".
            'inventory_locations_one_default_per_store (a database partial unique index) guarantees this cannot '.
            'happen -- reaching this branch means that database invariant was bypassed or is missing, which is a '.
            'database invariant violation to investigate directly, never a normal application state to resolve '.
            'by picking one arbitrarily.'
        );
    }
}
