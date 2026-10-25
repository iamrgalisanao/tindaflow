<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * EmployeeCreditLookupResult schema (openapi.yaml), slice 3.2: the lookup result plus the credit status for the CURRENT cut-off
 * (limit, used, remaining, period start and end), which is what the till shows before it charges. Advisory: checkout re-checks.
 *
 * @property Employee $resource
 */
class EmployeeCreditLookupResource extends EmployeeLookupResource
{
    /** @param  array<string, string>  $creditStatus  CreditLedger::status() of this employee */
    public function __construct(Employee $resource, private readonly array $creditStatus)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + ['credit' => [
            'limit' => $this->creditStatus['limit'],
            'used' => $this->creditStatus['used'],
            'remaining' => $this->creditStatus['remaining'],
            'period_start' => $this->creditStatus['period_start'],
            'period_end' => $this->creditStatus['period_end'],
        ]];
    }
}
