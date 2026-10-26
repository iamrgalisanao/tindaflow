<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads of daily stock (ADR-017 sections 3 and 8), straight from the ledger and its date-keyed cache. Leftover is DERIVED: the remaining balance of
 * a stock date, never a stored row, and nothing is written at close.
 *
 * Two shapes: the entry GRID for one stock date (a row per DAILY dish and per plain DAILY product, with what was cooked, sold and is left, and what
 * was cooked the day before for "copy yesterday"), and the REPORT over a range of stock dates (the same figures per date, plus removed, returned and
 * the oversold flag). A dish is counted through its hidden pool product; a product linked to a dish has no figures of its own.
 */
final class DailyStockQuery
{
    /**
     * @return list<array{kind: string, id: string, pool_product_id: string, name: string, group: ?string, sku: ?string, cooked: string, sold: string, remaining: string, yesterday_cooked: string}>
     */
    public function grid(string $storeId, string $locationId, string $stockDate): array
    {
        $items = $this->items($storeId, true);
        if ($items === []) {
            return [];
        }
        $pools = array_column($items, 'pool_product_id');
        $previous = CarbonImmutable::parse($stockDate)->subDay()->toDateString();

        $sums = DB::table('stock_movements')
            ->where('location_id', $locationId)->whereIn('product_id', $pools)->whereIn('stock_date', [$stockDate, $previous])
            ->groupBy('product_id', 'stock_date')
            ->selectRaw("product_id, stock_date,
                COALESCE(SUM(CASE WHEN movement_type = 'DAILY_PORTIONS_ADDED' THEN quantity WHEN movement_type = 'DAILY_PORTIONS_REMOVED' THEN -quantity ELSE 0 END), 0) AS cooked,
                COALESCE(SUM(CASE WHEN movement_type = 'SALE' THEN quantity WHEN movement_type = 'SALE_RETURN' THEN -quantity ELSE 0 END), 0) AS sold")
            ->get()->groupBy('product_id');
        $balances = DB::table('daily_stock_balances')->where('location_id', $locationId)->where('stock_date', $stockDate)->whereIn('product_id', $pools)
            ->pluck('quantity_on_hand', 'product_id');

        $rows = [];
        foreach ($items as $item) {
            $today = $sums->get($item['pool_product_id'])?->firstWhere('stock_date', $stockDate);
            $yesterday = $sums->get($item['pool_product_id'])?->firstWhere('stock_date', $previous);
            $rows[] = $item + [
                'cooked' => $this->q($today?->cooked),
                'sold' => $this->q($today?->sold),
                'remaining' => $this->q($balances[$item['pool_product_id']] ?? null),
                'yesterday_cooked' => $this->q($yesterday?->cooked),
            ];
        }

        return $rows;
    }

    /**
     * The daily leftover and waste report: one row per stock date and item that had any daily movement in the range.
     *
     * @return list<array{stock_date: string, kind: string, id: string, name: string, group: ?string, sku: ?string, cooked: string, removed: string, sold: string, returned: string, leftover: string, oversold: bool}>
     */
    public function report(string $storeId, string $locationId, string $from, string $to): array
    {
        $rows = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->leftJoin('dishes', 'dishes.stock_product_id', '=', 'products.id')
            ->leftJoin('dish_categories', 'dish_categories.id', '=', 'dishes.dish_category_id')
            ->leftJoin('daily_stock_balances', fn ($join) => $join->on('daily_stock_balances.product_id', '=', 'stock_movements.product_id')
                ->on('daily_stock_balances.location_id', '=', 'stock_movements.location_id')->on('daily_stock_balances.stock_date', '=', 'stock_movements.stock_date'))
            ->where('products.store_id', $storeId)->where('stock_movements.location_id', $locationId)
            ->whereBetween('stock_movements.stock_date', [$from, $to])
            ->groupBy('stock_movements.stock_date', 'stock_movements.product_id', 'products.name', 'products.sku', 'dishes.id', 'dishes.name', 'dish_categories.name', 'daily_stock_balances.quantity_on_hand')
            ->orderBy('stock_movements.stock_date')->orderBy('dish_categories.name')->orderByRaw('COALESCE(dishes.name, products.name)')->orderBy('stock_movements.product_id')
            ->selectRaw("stock_movements.stock_date, stock_movements.product_id, products.name AS product_name, products.sku, dishes.id AS dish_id, dishes.name AS dish_name, dish_categories.name AS category_name, daily_stock_balances.quantity_on_hand AS leftover,
                COALESCE(SUM(CASE WHEN movement_type = 'DAILY_PORTIONS_ADDED' THEN quantity ELSE 0 END), 0) AS cooked,
                COALESCE(SUM(CASE WHEN movement_type = 'DAILY_PORTIONS_REMOVED' THEN quantity ELSE 0 END), 0) AS removed,
                COALESCE(SUM(CASE WHEN movement_type = 'SALE' THEN quantity ELSE 0 END), 0) AS sold,
                COALESCE(SUM(CASE WHEN movement_type = 'SALE_RETURN' THEN quantity ELSE 0 END), 0) AS returned")
            ->get();

        return $rows->map(function ($row): array {
            $leftover = $this->q($row->leftover);

            return [
                'stock_date' => (string) $row->stock_date,
                'kind' => $row->dish_id !== null ? 'DISH' : 'PRODUCT',
                'id' => $row->dish_id ?? $row->product_id,
                'name' => $row->dish_name ?? $row->product_name,
                'group' => $row->category_name,
                'sku' => $row->dish_id !== null ? null : $row->sku,
                'cooked' => $this->q($row->cooked),
                'removed' => $this->q($row->removed),
                'sold' => $this->q($row->sold),
                'returned' => $this->q($row->returned),
                'leftover' => $leftover,
                'oversold' => bccomp($leftover, '0', 3) < 0,
            ];
        })->all();
    }

    /**
     * The items that count daily stock: every active DAILY dish (through its pool product) and every active plain DAILY product.
     *
     * @return list<array{kind: string, id: string, pool_product_id: string, name: string, group: ?string, sku: ?string}>
     */
    public function items(string $storeId, bool $activeOnly = true): array
    {
        $dishes = DB::table('dishes')->join('dish_categories', 'dish_categories.id', '=', 'dishes.dish_category_id')
            ->where('dishes.store_id', $storeId)->where('dishes.stock_mode', 'DAILY')
            ->when($activeOnly, fn ($query) => $query->where('dishes.active', true))
            ->orderBy('dish_categories.sort_order')->orderBy('dish_categories.name')->orderBy('dishes.sort_order')->orderBy('dishes.name')->orderBy('dishes.id')
            ->get(['dishes.id', 'dishes.name', 'dishes.stock_product_id', 'dish_categories.name as category_name']);
        $products = DB::table('products')->where('store_id', $storeId)->where('stock_mode', Product::MODE_DAILY)->whereNull('dish_id')->where('menu_hidden', false)
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'sku']);

        $items = [];
        foreach ($dishes as $dish) {
            $items[] = ['kind' => 'DISH', 'id' => $dish->id, 'pool_product_id' => $dish->stock_product_id, 'name' => $dish->name, 'group' => $dish->category_name, 'sku' => null];
        }
        foreach ($products as $product) {
            $items[] = ['kind' => 'PRODUCT', 'id' => $product->id, 'pool_product_id' => $product->id, 'name' => $product->name, 'group' => null, 'sku' => $product->sku];
        }

        return $items;
    }

    private function q(mixed $value): string
    {
        return bcadd((string) ($value ?? '0'), '0', 3);
    }
}
