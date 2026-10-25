<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of menuPriceSet: a new price for one dish category of the meal, or for one add-on option of it. Exactly one of the two
 * keys. `effective_from` is optional (now); MenuPriceService refuses a time in the past. Which categories and options belong
 * to the meal is checked there.
 */
class MenuPriceSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;

        return [
            'dish_category_id' => ['nullable', 'uuid', 'required_without:meal_option_id', 'prohibits:meal_option_id', Rule::exists('dish_categories', 'id')->where('store_id', $storeId)],
            'meal_option_id' => ['nullable', 'uuid', 'required_without:dish_category_id'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'effective_from' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.regex' => 'Enter an amount in pesos, up to two decimals.',
            'dish_category_id.required_without' => 'Choose a dish category or an add-on.',
            'meal_option_id.required_without' => 'Choose a dish category or an add-on.',
            'dish_category_id.prohibits' => 'Price either a dish category or an add-on, not both.',
        ];
    }
}
