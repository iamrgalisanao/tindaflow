<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body of creditPolicySetLimit: the new limit per cut-off, an amount with at most two decimals (0 means no credit without an override). */
class CreditLimitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit_per_cutoff' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['limit_per_cutoff.regex' => 'Enter an amount in pesos, such as 1500 or 1500.50.'];
    }
}
