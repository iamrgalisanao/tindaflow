<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml shiftOpen request body -- exactly {opening_cash}.
 * Non-negative only, matching the DB's own
 * `shifts_opening_cash_nonneg_check` -- rejected here as a structural
 * 422 rather than reaching the database as an unhandled constraint
 * violation.
 */
class ShiftOpenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'regex:/^\d+\.\d{2}$/'],
        ];
    }
}
