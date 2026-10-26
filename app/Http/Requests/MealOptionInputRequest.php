<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of mealOptionCreate / mealOptionUpdate. A group of dishes takes a `dish_id`; a group of add-ons takes a `label`
 * (MealOptionService checks which one the group accepts). Updating changes an add-on's label or any option's position.
 */
class MealOptionInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $creating = $this->isMethod('POST');

        return [
            'dish_id' => $creating ? ['nullable', 'uuid', Rule::exists('dishes', 'id')->where('store_id', $storeId)] : ['prohibited'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // ADR-017 slice B: portions of the dish one plate takes from the dish's daily stock (Standard viand 1, Combo main 1, Combo side 1 by default).
            'stock_portion' => ['sometimes', 'regex:/^\d{1,7}(\.\d{1,3})?$/', 'gt:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['stock_portion.regex' => 'Portions are a number with up to three decimals, e.g. 1 or 0.5.', 'stock_portion.gt' => 'Portions must be more than zero.', 'dish_id.prohibited' => 'An option keeps its dish. Hide it and offer another dish instead.'];
    }
}
