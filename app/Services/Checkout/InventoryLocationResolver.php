<?php

namespace App\Services\Checkout;

use App\Domain\Exceptions\InventoryLocationResolutionException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the single inventory_location a Sale's stock deductions are
 * written against. Stage 6C ruling (stage-6c-sale-finalization.md
 * SS"Gap 2"): V1 checkout always deducts from the store's default
 * location (`is_default = true`); the frozen `SaleFinalizeRequest`
 * contract has no location-selection field, so this is not a per-request
 * choice. Multi-location checkout is explicitly deferred to a future
 * product/API change, not something this resolver anticipates.
 */
final class InventoryLocationResolver
{
    public function resolveDefaultForStore(string $storeId): string
    {
        $matches = DB::table('inventory_locations')
            ->where('store_id', $storeId)
            ->where('is_default', true)
            ->pluck('id');

        return match ($matches->count()) {
            0 => throw InventoryLocationResolutionException::noDefaultForStore($storeId),
            1 => $matches->first(),
            default => throw InventoryLocationResolutionException::ambiguousDefaultForStore($storeId, $matches->count()),
        };
    }
}
