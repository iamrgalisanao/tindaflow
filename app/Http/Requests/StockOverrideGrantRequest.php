<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml StockOverrideGrantRequest (ADR-017 section 9): the till names the approver, sends the code they typed and the cart as the
 * sale request would carry it (the server works out the shortfall itself). The terminal and the cashier are NEVER read from the body.
 * Shape only: whether the code is right is the service's business and always the same generic answer.
 */
class StockOverrideGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'approver_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{6,8}$/D'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required_without:items.*.meal', 'nullable', 'uuid'],
            'items.*.meal' => ['nullable', 'array'],
            'items.*.quantity' => ['required', 'regex:/^\d+(\.\d{1,3})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'Type the 6 to 8 digit code.'];
    }
}
