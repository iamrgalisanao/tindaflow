<?php

namespace App\Http\Resources;

use App\Models\SaleVoid;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml VoidSummary. `sale_id` is additive: the schema omits it (RefundSummary has it), which
 * would leave the approvals queue unable to say which sale a pending void is about without one extra
 * request per row. See docs/06-backend/stage-15-sales-history-void-refund.md.
 *
 * @property SaleVoid $resource
 */
class VoidSummaryResource extends JsonResource
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
            'requested_at' => $this->resource->requested_at?->toJSON(),
            'resolved_at' => $this->resource->resolved_at?->toJSON(),
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'shift_id' => $this->resource->shift_id,
        ];
    }
}
