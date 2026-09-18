<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INVENTORY_LOCATION_NOT_FOUND. */
final class InventoryLocationNotFoundException extends DomainException
{
    public static function forId(string $inventoryLocationId): self
    {
        return new self(
            "Inventory location \"{$inventoryLocationId}\" was not found.",
            ['inventory_location_id' => $inventoryLocationId],
        );
    }

    public function errorCode(): string
    {
        return 'INVENTORY_LOCATION_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
