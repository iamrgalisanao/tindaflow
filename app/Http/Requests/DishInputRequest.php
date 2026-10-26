<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/** Body of dishCreate / dishUpdate. A dish belongs to a dish category of the same store; updating may rename, move or re-sort it. */
class DishInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:255', 'regex:/\S/'],
            'dish_category_id' => [$presence, 'uuid', Rule::exists('dish_categories', 'id')->where('store_id', $storeId)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            // ADR-017 slice B: DAILY counts the dish's portions per stock day (entered each morning); NONE does not.
            'stock_mode' => ['sometimes', Rule::in(['NONE', 'DAILY'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => 'Enter a name.'];
    }
}
