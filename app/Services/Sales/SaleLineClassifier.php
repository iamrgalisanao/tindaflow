<?php

namespace App\Services\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 slice 4.1 / 4.4: the one rule that puts a sale line under a MEAL TYPE and names its ITEM, shared by the billing statement and the
 * end-of-day sales-by-meal-type section so the two can never disagree about where a line belongs. It reads only the immutable sale snapshots
 * (`sale_items` and `sale_item_selections`), never the live menu, so a renamed or deleted dish does not move an old line.
 *
 *   Meal type of a line  = the meal's name snapshot ("Standard Meal") for a meal line AND for its add-on lines (rice sits under the meal it was
 *                          bought with); for a plain product the menu-group path snapshot ("Lunch > Drinks") when it sat in a menu group;
 *                          otherwise "Other items".
 *   Item of a line       = a meal line: the chosen dishes' name snapshots joined with " + " (a Combo is "Beef nilaga + Pinakbet"; the selection
 *                          snapshots, falling back to the line's name snapshot), kind DISH; an add-on line: its option label snapshot
 *                          ("Whole rice"), kind ADDON, counted as an item of its own; a plain product: its product name snapshot, kind PRODUCT.
 *   Amount               = sale_items.net_line_amount (after every discount; the lines of a sale add up to its grand total, DISC-006).
 *
 * @phpstan-type Line array{sale_item_id: string, meal_type: string, kind: string, item: string, quantity: string, amount: string}
 */
final class SaleLineClassifier
{
    public const OTHER_ITEMS = 'Other items';

    /** Display order of the kinds inside one meal type: the dishes first, then plain products, then add-ons. */
    public const KIND_ORDER = ['DISH' => 0, 'PRODUCT' => 1, 'ADDON' => 2];

    private const CHUNK = 1000;

    /**
     * Every line of the given sales, classified.
     *
     * @param  list<string>  $saleIds
     * @return array{bySale: array<string, list<array<string, mixed>>>, byId: array<string, array<string, mixed>>}
     */
    public function forSales(array $saleIds): array
    {
        return $this->load($saleIds, 'sale_id');
    }

    /**
     * The given sale lines (a refund names lines of sales from any day), classified.
     *
     * @param  list<string>  $saleItemIds
     * @return array{bySale: array<string, list<array<string, mixed>>>, byId: array<string, array<string, mixed>>}
     */
    public function forItems(array $saleItemIds): array
    {
        return $this->load($saleItemIds, 'id');
    }

    /**
     * @param  list<string>  $ids
     * @return array{bySale: array<string, list<array<string, mixed>>>, byId: array<string, array<string, mixed>>}
     */
    private function load(array $ids, string $column): array
    {
        $bySale = [];
        $byId = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $items = DB::table('sale_items')->whereIn($column, $chunk)->orderBy('sale_id')->orderBy('line_number')
                ->get(['id', 'sale_id', 'line_number', 'product_name_snapshot', 'quantity', 'net_line_amount', 'menu_group_path_snapshot', 'meal_name_snapshot', 'parent_line_number']);
            $selections = DB::table('sale_item_selections')->whereIn('sale_item_id', $items->pluck('id')->all())
                ->orderBy('sale_item_id')->orderBy('sort_order')->orderBy('created_at')->orderBy('id')
                ->get(['sale_item_id', 'option_label_snapshot', 'dish_name_snapshot'])->groupBy('sale_item_id');

            foreach ($items as $item) {
                $line = $this->classify($item, $selections[$item->id] ?? collect());
                $bySale[$item->sale_id][] = $line;
                $byId[$item->id] = $line;
            }
        }

        return ['bySale' => $bySale, 'byId' => $byId];
    }

    /**
     * @param  Collection<int, object>  $selections
     * @return array<string, mixed>
     */
    private function classify(object $item, Collection $selections): array
    {
        $mealName = $item->meal_name_snapshot;
        $mealType = $mealName !== null && $mealName !== '' ? $mealName : (($item->menu_group_path_snapshot ?? '') !== '' ? $item->menu_group_path_snapshot : self::OTHER_ITEMS);

        if ($item->parent_line_number !== null) {
            $kind = 'ADDON';
            $label = $selections->pluck('option_label_snapshot')->filter()->first() ?? $item->product_name_snapshot;
        } elseif ($mealName !== null && $mealName !== '') {
            $kind = 'DISH';
            $dishes = $selections->pluck('dish_name_snapshot')->filter()->all();
            $label = $dishes === [] ? $item->product_name_snapshot : implode(' + ', $dishes);
        } else {
            $kind = 'PRODUCT';
            $label = $item->product_name_snapshot;
        }

        return [
            'sale_item_id' => $item->id,
            'meal_type' => $mealType,
            'kind' => $kind,
            'item' => $label,
            'quantity' => bcadd((string) $item->quantity, '0', 3),
            'amount' => bcadd((string) $item->net_line_amount, '0', 2),
        ];
    }
}
