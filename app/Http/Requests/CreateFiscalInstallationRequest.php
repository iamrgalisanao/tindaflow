<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml fiscalInstallationCreate request body -- FiscalInstallationInput. */
class CreateFiscalInstallationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'deployment_model' => ['required', 'string', 'in:STANDALONE,SERVER_CONNECTED'],
            'machine_serial_number' => ['nullable', 'string'],
            'software_version' => ['required', 'string'],
            'accreditation' => ['nullable', 'array'],
            'accreditation.number' => ['nullable', 'string'],
            'accreditation.date' => ['nullable', 'date'],
            'permit_to_use' => ['nullable', 'array'],
            'permit_to_use.number' => ['nullable', 'string'],
            'permit_to_use.min' => ['nullable', 'string'],
            'permit_to_use.date' => ['nullable', 'date'],
        ];
    }
}
