<?php

namespace App\Http\Requests;

use App\Domain\CutOff;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * billingWriteOffRecord (ADR-016 section 8, slice 4.3): one real cut-off period (the 1st or the 16th), an amount in pesos more than zero,
 * and a required reason. The optional `cheque_id` links the write-off to a returned cheque and `request_id` makes a retry idempotent.
 * Whether the period owes that much, and whether the cheque is returned, is decided by the service under the billing party's lock.
 */
class BillingWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}\.\d{2}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'cheque_id' => ['nullable', 'uuid'],
            'request_id' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter the amount in pesos with two decimals, such as 1500.00.',
            'reason.required' => 'Say why this amount is being written off (for example "TSC will not pay the bounced cheque").',
            'reason.min' => 'Say why this amount is being written off (for example "TSC will not pay the bounced cheque").',
            'period_start.date_format' => 'Enter the start of the period as year-month-day, such as 2026-10-01.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            if (CutOff::startingOn($data['period_start'], (string) config('app.timezone')) === null) {
                $validator->errors()->add('period_start', 'A cut-off period starts on the 1st or the 16th of a month.');
            }
            if (bccomp($data['amount'], '0', 2) <= 0) {
                $validator->errors()->add('amount', 'The amount to write off must be more than zero.');
            }
        });
    }
}
