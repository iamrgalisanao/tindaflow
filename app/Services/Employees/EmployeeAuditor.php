<?php

namespace App\Services\Employees;

use App\Models\AuditEvent;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Audit trail for employees and credit limits (ADR-016 section 5), written in the same transaction as the change, like
 * MenuGroupAuditor:
 *   EMPLOYEE_CREATED      the employee as created (the generated code, type and names -- never anything about credit);
 *   EMPLOYEE_UPDATED      only the names that changed, before and after, with the code for context;
 *   EMPLOYEE_DEACTIVATED / EMPLOYEE_REACTIVATED   with the code and name;
 *   EMPLOYEES_IMPORTED    one summary per CSV import (row counts, the file's SHA-256, the codes created), alongside the
 *                         EMPLOYEE_CREATED event of each row, which carries the same `batch_id`;
 *   CREDIT_LIMIT_CHANGED  the old and the new limit of one employee type.
 * A change that changes nothing writes nothing.
 */
final class EmployeeAuditor
{
    public const TRACKED_NAMES = ['first_name', 'middle_name', 'last_name'];

    public function created(User $actor, Employee $employee, ?string $batchId = null): void
    {
        $after = Arr::only($employee->getAttributes(), ['employee_code', 'first_name', 'middle_name', 'last_name', 'billing_party_id', 'active'])
            + ['employee_type' => $employee->employee_type->value];
        if ($batchId !== null) {
            $after['batch_id'] = $batchId;
        }

        $this->write($actor, 'EMPLOYEE_CREATED', 'employee', $employee->id, null, $after);
    }

    /**
     * What changed on an employee that has been filled but not yet saved: [before, after], names only.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function nameDiff(Employee $employee): array
    {
        $before = [];
        $after = [];

        foreach (array_keys(Arr::only($employee->getDirty(), self::TRACKED_NAMES)) as $field) {
            $before[$field] = $employee->getOriginal($field);
            $after[$field] = $employee->getAttribute($field);
        }

        return [$before, $after];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function updated(User $actor, Employee $employee, array $before, array $after): void
    {
        if ($after === []) {
            return;
        }

        $this->write($actor, 'EMPLOYEE_UPDATED', 'employee', $employee->id, $before, $after + ['employee_code' => $employee->employee_code]);
    }

    public function activeChanged(User $actor, Employee $employee): void
    {
        $this->write(
            $actor,
            $employee->active ? 'EMPLOYEE_REACTIVATED' : 'EMPLOYEE_DEACTIVATED',
            'employee',
            $employee->id,
            ['active' => ! $employee->active],
            ['active' => $employee->active, 'employee_code' => $employee->employee_code, 'name' => $employee->fullName()],
        );
    }

    /** @param  array<string, mixed>  $summary */
    public function imported(User $actor, string $batchId, array $summary): void
    {
        $this->write($actor, 'EMPLOYEES_IMPORTED', 'employee_import', $batchId, null, $summary);
    }

    public function limitChanged(User $actor, string $policyId, string $employeeType, string $before, string $after): void
    {
        $this->write($actor, 'CREDIT_LIMIT_CHANGED', 'credit_policy', $policyId, ['limit_per_cutoff' => $before], [
            'limit_per_cutoff' => $after, 'employee_type' => $employeeType,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, string $entityType, ?string $entityId, ?array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => null,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
