<?php

namespace App\Http\Requests;

use App\Models\Terminal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * openapi.yaml terminalCreate request body -- exactly {terminal_code}. The code is unique per store
 * (terminals migration) and compared case-insensitively, so two tills cannot differ only by letter case.
 * A duplicate is a structural VALIDATION_FAILED field error, the contract's 422 for this operation.
 */
class CreateTerminalRequest extends FormRequest
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
            'terminal_code' => ['required', 'string', 'max:40', $this->codeIsUnclaimed($storeId)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('terminal_code'))) {
            $this->merge(['terminal_code' => trim($this->input('terminal_code'))]);
        }
    }

    private function codeIsUnclaimed(string $storeId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($storeId): void {
            $taken = Terminal::where('store_id', $storeId)
                ->whereRaw('lower(terminal_code) = ?', [Str::lower($value)])
                ->exists();

            if ($taken) {
                $fail('This store already has a till with that name.');
            }
        };
    }
}
