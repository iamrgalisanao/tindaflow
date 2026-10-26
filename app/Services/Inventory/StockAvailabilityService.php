<?php

namespace App\Services\Inventory;

use App\Domain\Exceptions\InventoryLocationResolutionException;
use App\Domain\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\MealLineResolver;
use App\Services\Menu\MenuPriceResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Unlocked reads of what is on hand, for the till and for the early (advisory) checks of ADR-017 section 6. The decisive check is never
 * here: it runs inside the checkout transaction on locked rows ({@see StockLedger::lockKeys()}). Everything here is quantities only, no costs.
 */
final class StockAvailabilityService
{
    private readonly MealLineResolver $meals;

    public function __construct(
        private readonly InventoryLocationResolver $locations = new InventoryLocationResolver,
        private readonly StockDemandResolver $demands = new StockDemandResolver,
        ?MealLineResolver $meals = null,
    ) {
        $this->meals = $meals ?? new MealLineResolver(new MenuPriceResolver);
    }

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
     * On-hand quantity of every row a cart demands, unlocked, keyed by {@see StockLedger::rowKey()}. A row that does not exist is absent (zero).
     *
     * @param  array<string, array<string, mixed>>  $demands  {@see StockDemandResolver::demandsFrom()}
     * @return array<string, string>
     */
    public function availableForDemands(string $locationId, array $demands): array
    {
        $available = [];
        $running = [];
        foreach ($demands as $demand) {
            if ($demand['stock_date'] === null) {
                $running[] = $demand['product_id'];

                continue;
            }
            $quantity = DB::table('daily_stock_balances')
                ->where('location_id', $locationId)->where('product_id', $demand['product_id'])->where('stock_date', $demand['stock_date'])
                ->value('quantity_on_hand');
            if ($quantity !== null) {
                $available[StockLedger::rowKey($demand['product_id'], $demand['stock_date'])] = bcadd((string) $quantity, '0', 3);
            }
        }

        return $available + $this->availableFor($locationId, $running);
    }

