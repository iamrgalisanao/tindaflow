<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml SaleDocumentModeInput. The capability (FISCAL_CONFIGURATION_MANAGE) is checked by the route. */
class SaleDocumentModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['sale_document_mode' => ['required', 'string', 'in:INVOICE,SLIP']];
    }
}
