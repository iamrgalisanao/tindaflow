<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * productBarcodeCreate body: a barcode the product can be scanned by and/or a named pack ("Case") that says how many
 * single units one of them holds (stage 29). Only the shape is checked here (surrounding whitespace, which a scanner's
 * line break leaves, is already trimmed by the framework); whether the code or name is free is decided inside the store's
 * barcode lock by ProductBarcodeService, so two people adding the same code at once cannot both succeed.
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
            'barcode' => ['nullable', 'string', 'max:255', 'required_without:name'],
            'name' => ['nullable', 'string', 'max:60', 'required_without:barcode'],
            'units_per_base' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'can_receive' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'barcode.required_without' => 'Enter or scan a barcode, or give this pack a name.',
            'barcode.max' => 'A barcode can be at most 255 characters.',
            'name.required_without' => 'Give this pack a name, or enter a barcode.',
            'name.max' => 'A name can be at most 60 characters.',
            'units_per_base.regex' => 'Units per pack must be a positive number with up to three decimal places, e.g. 6 or 24.',
            'units_per_base.gt' => 'Units per pack must be greater than zero.',
        ];
    }
}
