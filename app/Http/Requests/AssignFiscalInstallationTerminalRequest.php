<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml fiscalInstallationAssignTerminal request body -- {terminal_id, effective_from?}. */
class AssignFiscalInstallationTerminalRequest extends FormRequest
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
            'effective_from' => ['nullable', 'date'],
        ];
    }
}
