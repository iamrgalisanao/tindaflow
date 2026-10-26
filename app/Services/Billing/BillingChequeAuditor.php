<?php

namespace App\Services\Billing;

use App\Models\AuditEvent;
use App\Models\BillingCheque;
use App\Models\User;

/**
 * Audit trail for cheques (ADR-016 section 8, slice 4.2), written in the same transaction as the change, so a refused attempt writes nothing:
 *   BILLING_CHEQUE_RECORDED   a cheque was recorded: number, bank, dates, amount and what it pays per period;
 *   BILLING_CHEQUE_RETURNED   a cheque was marked returned (bounced): the reason is in the event's reason, the amount reinstated per period in the payload.
 * Only what is on the cheque itself is written (no account number, none is collected). Viewing the list or the balance writes nothing; an
 * export is written as BILLING_EXPORTED by BillingController (report = balance | cheques).
 */
final class BillingChequeAuditor
{
    public function recorded(User $actor, BillingCheque $cheque): void
    {
        $this->write($actor, 'BILLING_CHEQUE_RECORDED', $cheque, null, $this->summary($cheque) + ['status' => $cheque->status], null);
    }

    public function returned(User $actor, BillingCheque $cheque): void
    {
        $this->write($actor, 'BILLING_CHEQUE_RETURNED', $cheque, ['status' => BillingCheque::RECEIVED], $this->summary($cheque) + [
            'status' => $cheque->status,
            'returned_on' => $cheque->returned_on->toDateString(),
        ], $cheque->return_reason);
    }

    /** @return array<string, mixed> */
    private function summary(BillingCheque $cheque): array
    {
        return [
            'cheque_number' => $cheque->cheque_number,
            'bank' => $cheque->bank,
            'cheque_date' => $cheque->cheque_date->toDateString(),
            'received_on' => $cheque->received_on->toDateString(),
            'amount' => (string) $cheque->amount,
            'allocations' => $cheque->allocations->map(fn ($a) => ['period_start' => $a->period_start->toDateString(), 'amount' => (string) $a->amount])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, BillingCheque $cheque, ?array $before, array $after, ?string $reason): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => 'billing_cheque',
            'entity_id' => $cheque->id,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => $reason,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
