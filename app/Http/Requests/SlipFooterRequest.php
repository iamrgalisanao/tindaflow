<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** openapi.yaml SlipFooterInput. A blank value (which the framework turns into null) clears the footer. */
class SlipFooterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['slip_footer' => ['present', 'nullable', 'string', 'max:200']];
    }
}
