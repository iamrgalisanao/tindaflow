<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml Product schema. `note` documents (never actually
 * serialized as a value) that selling_price/tax_class here are current
 * catalog values for cashier preview only -- never authoritative for a
 * historical sale_item, which carries its own immutable snapshot
 * (already true of CheckoutService, unaffected by this resource).
 *
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'sku' => $this->resource->sku,
            'barcode' => $this->resource->barcode,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'category_id' => $this->resource->category_id,
            'brand_id' => $this->resource->brand_id,
            'unit_of_measure' => $this->resource->unit_of_measure,
            'cost' => $this->resource->cost,
            'selling_price' => $this->resource->selling_price,
            'tax_class' => $this->resource->tax_class,
            'track_inventory' => $this->resource->track_inventory,
            'reorder_level' => $this->resource->reorder_level,
            'active' => $this->resource->active,
        ];
    }
}
