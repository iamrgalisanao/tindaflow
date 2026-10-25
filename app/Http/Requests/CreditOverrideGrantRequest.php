<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml CreditOverrideGrantRequest: the till names the approver, sends the code they typed, and says which employee and how much
 * (the sale total it is about to charge). The terminal and the cashier are NEVER read from the body; they come from the authoritative
 * context. Shape only: whether the code is right is the service's business and always the same generic answer.
 */
class CreditOverrideGrantRequest extends FormRequest
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
            'employee_id' => ['required', 'uuid'],
            'amount' => ['required', 'regex:/^\d{1,10}\.\d{2}$/', 'not_regex:/^0+\.00$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Type the 6 to 8 digit code.',
            'amount.regex' => 'The amount must be in pesos with two decimals, such as 75.00.',
            'amount.not_regex' => 'The amount must be more than zero.',
        ];
    }
}
