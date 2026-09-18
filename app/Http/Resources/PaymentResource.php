<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml Payment schema.
 *
 * @property Payment $resource
 */
class PaymentResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'method' => $this->resource->method,
            'amount' => $this->resource->amount,
            'reference_note' => $this->resource->reference_note,
            'recorded_at' => $this->resource->recorded_at?->toJSON(),
        ];
    }
}
