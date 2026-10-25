<?php

namespace App\Http\Resources;

use App\Models\CreditPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CreditPolicy schema (openapi.yaml): the current limit per cut-off of one employee type.
 *
 * @property CreditPolicy $resource
 */
class CreditPolicyResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $policy = $this->resource;

        return [
            'employee_type' => $policy->employee_type->value,
            'limit_per_cutoff' => (string) $policy->limit_per_cutoff,
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }
}