    /**
     * The shortfall of a cart as the till sent it: `items[]` of `{product_id, quantity}` or `{meal, quantity}`. A meal's chosen dishes and a
     * product linked to a dish demand from the dish's daily pool exactly as checkout will (the same {@see StockDemandResolver}).
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
        $rules = StockRules::forStore($storeId);
        $now = Carbon::now();

        $specs = [];
        foreach ($items as $item) {
            if (! empty($item['meal'])) {
                $resolved = $this->meals->resolve($storeId, $item['meal'], (string) $item['quantity'], $now);
                $specs[] = ['product' => $resolved->meal->product, 'quantity' => (string) $item['quantity'], 'meal' => $resolved->meal];

                continue;
            }
            if (empty($item['product_id'])) {
                continue;
            }
            $product = $products->get($item['product_id']) ?? throw ProductNotFoundException::forId($item['product_id']);
            $specs[] = ['product' => $product, 'quantity' => (string) $item['quantity']];
        }

        $demands = $this->demands->demandsFrom($this->demands->linesFor($specs, $rules->stockDateAt($now)));
        $locationId = $this->locations->resolveDefaultForStore($storeId);

        return $this->demands->shortfalls($demands, $this->availableForDemands($locationId, $demands));
    }

    /**
     * What the till shows (GET /stock/availability): the store's rules, the current stock date and, for every active tracked product and every
     * DAILY dish, what is left at the default location. RUNNING items carry their low-stock state ("last 3"); DAILY items and dishes never do (they
     * just sell out, client decision 9). A product linked to a dish carries `dish_id` and `stock_portion`; its `remaining` is whole units of the
     * product that the dish's remaining portions cover. Advisory; the server decides at checkout.
     *
     * @return array{blocking: bool, override_available: bool, low_stock_default_threshold: int, stock_date: string, daily_configured: bool, daily_entered: bool, items: list<array<string, mixed>>, dishes: list<array<string, mixed>>}
     */
    public function forTill(string $storeId): array
    {
        $rules = StockRules::forStore($storeId);
        $stockDate = $rules->stockDateAt(Carbon::now());

        try {
            $locationId = $this->locations->resolveDefaultForStore($storeId);
        } catch (InventoryLocationResolutionException) {
            $locationId = null;
        }

        $items = [];
        $dishes = [];
        $dailyConfigured = false;
        $dailyEntered = false;
        if ($locationId !== null) {
            $rowsQuery = DB::table('products')
                ->leftJoin('stock_balances', fn ($join) => $join->on('stock_balances.product_id', '=', 'products.id')->where('stock_balances.location_id', $locationId))
                ->leftJoin('daily_stock_balances', fn ($join) => $join->on('daily_stock_balances.product_id', '=', 'products.id')
                    ->where('daily_stock_balances.location_id', $locationId)->where('daily_stock_balances.stock_date', $stockDate))
                ->where('products.store_id', $storeId)
                ->whereIn('products.stock_mode', [Product::MODE_RUNNING, Product::MODE_DAILY])
                ->where('products.active', true)
                ->where('products.menu_hidden', false)
                ->orderBy('products.name')->orderBy('products.id')
                ->select(['products.id', 'products.reorder_level', 'products.stock_mode', 'products.dish_id', 'products.stock_portion', 'products.shelf_life_days', 'stock_balances.quantity_on_hand', 'daily_stock_balances.quantity_on_hand as daily_on_hand']);
            StockFreshness::select($rowsQuery); // ADR-017 slice C: age of the latest receipt, in the same read
            $rows = $rowsQuery->get();

            $dishRows = DB::table('dishes')
                ->leftJoin('daily_stock_balances', fn ($join) => $join->on('daily_stock_balances.product_id', '=', 'dishes.stock_product_id')
                    ->where('daily_stock_balances.location_id', $locationId)->where('daily_stock_balances.stock_date', $stockDate))
                ->where('dishes.store_id', $storeId)->where('dishes.stock_mode', 'DAILY')->where('dishes.active', true)
                ->orderBy('dishes.name')->orderBy('dishes.id')
                ->get(['dishes.id', 'dishes.name', 'daily_stock_balances.quantity_on_hand as daily_on_hand']);

            $dishRemaining = [];
            foreach ($dishRows as $dish) {
                $dailyConfigured = true;
                $dailyEntered = $dailyEntered || $dish->daily_on_hand !== null;
                $remaining = bcadd((string) ($dish->daily_on_hand ?? '0'), '0', 3);
                $dishRemaining[$dish->id] = $remaining;
                $dishes[] = ['dish_id' => $dish->id, 'name' => $dish->name, 'remaining' => $remaining, 'sold_out' => bccomp($remaining, '0', 3) <= 0];
            }

            foreach ($rows as $row) {
                if ($row->stock_mode === Product::MODE_DAILY) {
                    $dailyConfigured = true;
                    if ($row->dish_id !== null) {
                        if (! isset($dishRemaining[$row->dish_id])) {
                            continue; // linked to a dish that is hidden or no longer counts daily stock: nothing to show or block
                        }
                        $portion = bcadd((string) $row->stock_portion, '0', 3);
                        $units = bccomp($dishRemaining[$row->dish_id], '0', 3) > 0 ? bcdiv($dishRemaining[$row->dish_id], $portion, 0) : '0';
                        $items[] = [
                            'product_id' => $row->id, 'mode' => 'DAILY', 'dish_id' => $row->dish_id, 'stock_portion' => $portion,
                            'remaining' => bcadd($units, '0', 3), 'threshold' => 0, 'low' => false, 'sold_out' => bccomp($units, '1', 3) < 0,
                        ];

                        continue;
                    }
                    $dailyEntered = $dailyEntered || $row->daily_on_hand !== null;
                    $remaining = bcadd((string) ($row->daily_on_hand ?? '0'), '0', 3);
                    $items[] = [
                        'product_id' => $row->id, 'mode' => 'DAILY', 'dish_id' => null, 'stock_portion' => null,
                        'remaining' => $remaining, 'threshold' => 0, 'low' => false, 'sold_out' => bccomp($remaining, '0', 3) <= 0,
                    ];

                    continue;
                }

                $remaining = bcadd((string) ($row->quantity_on_hand ?? '0'), '0', 3);
                $threshold = $rules->thresholdFor($row->reorder_level === null ? null : (int) $row->reorder_level);
                $items[] = [
                    'product_id' => $row->id,
                    'mode' => 'RUNNING',
                    'dish_id' => null,
                    'stock_portion' => null,
                    'remaining' => $remaining,
                    'threshold' => $threshold,
                    'low' => bccomp($remaining, '0', 3) > 0 && bccomp($remaining, (string) $threshold, 3) <= 0,
                    'sold_out' => bccomp($remaining, '0', 3) <= 0,
                    // Advisory (never blocks): true when a shelf life is set, stock remains and the latest receipt is older than it.
                    'check_freshness' => StockFreshness::flag($row->shelf_life_days, $remaining, $row->last_received_at, $row->freshness_days_old) !== null,
                ];
            }
        }

        return [
            'blocking' => $rules->blockSaleWithoutStock,
            'override_available' => $rules->overrideAvailable(),
            'low_stock_default_threshold' => $rules->defaultThreshold,
            'stock_date' => $stockDate,
            'daily_configured' => $dailyConfigured,
            'daily_entered' => $dailyEntered,
            'items' => $items,
            'dishes' => $dishes,
        ];
    }
}
