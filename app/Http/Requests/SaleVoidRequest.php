<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml saleVoid / voidReject / refundReject request body: a required, non-blank reason
 * (invariants #20e/#24). No processing terminal, shift or fiscal day is accepted -- they are resolved
 * server-side (invariants #67-#69).
 */
class SaleVoidRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
