<?php

namespace App\Http\Resources;

use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml StockBalance -- a projection of the stock ledger, never written to directly.
 *
 * @property StockBalance $resource
 */
class StockBalanceResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->resource->product_id,
            'location_id' => $this->resource->location_id,
            'quantity_on_hand' => $this->resource->quantity_on_hand,
            // The product's own reorder level, or the store's low-stock default when it has none (ADR-017 section 2); null where the row was not read through the product join.
            'low_stock_threshold' => $this->resource->getAttribute('low_stock_threshold') === null ? null : (int) $this->resource->getAttribute('low_stock_threshold'),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
