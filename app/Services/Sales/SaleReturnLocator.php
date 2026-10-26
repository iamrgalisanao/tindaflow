<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\Checkout\InventoryLocationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Where returned stock goes back. Reversing into the location the original SALE movement came out of
 * keeps every per-location balance equal to its ledger even if the store's default location has
 * changed since the sale. A sale with no recorded movement (data from before stock was tracked per
 * line) falls back to the store's current default location, the same rule checkout uses.
 */
final class SaleReturnLocator
{
    public function __construct(private readonly InventoryLocationResolver $defaultLocation) {}

    /**
     * Whether the sale line was drawn from DAILY stock (its original SALE movement carries a stock date: a plain daily product, or a product linked
     * to a dish's daily pool). Cooked food that has been served is never put back into stock (ADR-017 client decision 4, 2026-10-27), so a void or a
     * refund writes NO stock movement for such a line; the original SALE stays in that day's ledger and the portion counts as sold, not as left over.
     * Decided from the ledger row, not from the product's mode today, so a later change of mode cannot change what a refund of an old sale does.
     */
    public function drewDailyStock(SaleItem $item): bool
    {
        // A meal line draws through the dishes its customer chose: the portion snapshot on a selection says so.
        return StockMovement::where('reference_type', 'sale_item')
            ->where('reference_id', $item->id)
            ->where('movement_type', 'SALE')
            ->whereNotNull('stock_date')
            ->exists()
            || DB::table('sale_item_selections')->where('sale_item_id', $item->id)->whereNotNull('stock_portion_snapshot')->exists();
    }

    public function forSaleItem(SaleItem $item, Sale $sale): string
    {
        $original = StockMovement::where('reference_type', 'sale_item')
            ->where('reference_id', $item->id)
            ->where('movement_type', 'SALE')
            ->value('location_id');

        return $original ?? $this->defaultLocation->resolveDefaultForStore($sale->store_id);
    }
}
