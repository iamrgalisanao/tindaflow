<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Services\Checkout\ResolvedMealLine;

/**
 * What a sale demands from stock (ADR-017 section 5), and whether the stock on hand covers it.
 *
 * A sale is first turned into STOCK LINES, one per thing that takes stock, each pointing back at the sale line (or meal selection) it belongs to:
 *
 *   product, mode NONE                    a line with no demand (the movement is still written, as it always was, for the hidden meal backing products)
 *   product, RUNNING                      (product, no date, quantity)
 *   product, DAILY, no dish               (product, stock date, quantity): a snack cooked daily is its own pool
 *   product linked to a DAILY dish        (the dish's pool product, stock date, quantity x stock_portion); the ala carte product's own balance is never
 *                                         written (client decision 2: every dish can be sold alone, at 2.0 portions by default)
 *   meal line                             the backing product as above (NONE), plus one line per CHOICE selection whose dish is DAILY:
 *                                         (the dish's pool, stock date, meal quantity x the option's stock_portion). Add-ons (rice) draw nothing.
 *
 * The DEMANDS are the stock lines that have a mode other than NONE, ADDED UP per stock row (a pool product and a stock date, or a running product)
 * across the whole cart: a meal's main, an ala carte portion of the same dish and a Combo side all pool together (client decision 10). A row with no
 * record counts as ZERO on hand. These are pure functions of their arguments: reading the balances (unlocked for the early check, locked inside
 * the transaction for the decisive one) is the caller's business.
 */
final class StockDemandResolver
{
    /**
     * The stock lines of a cart, in sale-line order.
     *
     * @param  iterable<array{product: Product, quantity: string, meal?: ?ResolvedMealLine}>  $specs  one entry per sale line, in line order
     * @param  string|null  $stockDate  the sale's stock date; a DAILY line needs it
     * @return list<array{spec: int, selection: ?int, product_id: string, stock_date: ?string, quantity: string, mode: string, kind: string, name: string, dish_id: ?string, portion: ?string}>
     */
    public function linesFor(iterable $specs, ?string $stockDate): array
    {
        $lines = [];
        $index = -1;
        foreach ($specs as $spec) {
            $index++;
            $product = $spec['product'];
            $quantity = bcadd((string) $spec['quantity'], '0', 3);
            $mode = self::modeOf($product);

            if ($mode === Product::MODE_DAILY && $product->dish_id !== null) {
                $dish = $product->dish;
                if ($dish !== null && $dish->stock_mode === 'DAILY' && $dish->stock_product_id !== null) {
                    $portion = bcadd((string) $product->stock_portion, '0', 3);
                    $lines[] = [
                        'spec' => $index, 'selection' => null, 'product_id' => $dish->stock_product_id, 'stock_date' => $stockDate,
                        'quantity' => bcmul($quantity, $portion, 3), 'mode' => Product::MODE_DAILY, 'kind' => 'DISH', 'name' => $dish->name, 'dish_id' => $dish->id, 'portion' => $portion,
                    ];
                }

                continue;
            }

            $lines[] = [
                'spec' => $index, 'selection' => null, 'product_id' => $product->id, 'stock_date' => $mode === Product::MODE_DAILY ? $stockDate : null,
                'quantity' => $quantity, 'mode' => $mode, 'kind' => 'PRODUCT', 'name' => $product->name, 'dish_id' => null, 'portion' => null,
            ];

            foreach (($spec['meal'] ?? null)?->selections ?? [] as $selectionIndex => $selection) {
                if (($selection['stock_product_id'] ?? null) === null) {
                    continue;
                }
                $portion = bcadd((string) $selection['stock_portion'], '0', 3);
                $lines[] = [
                    'spec' => $index, 'selection' => $selectionIndex, 'product_id' => $selection['stock_product_id'], 'stock_date' => $stockDate,
                    'quantity' => bcmul($quantity, $portion, 3), 'mode' => Product::MODE_DAILY, 'kind' => 'DISH', 'name' => (string) $selection['dish_name'], 'dish_id' => $selection['dish_id'], 'portion' => $portion,
                ];
            }
        }

        return $lines;
    }

    /**
     * The aggregated demand per stock row, in the order first seen. Keyed by {@see StockLedger::rowKey()}.
     *
     * @param  list<array<string, mixed>>  $lines  {@see linesFor()}
     * @return array<string, array{kind: string, product_id: string, dish_id: ?string, name: string, mode: string, stock_date: ?string, required: string}>
     */
    public function demandsFrom(array $lines): array
    {
        $demands = [];
        foreach ($lines as $line) {
            if ($line['mode'] === Product::MODE_NONE) {
                continue;
            }
            $key = StockLedger::rowKey($line['product_id'], $line['stock_date']);
            $demands[$key] = [
                'kind' => $line['kind'],
                'product_id' => $line['product_id'],
                'dish_id' => $line['dish_id'],
                'name' => $line['name'],
                'mode' => $line['mode'],
                'stock_date' => $line['stock_date'],
                'required' => bcadd($demands[$key]['required'] ?? '0', $line['quantity'], 3),
            ];
        }

        return $demands;
    }

    /**
     * The demand of plain lines (no meal selections). Kept for callers that have only products.
     *
     * @param  iterable<array{product: Product, quantity: string}>  $lines
     * @return array<string, array{kind: string, product_id: string, dish_id: ?string, name: string, mode: string, stock_date: ?string, required: string}>
     */
    public function fromLines(iterable $lines, ?string $stockDate = null): array
    {
        return $this->demandsFrom($this->linesFor($lines, $stockDate));
    }

    /**
     * @param  array<string, array{kind: string, product_id: string, dish_id: ?string, name: string, mode: string, stock_date: ?string, required: string}>  $demands
     * @param  array<string, string>  $available  on-hand quantity per {@see StockLedger::rowKey()}; a key that is missing counts as zero
     * @return list<array{kind: string, product_id: string, dish_id: ?string, name: string, mode: string, stock_date: ?string, required: string, available: string}>
     */
    public function shortfalls(array $demands, array $available): array
    {
        $short = [];
        foreach ($demands as $key => $demand) {
            $onHand = bcadd($available[$key] ?? '0', '0', 3);
            if (bccomp($onHand, $demand['required'], 3) < 0) {
                $short[] = $demand + ['available' => $onHand];
            }
        }

        return $short;
    }

    /**
     * SHA-256 of the canonical (sorted) short lines: kind, stock row and the quantity required. What an override grant is bound to, so
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

    /** A product's stock mode, also for a model that was never saved (no `stock_mode` attribute yet). */
    public static function modeOf(Product $product): string
    {
        return $product->stock_mode ?? ($product->track_inventory ? Product::MODE_RUNNING : Product::MODE_NONE);
    }
}
