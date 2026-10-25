<?php

namespace App\Services\Employees;

use App\Domain\Exceptions\EmployeeInactiveException;
use App\Domain\Exceptions\EmployeeNotFoundException;
use App\Enums\EmployeeType;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Admin surface for employees (ADR-016 section 5, EMPLOYEE_MANAGE) and the exact-match lookup the till uses.
 *
 * The code is generated here and nowhere else (EmployeeCodeAllocator); the client never sends one, and the type is fixed at creation.
 * An employee is never deleted: deactivating is `active = false` and the code stays reserved. Everything is scoped to the actor's
 * store: an employee of another store is EMPLOYEE_NOT_FOUND.
 */
final class EmployeeService
{
    public function __construct(
        private EmployeeCodeAllocator $codes,
        private BillingPartyResolver $parties,
        private CreditPolicyService $policies,
        private EmployeeAuditor $auditor,
    ) {}

    /** @param  array<string, mixed>  $data  the validated EmployeeInput: employee_type, first_name, middle_name?, last_name */
    public function create(User $actor, array $data, ?string $batchId = null): Employee
    {
        return DB::transaction(function () use ($actor, $data, $batchId) {
            $type = EmployeeType::from($data['employee_type']);
            $this->policies->ensureDefaults($actor->store_id);

            $employee = Employee::create([
                'store_id' => $actor->store_id,
                'employee_type' => $type,
                'employee_code' => $this->codes->next($actor->store_id, $type),
                'billing_party_id' => $this->parties->forStore($actor->store_id)->id,
                'active' => true,
                'created_by' => $actor->id,
            ] + self::names($data));
            $this->auditor->created($actor, $employee, $batchId);

            return $employee;
        });
    }

    public function find(string $storeId, string $id): Employee
    {
        return Employee::where('store_id', $storeId)->find($id) ?? throw EmployeeNotFoundException::forId($id);
    }

    /** @param  array<string, mixed>  $data  first_name, middle_name, last_name; absent keys are left alone. The code and type cannot be sent. */
    public function update(User $actor, string $id, array $data): Employee
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $employee = $this->lock($actor->store_id, $id);
            $employee->fill(self::names($data));
            [$before, $after] = $this->auditor->nameDiff($employee);
            $employee->save();
            $this->auditor->updated($actor, $employee, $before, $after);

            return $employee;
        });
    }

    /** Idempotent: deactivating an inactive employee (or reactivating an active one) changes and records nothing. */
    public function setActive(User $actor, string $id, bool $active): Employee
    {
        return DB::transaction(function () use ($actor, $id, $active) {
            $employee = $this->lock($actor->store_id, $id);

            if ($employee->active !== $active) {
                $employee->active = $active;
                $employee->save();
                $this->auditor->activeChanged($actor, $employee);
            }

            return $employee;
        });
    }

    /**
     * The admin list: searchable by any part of the name or the code, filterable by type and active, ordered by name then code.
     *
     * @param  array{search?: string|null, employee_type?: string|null, active?: bool|null}  $filters
     * @return Builder<Employee>
     */
    public function query(string $storeId, array $filters): Builder
    {
        $query = Employee::where('store_id', $storeId);

        if (($term = trim((string) ($filters['search'] ?? ''))) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(fn (Builder $q) => $q
                ->where('employee_code', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', first_name, middle_name, last_name) ilike ?", [$like])
                ->orWhereRaw("concat_ws(' ', last_name, first_name, middle_name) ilike ?", [$like]));
        }
        if (! empty($filters['employee_type'])) {
            $query->where('employee_type', $filters['employee_type']);
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== null) {
            $query->where('active', $filters['active']);
        }

        return $query->orderBy('last_name')->orderBy('first_name')->orderBy('employee_code');
    }

    /**
     * The till's lookup: ONE employee by exact code. What a scanner types (the code, then Enter) or a cashier types by hand:
     * surrounding whitespace is ignored and the case does not matter. No partial match, no listing. An unknown code is
     * EMPLOYEE_NOT_FOUND; a deactivated employee is EMPLOYEE_INACTIVE.
     */
    public function lookup(string $storeId, string $typed): Employee
    {
        $code = self::normalizeCode($typed);
        $employee = Employee::where('store_id', $storeId)->where('employee_code', $code)->first() ?? throw EmployeeNotFoundException::forCode($code);

        if (! $employee->active) {
            throw EmployeeInactiveException::forCode($code);
        }

        return $employee;
    }

    public static function normalizeCode(string $typed): string
    {
        return strtoupper(preg_replace('/^[\s\x00-\x1F]+|[\s\x00-\x1F]+$/u', '', $typed) ?? '');
    }

    /** The comparison key of the import's duplicate safeguard: type plus the three name parts, trimmed and case-folded. */
    public static function nameKey(EmployeeType|string $type, string $first, ?string $middle, string $last): string
    {
        $type = $type instanceof EmployeeType ? $type->value : $type;

        return mb_strtolower(implode('|', [$type, $first, $middle ?? '', $last]));
    }

    private function lock(string $storeId, string $id): Employee
    {
        return Employee::where('store_id', $storeId)->lockForUpdate()->find($id) ?? throw EmployeeNotFoundException::forId($id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    public static function names(array $data): array
    {
        $out = [];
        foreach (['first_name', 'middle_name', 'last_name'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $value = self::tidy((string) ($data[$field] ?? ''));
            $out[$field] = $value === '' && $field === 'middle_name' ? null : $value;
        }

        return $out;
    }

    /** Trims and collapses inner runs of whitespace, so "  Juan   dela  Cruz " is stored as "Juan dela Cruz". */
    public static function tidy(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
