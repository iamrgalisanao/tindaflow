<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** billingWriteOffReverse: a required reason for cancelling the write-off. */
class BillingWriteOffReverseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this write-off is being cancelled (for example "Written off by mistake").',
            'reason.min' => 'Say why this write-off is being cancelled (for example "Written off by mistake").',
        ];
    }
}
