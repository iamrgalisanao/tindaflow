<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml DailyStockEntryRequest (ADR-017 sections 3 and 8): the grid as one request. Each line names a dish (`dish_id`) or a plain daily
 * product (`product_id`) and gives EITHER `cooked` (the total cooked today: the server turns it into added or removed portions) OR `add` (more
 * portions, a second batch). Lowering needs a `reason`. A cashier also presents the manager's single-use `stock_entry_token`. The terminal and
 * the actor are NEVER read from the body.
 */
class DailyStockEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $quantity = 'regex:/^\d{1,7}(\.\d{1,3})?$/';

        return [
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.dish_id' => ['nullable', 'uuid', 'required_without:lines.*.product_id'],
            'lines.*.product_id' => ['nullable', 'uuid', 'required_without:lines.*.dish_id'],
            'lines.*.cooked' => ['nullable', $quantity],
            'lines.*.add' => ['nullable', $quantity],
            'reason' => ['nullable', 'string', 'max:255'],
            'stock_entry_token' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'lines.*.cooked.regex' => 'Enter a number of portions, with up to three decimals.',
            'lines.*.add.regex' => 'Enter a number of portions, with up to three decimals.',
        ];
    }
}
