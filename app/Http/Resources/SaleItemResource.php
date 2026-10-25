<?php

namespace App\Http\Resources;

use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml SaleItem schema -- the immutable per-line financial
 * snapshot (domain-model.md §2.7).
 *
 * @property SaleItem $resource
 */
class SaleItemResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'line_number' => $this->resource->line_number,
            'product_id' => $this->resource->product_id,
            'product_name_snapshot' => $this->resource->product_name_snapshot,
            'sku_snapshot' => $this->resource->sku_snapshot,
            'barcode_snapshot' => $this->resource->barcode_snapshot,
            'unit_of_measure_snapshot' => $this->resource->unit_of_measure_snapshot,
            'quantity' => $this->resource->quantity,
            'unit_price_snapshot' => $this->resource->unit_price_snapshot,
            'gross_line_amount' => $this->resource->gross_line_amount,
            'line_discount_amount' => $this->resource->line_discount_amount,
            'order_discount_eligible' => $this->resource->order_discount_eligible,
            'allocated_order_discount_amount' => $this->resource->allocated_order_discount_amount,
            'net_line_amount' => $this->resource->net_line_amount,
            'tax_classification_snapshot' => $this->resource->tax_classification_snapshot,
            'tax_rate_snapshot' => $this->resource->tax_rate_snapshot,
            'taxable_base' => $this->resource->taxable_base,
            'tax_amount' => $this->resource->tax_amount,
            // ADR-016 snapshots: present only on lines that carry them, so a sale with no meal and no menu placement
            // serializes exactly as it always did. Every value is the snapshot taken at the sale (MENU-001).
            $this->mergeWhen($this->resource->menu_group_path_snapshot !== null, fn () => ['menu_group_path_snapshot' => $this->resource->menu_group_path_snapshot]),
            $this->mergeWhen($this->resource->meal_id !== null, fn () => [
                'meal_id' => $this->resource->meal_id,
                'meal_name_snapshot' => $this->resource->meal_name_snapshot,
                'parent_line_number' => $this->resource->parent_line_number,
                'selections' => $this->resource->selections->map(fn ($selection) => [
                    'group_name_snapshot' => $selection->group_name_snapshot,
                    'option_label_snapshot' => $selection->option_label_snapshot,
                    'dish_id' => $selection->dish_id,
                    'dish_name_snapshot' => $selection->dish_name_snapshot,
                    'dish_category_snapshot' => $selection->dish_category_snapshot,
                    'price_snapshot' => $selection->price_snapshot,
                    'sort_order' => $selection->sort_order,
                ])->values()->all(),
            ]),
        ];
    }
}
