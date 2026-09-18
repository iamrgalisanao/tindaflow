<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml SaleFinalizeRequest -- structural/shape validation only
 * (VALIDATION_FAILED, 422). Domain/business invariants (product
 * existence, payment sufficiency, etc.) are CheckoutService's own
 * responsibility, per stage-6c-sale-finalization.md's explicit division
 * of labor -- this class must never duplicate them.
 */
class SaleFinalizeRequest extends FormRequest
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
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
            'items.*.line_discount_amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'items.*.order_discount_eligible' => ['nullable', 'boolean'],
            'items.*.override_reason' => ['nullable', 'string'],
            'order_level_discount_amount' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string', 'in:CASH,GCASH,MAYA,CARD,OTHER'],
            'payments.*.amount' => ['required', 'regex:/^-?\d+\.\d{2}$/'],
            'payments.*.external_reference' => ['nullable', 'string'],
            'buyer_name' => ['nullable', 'string'],
            'buyer_address' => ['nullable', 'string'],
            'buyer_tin' => ['nullable', 'string'],
            'buyer_business_style' => ['nullable', 'string'],
            'client_expected_grand_total' => ['nullable', 'regex:/^-?\d+\.\d{2}$/'],
        ];
    }
}
