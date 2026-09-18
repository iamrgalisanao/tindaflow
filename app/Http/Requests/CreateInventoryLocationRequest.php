<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml inventoryLocationCreate request body. */
class CreateInventoryLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
