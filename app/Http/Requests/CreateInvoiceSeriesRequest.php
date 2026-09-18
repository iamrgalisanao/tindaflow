<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml invoiceSeriesCreate request body. */
class CreateInvoiceSeriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fiscal_installation_id' => ['required', 'uuid'],
            'series_code' => ['required', 'string', 'max:64'],
            'prefix' => ['nullable', 'string', 'max:8'],
            'starting_number' => ['required', 'integer', 'min:1'],
            'ending_number' => ['nullable', 'integer', 'gte:starting_number'],
        ];
    }
}
