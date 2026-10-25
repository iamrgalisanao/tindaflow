<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EmployeeLookupResult schema (openapi.yaml): only what the till needs to name the person it is charging. No billing party, no
 * dates, no balance (credit status arrives with slice 3.2).
 *
 * @property Employee $resource
 */
class EmployeeLookupResource extends JsonResource
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
            'name' => $employee->fullName(),
            'active' => $employee->active,
        ];
    }
}
