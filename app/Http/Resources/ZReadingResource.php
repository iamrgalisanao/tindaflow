<?php

namespace App\Http\Resources;

use App\Models\ZReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml ZReading schema.
 *
 * @property ZReading $resource
 */
class ZReadingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'business_date' => $this->resource->business_date?->toDateString(),
            'from_at' => $this->resource->from_at?->toJSON(),
            'to_at' => $this->resource->to_at?->toJSON(),
            'generated_at' => $this->resource->generated_at?->toJSON(),
            'generated_by' => $this->resource->generated_by,
            'totals_snapshot' => $this->resource->totals_snapshot,
        ];
    }
}
