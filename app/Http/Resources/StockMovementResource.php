<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml StockMovement. `unit_cost` is null where the ledger holds none (adjustments).
 *
 * @property StockMovement $resource
 */
class StockMovementResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'product_id' => $this->resource->product_id,
            'location_id' => $this->resource->location_id,
            'terminal_id' => $this->resource->terminal_id,
            'movement_type' => $this->resource->movement_type,
            'quantity' => $this->resource->quantity,
            'reference_type' => $this->resource->reference_type,
            'reference_id' => $this->resource->reference_id,
            'reason' => $this->resource->reason,
            'unit_cost' => $this->resource->unit_cost,
            'created_by' => $this->resource->created_by,
            'occurred_at' => $this->resource->occurred_at?->toIso8601String(),
        ];
    }
}
