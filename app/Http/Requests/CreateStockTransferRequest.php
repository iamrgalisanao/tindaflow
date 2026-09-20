<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** stockTransferCreate body: a move between two different locations of the same store. */
class CreateStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_location_id' => ['required', 'uuid'],
            'to_location_id' => ['required', 'uuid', 'different:from_location_id'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to_location_id.different' => 'Choose a different location to move the stock to.',
            'items.*.quantity.regex' => 'The quantity must be a positive number with up to three decimal places, e.g. 12 or 2.5.',
            'items.*.quantity.gt' => 'The quantity must be greater than zero.',
            'items.*.product_id.distinct' => 'A product can appear only once in a transfer.',
        ];
    }
}
