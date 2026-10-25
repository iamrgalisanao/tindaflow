<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee schema (openapi.yaml), the administrator's view. `name` is "First Middle Last".
 *
 * @property Employee $resource
 */
class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $employee = $this->resource;

        return [
            'id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_type' => $employee->employee_type->value,
            'first_name' => $employee->first_name,
            'middle_name' => $employee->middle_name,
            'last_name' => $employee->last_name,
            'name' => $employee->fullName(),
            'billing_party_id' => $employee->billing_party_id,
            'active' => $employee->active,
            'created_at' => $employee->created_at?->toIso8601String(),
        ];
    }
}
