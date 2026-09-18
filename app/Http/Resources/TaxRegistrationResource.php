<?php

namespace App\Http\Resources;

use App\Models\TaxRegistration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's TaxRegistration schema.
 *
 * @property TaxRegistration $resource
 */
class TaxRegistrationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'registration_type' => $this->resource->registration_type,
            'effective_from' => $this->resource->effective_from?->toDateString(),
            'effective_to' => $this->resource->effective_to?->toDateString(),
        ];
    }
}
