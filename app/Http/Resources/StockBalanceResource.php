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
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
