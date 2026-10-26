<?php

namespace App\Http\Requests;

use App\Enums\EmployeeType;
use App\Services\Billing\BillingCriteria;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parameters of billingStatementGet (query) and billingRunCreate (body): the same thing, so one class. A range is EITHER a named cut-off
 * (`cutoff_month` YYYY-MM and `cutoff_half` 1 = the 1st to the 15th, 2 = the 16th to the end of the month) OR `from` and `to` (Manila
 * calendar dates, inclusive); `from` may not be after `to` and the range may not exceed BillingCriteria::MAX_DAYS (366) days.
 */
class BillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cutoff_month' => ['nullable', 'date_format:Y-m', 'required_with:cutoff_half'],
            'cutoff_half' => ['nullable', Rule::in(['1', '2', 1, 2]), 'required_with:cutoff_month'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from'],
            'employee_type' => ['nullable', Rule::in(EmployeeType::values())],
            'employee_id' => ['nullable', 'uuid'],
            'as_of' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cutoff_month.date_format' => 'Pick the month as year and month, such as 2026-10.',
            'cutoff_half.in' => 'The half must be 1 (the 1st to the 15th) or 2 (the 16th to the end of the month).',
            'from.date_format' => 'Enter the first date as year-month-day, such as 2026-10-01.',
            'to.date_format' => 'Enter the last date as year-month-day, such as 2026-10-15.',
            'employee_type.in' => 'The employee type must be DIRECT or SUBCONTRACTOR.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $named = ! empty($data['cutoff_month']);
            $custom = ! empty($data['from']) || ! empty($data['to']);

            if ($named && $custom) {
                $validator->errors()->add('from', 'Choose a cut-off or a from and to date, not both.');

                return;
            }
            if (! $named && ! $custom) {
                $validator->errors()->add('from', 'Choose a cut-off, or enter a from and a to date.');

                return;
            }
            if ($custom) {
                $from = CarbonImmutable::parse($data['from']);
                $to = CarbonImmutable::parse($data['to']);
                if ($from->greaterThan($to)) {
                    $validator->errors()->add('from', 'The first date cannot be after the last date.');
                } elseif ($from->diffInDays($to) + 1 > BillingCriteria::MAX_DAYS) {
                    $validator->errors()->add('to', 'A statement can cover at most '.BillingCriteria::MAX_DAYS.' days. Choose a shorter range.');
                }
            }
        });
    }
}
