<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\InventoryLocationNotFoundException;
use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;

/**
 * Admin surface for `inventory_locations` -- the table
 * `InventoryLocationResolver::resolveDefaultForStore()` reads at checkout
 * time. Without an `is_default` row here, `POST /sales` cannot resolve
 * where stock deducts from.
 */
final class InventoryLocationService
{
    /** A store's first location is made the default automatically -- otherwise a fresh store would need a second, separate "set as default" call just to leave checkout ever able to succeed. */
    public function create(string $storeId, string $name, bool $requestedDefault): InventoryLocation
    {
        return DB::transaction(function () use ($storeId, $name, $requestedDefault) {
            $hasAnyLocation = InventoryLocation::where('store_id', $storeId)->exists();
            $isDefault = $requestedDefault || ! $hasAnyLocation;

            if ($isDefault) {
                InventoryLocation::where('store_id', $storeId)->where('is_default', true)->update(['is_default' => false]);
            }

            return InventoryLocation::create([
                'store_id' => $storeId,
                'name' => $name,
                'is_default' => $isDefault,
            ]);
        });
    }

    /** @param  array<string, mixed>  $data  the already-shape-validated request body */
    public function update(string $storeId, string $inventoryLocationId, array $data): InventoryLocation
    {
        return DB::transaction(function () use ($storeId, $inventoryLocationId, $data) {
            $location = InventoryLocation::where('store_id', $storeId)->lockForUpdate()->find($inventoryLocationId);

            if ($location === null) {
                throw InventoryLocationNotFoundException::forId($inventoryLocationId);
            }

            if (array_key_exists('name', $data)) {
                $location->name = $data['name'];
            }

            if (($data['is_default'] ?? false) === true && ! $location->is_default) {
                InventoryLocation::where('store_id', $storeId)->where('is_default', true)->update(['is_default' => false]);
                $location->is_default = true;
            }

            $location->save();

            return $location;
        });
    }
}
