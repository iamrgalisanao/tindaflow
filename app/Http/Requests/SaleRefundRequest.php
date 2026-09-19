<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml RefundRequest -- shape only. Which lines belong to the sale, what remains refundable
 * and whether the settlements add up to the derived total are the refund service's job. No amount is
 * accepted for a line: it is derived from the sale_item snapshot, never from the client.
 */
class SaleRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.sale_item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'items.*.disposition' => ['required', Rule::in(['RETURN_TO_STOCK', 'DAMAGED', 'EXPIRED', 'DISPOSED'])],
            'settlements' => ['required', 'array', 'min:1'],
            'settlements.*.payment_method' => ['required', Rule::in(['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'])],
            'settlements.*.amount' => ['required', 'regex:/^\d{1,10}\.\d{2}$/', 'gt:0'],
            'settlements.*.external_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.*.quantity.regex' => 'The quantity must be a positive number with up to three decimal places.',
            'items.*.quantity.gt' => 'The quantity must be greater than zero.',
            'items.*.sale_item_id.distinct' => 'Each line can appear only once; combine the quantities.',
            'items.*.disposition.in' => 'Choose what happens to the returned item: RETURN_TO_STOCK, DAMAGED, EXPIRED or DISPOSED.',
            'settlements.*.amount.regex' => 'The amount must be a decimal with exactly two places, e.g. 40.00.',
            'settlements.*.amount.gt' => 'The amount must be greater than zero.',
        ];
    }
}
