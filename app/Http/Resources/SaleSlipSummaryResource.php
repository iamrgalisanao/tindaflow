<?php

namespace App\Http\Resources;

use App\Models\SaleSlip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml SaleSlipSummary schema.
 *
 * @property SaleSlip $resource
 */
class SaleSlipSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'slip_number' => $this->resource->slip_number,
            'issued_at' => $this->resource->slip_snapshot_json['issued_at'] ?? $this->resource->created_at?->toJSON(),
            'schema_version' => $this->resource->slip_snapshot_json['schema_version'] ?? null,
        ];
    }
}
