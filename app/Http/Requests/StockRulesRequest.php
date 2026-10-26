<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml StockRulesInput (ADR-017 section 1). The first three values are sent every time; the stored ones are replaced. */
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
            // ADR-017 slice B: the hour (0 to 6) the stock day starts. Left out, the stored hour is kept.
            'stock_day_cutover_hour' => ['sometimes', 'integer', 'between:0,6'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'low_stock_default_threshold.between' => 'Use a whole number from 0 to 1000.',
            'stock_day_cutover_hour.between' => 'Use a whole hour from 0 (midnight) to 6.',
        ];
    }
}
