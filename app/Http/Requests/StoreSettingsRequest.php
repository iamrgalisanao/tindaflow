<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml StoreSettingsInput -- a partial update: only the fields sent are changed. Lengths follow the
 * columns. Values are only checked for shape, never for what a tax identifier "should" look like: no TIN
 * format or checksum is invented here (BIR-006 is still open). A blank optional value (which the framework
 * turns into null) clears it; the three identity fields can never be blanked. Whether the three identity
 * fields must all be present when the settings are first created is the service's call, since it depends on
 * whether a row exists.
 */
class StoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'registered_name' => ['sometimes', 'required', 'string', 'max:255'],
            'tin' => ['sometimes', 'required', 'string', 'max:50'],
            'business_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'invoice_header' => ['sometimes', 'nullable', 'string', 'max:500'],
            'invoice_footer' => ['sometimes', 'nullable', 'string', 'max:500'],
            'telephone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'business_name.required' => 'The business name cannot be blank.',
            'registered_name.required' => 'The registered name cannot be blank.',
            'tin.required' => 'The TIN cannot be blank.',
        ];
    }
}
