<?php

namespace App\Http\Requests;

use App\Domain\CutOff;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * billingChequeRecord (ADR-016 section 8, slice 4.2). The cheque is allocated in full to one or more real cut-off periods (the 1st or the
 * 16th is the `period_start`), each at most once, each amount positive, and the allocations add up to the cheque amount exactly: there is
 * no "on account" balance. Whether a period owes that much is decided by the service under the billing party's lock.
 *
 * Dates: `received_on` is the day the cheque reached the canteen and may not be in the future (Manila calendar). `cheque_date` is the date
 * written on the cheque and may be up to a year either side of `received_on`, which allows a post-dated cheque and catches a typo in the year.
 */
class BillingChequeRequest extends FormRequest
{
    /** How far the date written on a cheque may be from the day it was received, in days. */
    public const MAX_DATE_GAP_DAYS = 365;

    public const MAX_ALLOCATIONS = 36;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cheque_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 \-\/]*$/'],
            'bank' => ['required', 'string', 'min:2', 'max:80'],
            'cheque_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/^\d{1,12}\.\d{2}$/'],
            'received_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:500'],
            'allocations' => ['required', 'array', 'min:1', 'max:'.self::MAX_ALLOCATIONS],
            'allocations.*.period_start' => ['required', 'date_format:Y-m-d'],
            'allocations.*.amount' => ['required', 'string', 'regex:/^\d{1,12}\.\d{2}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cheque_number.regex' => 'The cheque number may contain letters, digits, spaces, dashes and slashes only.',
            'amount.regex' => 'Enter the amount in pesos with two decimals, such as 12500.00.',
            'allocations.*.amount.regex' => 'Enter each allocated amount in pesos with two decimals, such as 5000.00.',
            'allocations.required' => 'Say which cut-off periods this cheque pays.',
            'allocations.min' => 'Say which cut-off periods this cheque pays.',
            'cheque_date.date_format' => 'Enter the cheque date as year-month-day, such as 2026-10-30.',
            'received_on.date_format' => 'Enter the date received as year-month-day, such as 2026-10-30.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            $timezone = (string) config('app.timezone');
            $today = CarbonImmutable::now($timezone)->startOfDay();

            if (bccomp($data['amount'], '0', 2) <= 0) {
                $validator->errors()->add('amount', 'The cheque amount must be more than zero.');
            }

            $received = CarbonImmutable::createFromFormat('!Y-m-d', $data['received_on'], $timezone);
            $chequeDate = CarbonImmutable::createFromFormat('!Y-m-d', $data['cheque_date'], $timezone);
            if ($received === false || $received->format('Y-m-d') !== $data['received_on']) {
                $validator->errors()->add('received_on', 'That is not a real date.');
            } elseif ($received->greaterThan($today)) {
                $validator->errors()->add('received_on', 'The date received cannot be in the future.');
            }
            if ($chequeDate === false || $chequeDate->format('Y-m-d') !== $data['cheque_date']) {
                $validator->errors()->add('cheque_date', 'That is not a real date.');
            } elseif ($received instanceof CarbonImmutable && $received->format('Y-m-d') === $data['received_on'] && abs($received->diffInDays($chequeDate)) > self::MAX_DATE_GAP_DAYS) {
                $validator->errors()->add('cheque_date', 'The cheque date is more than a year from the date received. Check the year.');
            }

            $seen = [];
            $sum = '0.00';
            foreach ($data['allocations'] as $index => $allocation) {
                $start = $allocation['period_start'];
                if (CutOff::startingOn($start, $timezone) === null) {
                    $validator->errors()->add("allocations.{$index}.period_start", 'A cut-off period starts on the 1st or the 16th of a month.');
                } elseif (isset($seen[$start])) {
                    $validator->errors()->add("allocations.{$index}.period_start", 'That period is listed twice. Put the whole amount for it on one line.');
                }
                $seen[$start] = true;
                if (bccomp($allocation['amount'], '0', 2) <= 0) {
                    $validator->errors()->add("allocations.{$index}.amount", 'Each allocated amount must be more than zero.');
                }
                $sum = bcadd($sum, $allocation['amount'], 2);
            }
            if ($validator->errors()->isEmpty() && bccomp($sum, $data['amount'], 2) !== 0) {
                $validator->errors()->add('allocations', "The allocations add up to P{$sum} but the cheque is for P{$data['amount']}. They must be equal.");
            }
        });
    }
}
