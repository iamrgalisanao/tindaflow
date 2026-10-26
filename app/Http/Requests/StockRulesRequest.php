<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml StockRulesInput (ADR-017 section 1). All three values are sent every time; the stored ones are replaced. */
class StockRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'block_sale_without_stock' => ['required', 'boolean'],
            'stock_override_enabled' => ['required', 'boolean'],
            'low_stock_default_threshold' => ['required', 'integer', 'between:0,1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['low_stock_default_threshold.between' => 'Use a whole number from 0 to 1000.'];
    }
}
