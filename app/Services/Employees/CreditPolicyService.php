<?php

namespace App\Services\Employees;

use App\Enums\EmployeeType;
use App\Models\CreditPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The credit limit per cut-off of each employee type (ADR-016 section 5): TSC (direct) 1,500.00 and SUB (subcontractor) 1,000.00 until
 * changed. One mutable row per store and type; every change is audited with the old and the new value (CREDIT_LIMIT_CHANGED).
 * The migration creates the rows for existing stores; a store created later gets them the first time they are read or an employee
 * is created (ensureDefaults). The credit check of slice 3.2 reads the limit through limitFor().
 */
final class CreditPolicyService
{
    public function __construct(private EmployeeAuditor $auditor) {}

    /** Creates a store's missing rows with the default limits; never touches a row that exists. */
    public function ensureDefaults(string $storeId): void
    {
        $now = now();
        foreach (EmployeeType::cases() as $type) {
            DB::table('credit_policies')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'store_id' => $storeId, 'employee_type' => $type->value,
                'limit_per_cutoff' => $type->defaultCreditLimit(), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** @return list<CreditPolicy> one per employee type, DIRECT first */
    public function all(string $storeId): array
    {
        $this->ensureDefaults($storeId);
        $rows = CreditPolicy::where('store_id', $storeId)->get()->keyBy(fn (CreditPolicy $policy) => $policy->employee_type->value);

        return array_map(fn (EmployeeType $type) => $rows[$type->value], EmployeeType::cases());
    }

    public function limitFor(string $storeId, EmployeeType $type): string
    {
        $this->ensureDefaults($storeId);

        return (string) CreditPolicy::where('store_id', $storeId)->where('employee_type', $type->value)->value('limit_per_cutoff');
    }

    /** Sets one type's limit (a decimal string, two places). Setting the value it already has changes and records nothing. */
    public function setLimit(User $actor, EmployeeType $type, string $limit): CreditPolicy
    {
        return DB::transaction(function () use ($actor, $type, $limit) {
            $this->ensureDefaults($actor->store_id);
            $policy = CreditPolicy::where('store_id', $actor->store_id)->where('employee_type', $type->value)->lockForUpdate()->firstOrFail();

            $before = (string) $policy->limit_per_cutoff;
            $after = number_format((float) $limit, 2, '.', '');
            if ($before !== $after) {
                $policy->limit_per_cutoff = $after;
                $policy->updated_by = $actor->id;
                $policy->save();
                $this->auditor->limitChanged($actor, $policy->id, $type->value, $before, $after);
            }

            return $policy->refresh();
        });
    }
}
