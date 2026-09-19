<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * openapi.yaml UserInput, shared by userCreate and userUpdate (required: name, email, role; the
 * write-only `password` is optional). Email is unique per store (users migration) and compared
 * case-insensitively so two accounts cannot differ only by letter case. A duplicate is a
 * structural VALIDATION_FAILED field error -- the contract's 422 for both operations.
 *
 * The minimum password length is a technical baseline, not a policy the frozen documents state.
 */
class UserInputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $storeId = Auth::guard('web')->user()->store_id;
        $userId = $this->route('userId');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $this->emailIsUnclaimed($storeId, $userId)],
            'role' => ['required', Rule::in(['ADMIN', 'MANAGER', 'CASHIER'])],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    private function emailIsUnclaimed(string $storeId, ?string $userId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($storeId, $userId): void {
            $taken = User::where('store_id', $storeId)
                ->whereRaw('lower(email) = ?', [Str::lower($value)])
                ->when($userId, fn ($query) => $query->where('id', '!=', $userId))
                ->exists();

            if ($taken) {
                $fail('This email is already used by another user.');
            }
        };
    }
}
