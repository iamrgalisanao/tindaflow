<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of dishAlaCarteCreate (ADR-017 slice B, client decision 2): the manager chooses dishes and gives each a price, either one price per dish
 * category (`category_prices`, keyed by dish category id) or one per dish (`items.*.price`, which wins). The prices are the manager's: nothing is
 * assumed. `menu_group_id` is the till group the new items sit in (optional). `stock_portion` is the portions of the dish one unit takes (default 2).
 */
class DishAlaCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $money = 'regex:/^\d{1,10}\.\d{2}$/';

        return [
            'menu_group_id' => ['nullable', 'uuid', Rule::exists('menu_groups', 'id')->where('store_id', $storeId)],
            'stock_portion' => ['sometimes', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
            'category_prices' => ['sometimes', 'array'],
            'category_prices.*' => ['nullable', $money],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.dish_id' => ['required', 'uuid', 'distinct', Rule::exists('dishes', 'id')->where('store_id', $storeId)],
            'items.*.price' => ['nullable', $money],
            'items.*.name' => ['nullable', 'string', 'max:255', 'regex:/\S/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.*.price.regex' => 'The price must be a decimal with exactly two places, e.g. 85.00.',
            'category_prices.*.regex' => 'The price must be a decimal with exactly two places, e.g. 85.00.',
            'stock_portion.regex' => 'Portions are a number with up to three decimals, e.g. 2 or 0.5.',
            'stock_portion.gt' => 'Portions must be more than zero.',
            'items.*.name.regex' => 'Enter a name.',
        ];
    }
}
