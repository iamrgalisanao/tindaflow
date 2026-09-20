<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** stockCountCreate body. Without a location the count is of the store's default location. */
class StartStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
