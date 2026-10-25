<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Body of the menu reorder operations: the sibling ids in their new order. Reordering dishes also names the dish category
 * whose dishes they are. The services require every sibling exactly once.
 */
class MenuReorderRequest extends FormRequest
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
            'dish_category_id' => ['sometimes', 'uuid', Rule::exists('dish_categories', 'id')->where('store_id', $storeId)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
