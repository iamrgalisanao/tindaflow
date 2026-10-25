<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Body of dishCategoryCreate / dishCategoryUpdate: a name (required when creating) and an optional position. */
class DishCategoryInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/\S/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['name.regex' => 'Enter a name.'];
    }
}
