<?php

namespace App\Http\Resources;

use App\Models\InvoiceSeries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property InvoiceSeries $resource
 */
class InvoiceSeriesResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'fiscal_installation_id' => $this->resource->fiscal_installation_id,
            'series_code' => $this->resource->series_code,
            'prefix' => $this->resource->prefix,
            'current_number' => $this->resource->current_number,
            'starting_number' => $this->resource->starting_number,
            'ending_number' => $this->resource->ending_number,
            'status' => $this->resource->status,
        ];
    }
}
