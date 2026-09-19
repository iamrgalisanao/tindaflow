<?php

namespace App\Http\Resources;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml SaleSummary -- a row of sales history and the `sale` inside a void or refund result.
 *
 * @property Sale $resource
 */
class SaleSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'uuid' => $this->resource->id,
            'transaction_number' => $this->resource->transaction_number,
            'invoice_number' => $this->resource->invoice?->invoice_number,
            'store_id' => $this->resource->store_id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'shift_id' => $this->resource->shift_id,
            'cashier_id' => $this->resource->cashier_id,
            'sold_at' => $this->resource->sold_at?->toJSON(),
            'grand_total' => $this->resource->grand_total,
            'status' => $this->resource->status,
        ];
    }
}
