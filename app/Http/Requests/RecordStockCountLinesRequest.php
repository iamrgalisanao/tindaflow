<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * stockCountLinesRecord body. A counted quantity may be zero (the shelf really is empty), unlike a receipt or
 * transfer quantity, and each product appears once per request.
 */
class RecordStockCountLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'uuid', 'distinct'],
            'lines.*.counted_quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.*.counted_quantity.regex' => 'The counted quantity must be zero or a positive number with up to three decimal places, e.g. 0, 12 or 2.5.',
            'lines.*.product_id.distinct' => 'A product can appear only once in a request.',
        ];
    }
}
