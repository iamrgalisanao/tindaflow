<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml inventoryReceiptCreate request body. Quantity is a positive decimal with up to three
 * places (NUMERIC(10,3)); unit_cost is Money (two places, NUMERIC(12,2)).
 */
class StockReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'movement_type' => ['required', Rule::in(['OPENING_STOCK', 'PURCHASE_RECEIPT'])],
            'unit_cost' => ['nullable', 'regex:/^\d{1,10}\.\d{2}$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'The quantity must be a positive number with up to three decimal places, e.g. 12 or 2.5.',
            'quantity.gt' => 'The quantity must be greater than zero.',
            'unit_cost.regex' => 'The unit cost must be a decimal with exactly two places, e.g. 40.00.',
        ];
    }
}
