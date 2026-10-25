<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of menuGroupCreate / menuGroupUpdate. Creating needs a name and may name a parent (a top group of the same store;
 * the depth cap itself is enforced by MenuGroupService). Updating renames and/or re-sorts: the parent never changes.
 */
class MenuGroupInputRequest extends FormRequest
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
            'parent_id' => $creating
                ? ['nullable', 'uuid', Rule::exists('menu_groups', 'id')->where('store_id', $storeId)]
                : ['prohibited'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Enter a name.',
            'parent_id.prohibited' => 'A group cannot be moved to another parent.',
        ];
    }
}
