<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of mealOptionGroupCreate / mealOptionGroupUpdate. The kind (CHOICE dishes, ADDON extras) is chosen when the group is
 * created and never changes. `prices_the_meal: true` on an update moves the "prices the meal" flag to this group; it cannot
 * be set to false (a meal always has one pricing group). The range rules are in MealOptionGroupService.
 */
class MealOptionGroupInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/\S/'],
            'kind' => $creating ? ['required', Rule::in(['CHOICE', 'ADDON'])] : ['prohibited'],
            'min_select' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'max_select' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'popup_after_choice' => ['sometimes', 'boolean'],
            'step_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'prices_the_meal' => $creating ? ['prohibited'] : ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Enter a name.',
            'kind.prohibited' => 'A group cannot change from dishes to add-ons or back.',
            'prices_the_meal.prohibited' => 'A new group does not price the meal; move that flag to it afterwards.',
        ];
    }
}
