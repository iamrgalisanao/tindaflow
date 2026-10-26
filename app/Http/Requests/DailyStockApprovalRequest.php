<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml DailyStockApprovalRequest (ADR-017 slice B): the cashier names a manager and sends the code that manager typed. Shape only: whether
 * the code is right is the service's business and always the same generic answer. The terminal and the cashier are NEVER read from the body.
 */
class DailyStockApprovalRequest extends FormRequest
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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.regex' => 'Type the 6 to 8 digit code.'];
    }
}
