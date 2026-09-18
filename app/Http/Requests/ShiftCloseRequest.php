<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml shiftClose request body -- exactly {declared_cash}, non-negative. */
class ShiftCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'declared_cash' => ['required', 'regex:/^\d+\.\d{2}$/'],
        ];
    }
}
