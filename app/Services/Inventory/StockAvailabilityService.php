<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\InventoryLocationResolutionException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Services\Checkout\InventoryLocationResolver;
use Illuminate\Support\Facades\DB;

/**
 * Unlocked reads of what is on hand, for the till and for the early (advisory) checks of ADR-017 section 6. The decisive check is never
 * here: it runs inside the checkout transaction on locked rows ({@see StockLedger::lockRows()}). Everything here is quantities only, no costs.
 */
final class StockAvailabilityService
{
    public function __construct(
        private readonly InventoryLocationResolver $locations = new InventoryLocationResolver,
        private readonly StockDemandResolver $demands = new StockDemandResolver,
    ) {}

    /**
     * On-hand quantity per product id at a location, unlocked. A product with no balance row is simply absent (the caller counts it as zero).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    public function availableFor(string $locationId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('stock_balances')->where('location_id', $locationId)->whereIn('product_id', $productIds)->pluck('quantity_on_hand', 'product_id')
            ->map(fn ($quantity): string => bcadd((string) $quantity, '0', 3))->all();
    }

    /**
     * The shortfall of a cart as the till sent it: `items[]` of `{product_id, quantity}` (a meal item has no product and demands nothing in slice A).
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     *
     * @throws ProductNotFoundException a product that is not this store's
     */
    public function cartShortfalls(string $storeId, array $items): array
    {
        $ids = collect($items)->pluck('product_id')->filter()->unique()->values();
        $products = Product::where('store_id', $storeId)->whereIn('id', $ids)->get()->keyBy('id');

        $lines = [];
        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $product = $products->get($item['product_id']) ?? throw ProductNotFoundException::forId($item['product_id']);
            $lines[] = ['product' => $product, 'quantity' => (string) $item['quantity']];
        }

        $demands = $this->demands->fromLines($lines);
        $locationId = $this->locations->resolveDefaultForStore($storeId);

        return $this->demands->shortfalls($demands, $this->availableFor($locationId, array_keys($demands)));
    }

    /**
     * What the till shows (GET /stock/availability): the store's rules and, for every active tracked product, what is left at the default
     * location with its low-stock state. Advisory; the server decides at checkout.
     *
     * @return array{blocking: bool, override_available: bool, low_stock_default_threshold: int, items: list<array<string, mixed>>}
     */
    public function forTill(string $storeId): array
    {
        $rules = StockRules::forStore($storeId);

        try {
            $locationId = $this->locations->resolveDefaultForStore($storeId);
        } catch (InventoryLocationResolutionException) {
            $locationId = null;
        }

        $items = [];
        if ($locationId !== null) {
            $rows = DB::table('products')
                ->leftJoin('stock_balances', fn ($join) => $join->on('stock_balances.product_id', '=', 'products.id')->where('stock_balances.location_id', $locationId))
                ->where('products.store_id', $storeId)
                ->where('products.track_inventory', true)
                ->where('products.active', true)
                ->where('products.menu_hidden', false)
                ->orderBy('products.name')->orderBy('products.id')
                ->get(['products.id', 'products.reorder_level', 'stock_balances.quantity_on_hand']);

            foreach ($rows as $row) {
                $remaining = bcadd((string) ($row->quantity_on_hand ?? '0'), '0', 3);
                $threshold = $rules->thresholdFor($row->reorder_level === null ? null : (int) $row->reorder_level);
                $items[] = [
                    'product_id' => $row->id,
                    'remaining' => $remaining,
                    'threshold' => $threshold,
                    'low' => bccomp($remaining, '0', 3) > 0 && bccomp($remaining, (string) $threshold, 3) <= 0,
                    'sold_out' => bccomp($remaining, '0', 3) <= 0,
                ];
            }
        }

        return [
            'blocking' => $rules->blockSaleWithoutStock,
            'override_available' => $rules->overrideAvailable(),
            'low_stock_default_threshold' => $rules->defaultThreshold,
            'items' => $items,
        ];
    }
}
