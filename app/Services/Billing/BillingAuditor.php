<?php

namespace App\Services\Billing;

use App\Models\AuditEvent;
use App\Models\BillingRun;
use App\Models\User;

/**
 * Audit trail for billing (ADR-016 section 8), written like EmployeeAuditor:
 *   BILLING_RUN_CREATED   a billing run was saved: its number, range, filters, as-of instant, totals and the SHA-256 of the frozen statement;
 *   BILLING_EXPORTED      a statement or a saved run was exported (CSV), live or from a saved run, with the range and the totals exported.
 * Viewing a live statement writes nothing (it changes nothing). No amount per employee is put in the audit payload, only the totals.
 */
final class BillingAuditor
{
    public function runCreated(User $actor, BillingRun $run): void
    {
        $this->write($actor, 'BILLING_RUN_CREATED', 'billing_run', $run->id, $this->summary($run) + ['statement_sha256' => $run->statement_sha256]);
    }

    /** @param  array<string, mixed>  $statement */
    public function exported(User $actor, array $statement, ?BillingRun $run): void
    {
        $criteria = $statement['criteria'];
        $after = [
            'format' => 'csv',
            'source' => $run === null ? 'live' : 'saved_run',
            'from' => $criteria['from'],
            'to' => $criteria['to'],
            'employee_type' => $criteria['employee_type'],
            'employee_code' => $criteria['employee_code'],
            'as_of' => $criteria['as_of'],
            'net_amount' => $statement['totals']['net_amount'],
            'employee_count' => $statement['totals']['employee_count'],
        ];
        if ($run !== null) {
            $after['run_number'] = $run->run_number;
        }

        $this->write($actor, 'BILLING_EXPORTED', $run === null ? 'billing_statement' : 'billing_run', $run?->id, $after);
    }

    /**
     * The cheque list or the balance by period was exported (slice 4.2). `report` is `cheques` or `balance`.
     *
     * @param  array<string, mixed>  $details
     */
    public function chequeReportExported(User $actor, string $report, array $details): void
    {
        $this->write($actor, 'BILLING_EXPORTED', 'billing_'.$report, null, ['format' => 'csv', 'report' => $report] + $details);
    }

    /** @return array<string, mixed> */
    private function summary(BillingRun $run): array
    {
        return [
            'run_number' => $run->run_number,
            'from' => $run->from_date->toDateString(),
            'to' => $run->to_date->toDateString(),
            'employee_type' => $run->employee_type,
            'employee_code' => $run->employee_code,
            'as_of' => $run->as_of->toIso8601String(),
            'sold_amount' => (string) $run->charges_total,
            'returned_amount' => (string) $run->reversals_total,
            'net_amount' => (string) $run->net_total,
            'employee_count' => $run->account_count,
        ];
    }

    /** @param  array<string, mixed>  $after */
    private function write(User $actor, string $type, string $entityType, ?string $entityId, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => null,
            'after_metadata' => $after,
            'reason' => null,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
