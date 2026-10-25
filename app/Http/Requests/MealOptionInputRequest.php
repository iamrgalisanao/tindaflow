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
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['dish_id.prohibited' => 'An option keeps its dish. Hide it and offer another dish instead.'];
    }
}
