<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * productBarcodeCreate body. Only the shape is checked here (surrounding whitespace, which a scanner's line break
 * leaves, is already trimmed by the framework); whether the code is free is decided inside the store's barcode lock by
 * ProductBarcodeService, so two people adding the same code at once cannot both succeed.
 */
class AddProductBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'barcode' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required' => 'Enter or scan a barcode.',
            'barcode.max' => 'A barcode can be at most 255 characters.',
        ];
    }
}
