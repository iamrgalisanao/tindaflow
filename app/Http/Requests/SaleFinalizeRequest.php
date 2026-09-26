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
            // Stage 36 (additive to this frozen request; see
            // docs/06-backend/stage-36-statutory-discount.md): the RA 9994 (Senior Citizen) / RA 10754 (PWD)
            // 20%-plus-VAT-exemption discount. Whichever law is invoked, the beneficiary must be named --
            // CheckoutService computes the amount itself; a client never sends one.
            'statutory_discount' => ['nullable', 'array'],
            'statutory_discount.type' => ['required_with:statutory_discount', 'string', 'in:SENIOR_CITIZEN,PWD'],
            'statutory_discount.id_number' => ['required_with:statutory_discount', 'string', 'max:50'],
            'statutory_discount.name' => ['required_with:statutory_discount', 'string', 'max:150'],
            // Stage 38: which rule applies. Absent = STANDARD_20 (Stage 36); BNPC_5 is DTI-DA-DOE JAO 24-02.
            'statutory_discount.rule' => ['nullable', 'string', 'in:STANDARD_20,BNPC_5'],
            'statutory_discount.weekly_discount_used' => ['nullable', 'regex:/^\d+\.\d{2}$/'],
        ];
    }
}
