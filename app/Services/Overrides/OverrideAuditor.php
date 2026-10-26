<?php

namespace App\Services\Overrides;

use App\Models\AuditEvent;
use App\Models\CreditOverrideGrant;
use App\Models\StockEntryGrant;
use App\Models\StockOverrideGrant;
use App\Models\User;

/**
 * The audit trail of the supervisor override (ADR-016 section 6). One line per event, with the ids and amounts needed to answer "who
 * approved what, for whom, on which till". NEVER a code, a password, a hash or a token: callers pass ids, names and amounts only.
 *   CREDIT_OVERRIDE_GRANTED   a good code issued a grant;
 *   CREDIT_OVERRIDE_REFUSED   any refusal, with a `reason` (WRONG_CODE, APPROVER_LOCKED, SELF_APPROVAL, UNKNOWN_APPROVER, ...);
 *   CREDIT_OVERRIDE_LOCKED    the wrong code that locked the approver;
 *   CREDIT_OVERRIDE_USED      checkout consumed a grant to charge past the limit (written in the sale's own transaction);
 *   STOCK_OVERRIDE_GRANTED / _REFUSED / _LOCKED / _USED   the same four for a blocked sale (ADR-017 section 9); _USED is the OVERSOLD record;
 *   STOCK_ENTRY_APPROVED / STOCK_ENTRY_APPROVAL_REFUSED / _LOCKED   the same for a manager's code approving a CASHIER's daily stock entry (ADR-017 slice B);
 *                             the entry itself is DAILY_STOCK_ENTERED, written by DailyStockService with both ids;
 *   OVERRIDE_CODE_SET         an approver set or changed their own code;
 *   OVERRIDE_CODE_RESET       an administrator cleared an approver's code.
 */
final class OverrideAuditor
{
    /** @param  array<string, mixed>  $context */
    public function granted(User $cashier, string $terminalId, CreditOverrideGrant $grant, array $context): void
    {
        $this->write($cashier->store_id, 'CREDIT_OVERRIDE_GRANTED', $cashier->id, $terminalId, 'credit_override', $grant->id, null, $context + [
            'grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'cashier_id' => $cashier->id, 'terminal_id' => $terminalId,
            'employee_id' => $grant->employee_id, 'max_amount' => $grant->max_amount, 'expires_at' => $grant->expires_at->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function refused(string $storeId, string $cashierId, string $terminalId, string $reason, array $context, string $family = 'CREDIT'): void
    {
        $this->write($storeId, self::refusedType($family), $cashierId, $terminalId, self::entity($family), $context['grant_id'] ?? null, null, ['reason' => $reason, 'cashier_id' => $cashierId, 'terminal_id' => $terminalId] + $context, $reason);
    }

    /** @param  array<string, mixed>  $context */
    public function locked(string $storeId, string $cashierId, string $terminalId, string $approverId, int $minutes, array $context, string $family = 'CREDIT'): void
    {
        $this->write($storeId, self::lockedType($family), $cashierId, $terminalId, 'user', $approverId, null, [
            'approver_id' => $approverId, 'cashier_id' => $cashierId, 'terminal_id' => $terminalId, 'locked_minutes' => $minutes,
        ] + $context, 'LOCKED');
    }

    /** The audit event names of the override families: CREDIT_OVERRIDE_*, STOCK_OVERRIDE_* and, for a cashier's daily stock entry, STOCK_ENTRY_APPROVAL_*. */
    private static function refusedType(string $family): string
    {
        return $family === 'STOCK_ENTRY' ? 'STOCK_ENTRY_APPROVAL_REFUSED' : $family.'_OVERRIDE_REFUSED';
    }

    private static function lockedType(string $family): string
    {
        return $family === 'STOCK_ENTRY' ? 'STOCK_ENTRY_APPROVAL_LOCKED' : $family.'_OVERRIDE_LOCKED';
    }

    private static function entity(string $family): string
    {
        return $family === 'STOCK_ENTRY' ? 'stock_entry_approval' : strtolower($family).'_override';
    }

    /** @param  array<string, mixed>  $context */
    public function used(string $storeId, string $cashierId, string $terminalId, CreditOverrideGrant $grant, array $context): void
    {
        $this->write($storeId, 'CREDIT_OVERRIDE_USED', $cashierId, $terminalId, 'credit_override', $grant->id, null, $context + [
            'grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'cashier_id' => $cashierId, 'terminal_id' => $terminalId, 'employee_id' => $grant->employee_id,
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function stockGranted(User $cashier, string $terminalId, StockOverrideGrant $grant, array $context): void
    {
        $this->write($cashier->store_id, 'STOCK_OVERRIDE_GRANTED', $cashier->id, $terminalId, 'stock_override', $grant->id, null, $context + [
            'grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'cashier_id' => $cashier->id, 'terminal_id' => $terminalId,
            'shortfalls' => $grant->shortfalls, 'expires_at' => $grant->expires_at->toIso8601String(),
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function stockUsed(string $storeId, string $cashierId, string $terminalId, StockOverrideGrant $grant, array $context): void
    {
        $this->write($storeId, 'STOCK_OVERRIDE_USED', $cashierId, $terminalId, 'stock_override', $grant->id, null, $context + [
            'grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'cashier_id' => $cashierId, 'terminal_id' => $terminalId,
        ]);
    }

    /** @param  array<string, mixed>  $context */
    public function stockEntryApproved(User $cashier, string $terminalId, StockEntryGrant $grant, array $context): void
    {
        $this->write($cashier->store_id, 'STOCK_ENTRY_APPROVED', $cashier->id, $terminalId, 'stock_entry_approval', $grant->id, null, $context + [
            'grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'cashier_id' => $cashier->id, 'terminal_id' => $terminalId,
            'stock_date' => $grant->stock_date->toDateString(), 'expires_at' => $grant->expires_at->toIso8601String(),
        ]);
    }

    public function codeSet(User $actor, bool $hadCode): void
    {
        $this->write($actor->store_id, 'OVERRIDE_CODE_SET', $actor->id, null, 'user', $actor->id, ['has_code' => $hadCode], ['has_code' => true, 'user_id' => $actor->id]);
    }

    public function codeReset(User $admin, User $target, bool $hadCode, bool $wasLocked): void
    {
        $this->write($admin->store_id, 'OVERRIDE_CODE_RESET', $admin->id, null, 'user', $target->id, ['has_code' => $hadCode, 'locked' => $wasLocked], [
            'has_code' => false, 'user_id' => $target->id, 'user_name' => $target->name, 'role' => $target->role, 'reset_by' => $admin->id,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(string $storeId, string $type, ?string $actorId, ?string $terminalId, string $entityType, ?string $entityId, ?array $before, array $after, ?string $reason = null): void
    {
        AuditEvent::create([
            'store_id' => $storeId,
            'event_type' => $type,
            'actor_user_id' => $actorId,
            'terminal_id' => $terminalId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => $reason,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
