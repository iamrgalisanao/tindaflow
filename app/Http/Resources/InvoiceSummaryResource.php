<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml InvoiceSummary schema.
 *
 * @property Invoice $resource
 */
class InvoiceSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'invoice_number' => $this->resource->invoice_number,
            'issued_at' => $this->resource->issued_at?->toJSON(),
            'schema_version' => $this->resource->invoice_snapshot_json['schema_version'] ?? null,
        ];
    }
}
