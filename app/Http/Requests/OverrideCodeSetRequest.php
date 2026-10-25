<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml OverrideCodeSetRequest: the approver's account password (re-entered) and the new code. Strength is OverrideCodePolicy's. */
class OverrideCodeSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
