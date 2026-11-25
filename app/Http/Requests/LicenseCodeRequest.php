<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml licenseCodeApply request body: exactly {code}. A code is 169 characters; the extra room is for spaces and line breaks from a message. */
class LicenseCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:600'],
        ];
    }
}
