<?php

namespace App\Http\Resources;

use App\Models\XReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml XReading schema.
 *
 * @property XReading $resource
 */
class XReadingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_id' => $this->resource->terminal_id,
            'shift_id' => $this->resource->shift_id,
            'cashier_id' => $this->resource->cashier_id,
            'from_at' => $this->resource->from_at?->toJSON(),
            'to_at' => $this->resource->to_at?->toJSON(),
            'generated_at' => $this->resource->generated_at?->toJSON(),
            'generated_by' => $this->resource->generated_by,
            'is_closing_reading' => $this->resource->is_closing_reading,
            'totals_snapshot' => $this->resource->totals_snapshot,
        ];
    }
}
