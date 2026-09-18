<?php

namespace App\Http\Resources;

use App\Models\FiscalDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml FiscalDay schema.
 *
 * @property FiscalDay $resource
 */
class FiscalDayResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'store_id' => $this->resource->store_id,
            'terminal_id' => $this->resource->terminal_id,
            'business_date' => $this->resource->business_date?->toDateString(),
            'opened_at' => $this->resource->opened_at?->toJSON(),
            'closed_at' => $this->resource->closed_at?->toJSON(),
            'status' => $this->resource->status,
        ];
    }
}
