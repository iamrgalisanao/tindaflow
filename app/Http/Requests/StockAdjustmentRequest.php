<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml inventoryAdjustmentCreate request body. `reason` is deliberately not `required` here:
 * a missing or blank reason is the domain's own STOCK_ADJUSTMENT_REASON_REQUIRED (invariant #46), not a
 * generic shape error, so StockService raises it.
 */
class StockAdjustmentRequest extends FormRequest
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
            'movement_type' => ['required', Rule::in(['STOCK_ADJUSTMENT_IN', 'STOCK_ADJUSTMENT_OUT', 'DAMAGE', 'EXPIRED'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'The quantity must be a positive number with up to three decimal places, e.g. 12 or 2.5.',
            'quantity.gt' => 'The quantity must be greater than zero.',
        ];
    }
}
