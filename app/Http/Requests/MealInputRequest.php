<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of mealCreate / mealUpdate. Creating also names the meal's pricing group (default "Main"); the menu group places the
 * meal on the till's navigation tree and may be null.
 */
class MealInputRequest extends FormRequest
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
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/\S/'],
            'menu_group_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('menu_groups', 'id')->where('store_id', $storeId)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'pricing_group_name' => $creating ? ['sometimes', 'nullable', 'string', 'max:255'] : ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => 'Enter a name.', 'pricing_group_name.prohibited' => 'Rename the pricing group from the group itself.'];
    }
}
