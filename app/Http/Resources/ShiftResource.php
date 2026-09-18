<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml Shift schema.
 *
 * @property Shift $resource
 */
class ShiftResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'cashier_id' => $this->resource->cashier_id,
            'opening_cash' => $this->resource->opening_cash,
            'opened_at' => $this->resource->opened_at?->toJSON(),
            'status' => $this->resource->status,
            'expected_cash' => $this->resource->expected_cash,
            'declared_cash' => $this->resource->declared_cash,
            'variance' => $this->resource->variance,
            'cash_sales' => $this->resource->cash_sales,
            'non_cash_sales' => $this->resource->non_cash_sales,
            'refunds_total' => $this->resource->refunds_total,
            'cash_in_total' => $this->resource->cash_in_total,
            'cash_out_total' => $this->resource->cash_out_total,
            'closed_at' => $this->resource->closed_at?->toJSON(),
        ];
    }
}
