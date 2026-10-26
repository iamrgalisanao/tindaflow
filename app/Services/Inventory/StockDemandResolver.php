<?php

namespace App\Services\Inventory;

use App\Models\Product;

/**
 * What a sale demands from stock (ADR-017 section 5), and whether the stock on hand covers it.
 *
 * Slice A covers RUNNING stock only: every sale line of a product with `track_inventory = true` demands its quantity of that product, and
 * the demands of one product are ADDED UP across the cart (two lines of the same product, or the same product scanned twice, are one
 * demand) before they are compared with what is on hand. A meal line is sold through a hidden backing product with `track_inventory =
 * false` and an add-on (rice) likewise, so neither demands anything. Slice B extends this class with daily dish pools and portions; the
 * shape of a demand and of a shortfall already carries `kind`, `mode` and `stock_date` for it.
 *
 * A product with no stock record counts as ZERO on hand. These are pure functions of their arguments: reading the balances (unlocked for
 * the early check, locked inside the transaction for the decisive one) is the caller's business.
 */
final class StockDemandResolver
{
    /**
     * @param  iterable<array{product: Product, quantity: string}>  $lines  one entry per sale line
     * @return array<string, array{product: Product, required: string}> the aggregated demand per tracked product id, in the order first seen
     */
    public function fromLines(iterable $lines): array
    {
        $demands = [];
        foreach ($lines as $line) {
            $product = $line['product'];
            if (! $product->track_inventory) {
                continue;
            }
            $quantity = bcadd((string) $line['quantity'], '0', 3);
            $demands[$product->id] = [
                'product' => $product,
                'required' => bcadd($demands[$product->id]['required'] ?? '0', $quantity, 3),
            ];
        }

        return $demands;
    }

    /**
     * @param  array<string, array{product: Product, required: string}>  $demands
     * @param  array<string, string>  $available  on-hand quantity per product id; an id that is missing counts as zero
     * @return list<array{kind: string, product_id: string, name: string, mode: string, stock_date: null, required: string, available: string}>
     */
    public function shortfalls(array $demands, array $available): array
    {
        $short = [];
        foreach ($demands as $productId => $demand) {
            $onHand = bcadd($available[$productId] ?? '0', '0', 3);
            if (bccomp($onHand, $demand['required'], 3) < 0) {
                $short[] = [
                    'kind' => 'PRODUCT',
                    'product_id' => $productId,
                    'name' => $demand['product']->name,
                    'mode' => 'RUNNING',
                    'stock_date' => null,
                    'required' => $demand['required'],
                    'available' => $onHand,
                ];
            }
        }

        return $short;
    }

    /**
     * SHA-256 of the canonical (sorted by id) short lines: product id and the quantity required. What an override grant is bound to, so
     * changing the cart changes the hash. `available` is left out on purpose: the approval is for "this cart is short of these items",
     * not for the exact count at that second.
     *
     * @param  list<array<string, mixed>>  $shortfalls
     */
    public function hash(array $shortfalls): string
    {
        $lines = array_map(fn (array $line): string => $line['kind'].':'.$line['product_id'].':'.($line['stock_date'] ?? '-').':'.$line['required'], $shortfalls);
        sort($lines, SORT_STRING);

        return hash('sha256', implode('|', $lines));
    }
}
