<?php

namespace App\Http\Resources;

use App\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml CashMovement schema.
 *
 * @property CashMovement $resource
 */
class CashMovementResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'shift_id' => $this->resource->shift_id,
            'type' => $this->resource->type,
            'amount' => $this->resource->amount,
            'reason' => $this->resource->reason,
            'authorized_by' => $this->resource->authorized_by,
            'created_at' => $this->resource->created_at?->toJSON(),
        ];
    }
}
