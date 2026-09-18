<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml inventoryLocationUpdate request body. `is_default`, when
 * present, must be `true` -- a location is promoted to default (which
 * transactionally demotes the current one), never explicitly demoted on
 * its own, so the store can never be left with zero default locations
 * through this endpoint.
 */
class UpdateInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean', 'accepted'],
        ];
    }
}
