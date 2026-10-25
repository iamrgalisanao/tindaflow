<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Body of menuGroupReorder: the sibling ids in their new order, and the parent whose sub-groups they are (null for top groups). */
class MenuGroupReorderRequest extends FormRequest
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
            'parent_id' => ['nullable', 'uuid', Rule::exists('menu_groups', 'id')->where('store_id', $storeId)],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
