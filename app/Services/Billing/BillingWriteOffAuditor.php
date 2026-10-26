<?php

namespace App\Services\Billing;

use App\Models\AuditEvent;
use App\Models\BillingCheque;
use App\Models\BillingWriteOff;
use App\Models\User;

/**
 * Audit trail for write-offs (ADR-016 section 8, slice 4.3), written in the same transaction as the row, so a refused attempt writes nothing:
 *   BILLING_WRITE_OFF_RECORDED   an unpaid amount of a cut-off period was written off: period, amount, the cheque it relates to (if any), what the
 *                                period owed before and after; the reason is in the event's reason, the actor is the actor of the event;
 *   BILLING_WRITE_OFF_REVERSED   a write-off was cancelled: the same facts plus the write-off it cancels and that write-off's own reason.
 * Viewing the list or the balance writes nothing; the balance CSV export is BILLING_EXPORTED (BillingChequeController).
 */
final class BillingWriteOffAuditor
{
    public function recorded(User $actor, BillingWriteOff $row, ?BillingCheque $cheque, string $owedBefore, string $owedAfter): void
    {
        $this->write($actor, 'BILLING_WRITE_OFF_RECORDED', $row, null, $this->summary($row, $cheque) + ['owed_before' => $owedBefore, 'owed_after' => $owedAfter]);
    }

    public function reversed(User $actor, BillingWriteOff $reversal, BillingWriteOff $original, ?BillingCheque $cheque, string $owedBefore, string $owedAfter): void
    {
        $this->write(
            $actor,
            'BILLING_WRITE_OFF_REVERSED',
            $reversal,
            ['write_off_id' => $original->id, 'original_reason' => $original->reason, 'original_written_by' => $original->written_by, 'original_written_at' => $original->written_at->toIso8601String()],
            $this->summary($reversal, $cheque) + ['reverses_write_off_id' => $original->id, 'owed_before' => $owedBefore, 'owed_after' => $owedAfter],
        );
    }

    /** @return array<string, mixed> */
    private function summary(BillingWriteOff $row, ?BillingCheque $cheque): array
    {
        return [
            'period_start' => $row->period_start->toDateString(),
            'period_end' => $row->period_end->toDateString(),
            'amount' => (string) $row->amount,
            'cheque_id' => $cheque?->id,
            'cheque_number' => $cheque?->cheque_number,
            'bank' => $cheque?->bank,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, BillingWriteOff $row, ?array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => 'billing_write_off',
            'entity_id' => $row->id,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => $row->reason,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
