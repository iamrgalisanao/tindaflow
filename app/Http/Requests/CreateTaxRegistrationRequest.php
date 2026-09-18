<?php

namespace App\Http\Requests;

use App\Models\TaxRegistration;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/** openapi.yaml taxRegistrationCreate request body -- {registration_type, effective_from}. */
class CreateTaxRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'registration_type' => ['required', 'string', 'in:VAT,NON_VAT'],
            'effective_from' => ['required', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $actor = Auth::guard('web')->user();
            $effectiveFrom = $this->input('effective_from');

            if ($actor === null || $effectiveFrom === null) {
                return;
            }

            $current = TaxRegistration::where('store_id', $actor->store_id)->whereNull('effective_to')->first();

            if ($current !== null && $current->effective_from->toDateString() >= $effectiveFrom) {
                $validator->errors()->add(
                    'effective_from',
                    'Must be after the store\'s current tax registration effective date.',
                );
            }
        });
    }
}
