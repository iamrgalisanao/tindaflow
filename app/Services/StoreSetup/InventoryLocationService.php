<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\InventoryLocationNotFoundException;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Audit\AuditTrail;
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
    public function create(string $storeId, string $name, bool $requestedDefault, ?User $actor = null): InventoryLocation
    {
        return DB::transaction(function () use ($storeId, $name, $requestedDefault, $actor) {
            $hasAnyLocation = InventoryLocation::where('store_id', $storeId)->exists();
            $isDefault = $requestedDefault || ! $hasAnyLocation;

            if ($isDefault) {
                InventoryLocation::where('store_id', $storeId)->where('is_default', true)->update(['is_default' => false]);
            }

            $location = InventoryLocation::create([
                'store_id' => $storeId,
                'name' => $name,
                'is_default' => $isDefault,
            ]);

            AuditTrail::record($storeId, 'INVENTORY_LOCATION_CREATED', $actor?->id, 'inventory_location', $location->id, null, ['name' => $location->name, 'is_default' => $location->is_default]);

            return $location;
        });
    }

    /** @param  array<string, mixed>  $data  the already-shape-validated request body */
    public function update(string $storeId, string $inventoryLocationId, array $data, ?User $actor = null): InventoryLocation
    {
        return DB::transaction(function () use ($storeId, $inventoryLocationId, $data, $actor) {
            $location = InventoryLocation::where('store_id', $storeId)->lockForUpdate()->find($inventoryLocationId);

            if ($location === null) {
                throw InventoryLocationNotFoundException::forId($inventoryLocationId);
            }

            $before = ['name' => $location->name, 'is_default' => $location->is_default];

            if (array_key_exists('name', $data)) {
                $location->name = $data['name'];
            }

            if (($data['is_default'] ?? false) === true && ! $location->is_default) {
                InventoryLocation::where('store_id', $storeId)->where('is_default', true)->update(['is_default' => false]);
                $location->is_default = true;
            }

            $location->save();

            $after = ['name' => $location->name, 'is_default' => $location->is_default];
            if ($after !== $before) {
                AuditTrail::record($storeId, 'INVENTORY_LOCATION_UPDATED', $actor?->id, 'inventory_location', $location->id, $before, $after);
            }

            return $location;
        });
    }
}
