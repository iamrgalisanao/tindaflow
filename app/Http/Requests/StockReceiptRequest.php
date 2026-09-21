<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml inventoryReceiptCreate request body. Quantity is a positive decimal with up to three
 * places (NUMERIC(10,3)); unit_cost is Money (two places, NUMERIC(12,2)).
 *
 * Stage 29 adds a second, additive way to say how much arrived: `packaging_id` (one of the product's packs), `packs`
 * (how many of them) and an optional `pack_cost` (what one pack cost). The server does the conversion to single units
 * and the unit cost, so those replace `quantity` and `unit_cost` rather than sit beside them, and the plain form is
 * exactly as it was. Recorded in docs/06-backend/stage-29-packaging-and-pack-receiving.md; the frozen openapi.yaml is
 * not edited (the stage 22 and 24 precedent for an additive change to a frozen operation).
 */
class StockReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required_without:packaging_id', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'movement_type' => ['required', Rule::in(['OPENING_STOCK', 'PURCHASE_RECEIPT'])],
            'unit_cost' => ['nullable', 'regex:/^\d{1,10}\.\d{2}$/'],
            'packaging_id' => ['nullable', 'uuid', 'required_with:packs,pack_cost', 'prohibits:quantity,unit_cost'],
            'packs' => ['nullable', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0', 'required_with:packaging_id'],
            'pack_cost' => ['nullable', 'regex:/^\d{1,10}\.\d{2}$/'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.regex' => 'The quantity must be a positive number with up to three decimal places, e.g. 12 or 2.5.',
            'quantity.gt' => 'The quantity must be greater than zero.',
            'unit_cost.regex' => 'The unit cost must be a decimal with exactly two places, e.g. 40.00.',
            'packaging_id.required_with' => 'Choose which pack these are.',
            'packaging_id.prohibits' => 'Give either a quantity in single units or a pack and how many packs, not both.',
            'packs.required_with' => 'Enter how many packs arrived.',
            'packs.regex' => 'The number of packs must be a positive number with up to three decimal places, e.g. 5 or 0.5.',
            'packs.gt' => 'The number of packs must be greater than zero.',
            'pack_cost.regex' => 'The cost of one pack must be a decimal with exactly two places, e.g. 1153.92.',
        ];
    }
}
