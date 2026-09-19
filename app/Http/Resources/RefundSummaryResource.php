<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml RefundSummary.
 *
 * @property Refund $resource
 */
class RefundSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'sale_id' => $this->resource->sale_id,
            'status' => $this->resource->status,
            'reason' => $this->resource->reason,
            'requested_by' => $this->resource->requested_by,
            'approved_by' => $this->resource->approved_by,
            'refund_total' => $this->resource->refund_total,
            'requested_at' => $this->resource->requested_at?->toJSON(),
            'resolved_at' => $this->resource->resolved_at?->toJSON(),
            'refunded_at' => $this->resource->refunded_at?->toJSON(),
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'shift_id' => $this->resource->shift_id,
        ];
    }
}
