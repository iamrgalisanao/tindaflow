<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\Checkout\InventoryLocationResolver;

/**
 * Where returned stock goes back. Reversing into the location the original SALE movement came out of
 * keeps every per-location balance equal to its ledger even if the store's default location has
 * changed since the sale. A sale with no recorded movement (data from before stock was tracked per
 * line) falls back to the store's current default location, the same rule checkout uses.
 */
final class SaleReturnLocator
{
    public function __construct(private readonly InventoryLocationResolver $defaultLocation) {}

    public function forSaleItem(SaleItem $item, Sale $sale): string
    {
        $original = StockMovement::where('reference_type', 'sale_item')
            ->where('reference_id', $item->id)
            ->where('movement_type', 'SALE')
            ->value('location_id');

        return $original ?? $this->defaultLocation->resolveDefaultForStore($sale->store_id);
    }
}
