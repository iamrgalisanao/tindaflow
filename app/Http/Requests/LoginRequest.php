<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * openapi.yaml authLogin request body -- exactly {email, password}, both
 * required. Deliberately whitelists only these two fields; user_id/
 * store_id/role/capabilities/terminal_id/cashier_id are never accepted
 * (Module A Decision Register SS5).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
