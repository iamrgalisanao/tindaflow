<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** billingChequeReturn: the date the bank returned the cheque (Manila calendar, not in the future) and a reason. */
class BillingChequeReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'returned_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the cheque came back (for example "Insufficient funds").',
            'reason.min' => 'Say why the cheque came back (for example "Insufficient funds").',
            'returned_on.date_format' => 'Enter the date as year-month-day, such as 2026-10-30.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $timezone = (string) config('app.timezone');
            $on = CarbonImmutable::createFromFormat('!Y-m-d', $validator->getData()['returned_on'], $timezone);
            if ($on === false || $on->format('Y-m-d') !== $validator->getData()['returned_on']) {
                $validator->errors()->add('returned_on', 'That is not a real date.');
            } elseif ($on->greaterThan(CarbonImmutable::now($timezone)->startOfDay())) {
                $validator->errors()->add('returned_on', 'The return date cannot be in the future.');
            }
        });
    }
}
