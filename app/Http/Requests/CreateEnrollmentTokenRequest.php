<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml terminalCreateEnrollmentToken request body -- exactly {terminal_id}. */
class CreateEnrollmentTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'terminal_id' => ['required', 'uuid'],
        ];
    }
}
