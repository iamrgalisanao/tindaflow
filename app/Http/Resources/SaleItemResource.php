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
        ];
    }
}
