<?php

namespace App\Http\Resources;

use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml ProductBarcode -- a packaging of a product: an alternate barcode and/or a named pack that holds `units_per_base`
 * single units (its own main barcode and unit are `Product.barcode` and `Product.unit_of_measure`). `can_sell` is stored
 * but not exposed: selling by the pack is off until it is switched on per store.
 *
 * @property ProductBarcode $resource
 */
class ProductBarcodeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'barcode' => $this->resource->barcode,
            'name' => $this->resource->name,
            'units_per_base' => $this->resource->units_per_base,
            'can_receive' => $this->resource->can_receive,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
