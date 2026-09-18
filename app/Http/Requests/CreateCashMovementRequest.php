<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml shiftCashMovementCreate request body -- {type, amount, reason}. */
class CreateCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:CASH_IN,CASH_OUT'],
            'amount' => ['required', 'regex:/^\d+\.\d{2}$/', 'gt:0'],
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
