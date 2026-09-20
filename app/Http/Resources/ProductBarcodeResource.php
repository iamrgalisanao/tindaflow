<?php

namespace App\Http\Resources;

use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml ProductBarcode -- an alternate barcode of a product (its own main barcode is `Product.barcode`).
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
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
