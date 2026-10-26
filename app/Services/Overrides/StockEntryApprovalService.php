<?php

namespace App\Services\Overrides;

use App\Domain\Exceptions\StockEntryApprovalException;
use App\Models\StockEntryGrant;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Inventory\StockRules;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A manager's approval of a CASHIER's daily stock entry (ADR-017 slice B, client decision 5, invariants.md STK-007), in two halves that mirror the
 * stock override of a blocked sale:
 *
 *  grant()    A named approver (anyone holding STOCK_ADJUST: MANAGER, ADMIN) types their own code on the cashier's till. The code check, the
 *             lockout and the refusal audit are {@see ApproverCodeVerifier}'s, shared with the credit and stock overrides (five wrong codes lock the
 *             approver for all three). A good code issues a single-use grant bound to the terminal, the cashier, the store and TODAY's stock date,
 *             valid for five minutes; the random token is returned once and only its SHA-256 is stored.
 *  consume()  The entry POST, inside its transaction, with the grant row already locked FOR UPDATE: checks every binding and the approver's
 *             present eligibility and stamps `consumed_at`. A rolled-back entry rolls that back with it, so a failed save leaves the approval usable
 *             until it expires; a saved one can never be used twice.
 *
 * Reuses STOCK_ADJUST as the approver capability: whoever may enter stock directly may approve a cashier's entry, and nobody else (no new capability).
 */
final class StockEntryApprovalService
{
    public const CAPABILITY = 'STOCK_ADJUST';

    private readonly ApproverCodeVerifier $verifier;

    public function __construct(private readonly OverrideAuditor $auditor, ?ApproverCodeVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? new ApproverCodeVerifier($auditor);
    }

    /**
     * @return array{grant: StockEntryGrant, token: string, approver: User}
     *
     * @throws StockEntryApprovalException
     */
    public function grant(User $cashier, Terminal $terminal, string $approverId, string $code): array
    {
        $rules = StockRules::forStore($terminal->store_id);
        $stockDate = $rules->stockDateAt(now());
        $context = ['approver_id' => $approverId, 'stock_date' => $stockDate];

        try {
            return $this->verifier->verify('STOCK_ENTRY', self::CAPABILITY, $terminal, $cashier, $approverId, $code, $context,
                function ($row, User $approver, CarbonInterface $now) use ($cashier, $terminal, $stockDate, $context): array {
                    $token = bin2hex(random_bytes(32));
                    $grant = StockEntryGrant::create([
                        'store_id' => $terminal->store_id,
                        'terminal_id' => $terminal->id,
                        'cashier_id' => $cashier->id,
                        'approver_id' => $approver->id,
                        'stock_date' => $stockDate,
                        'token_hash' => StockEntryGrant::hashToken($token),
                        'expires_at' => $now->copy()->addMinutes((int) config('tindaflow.credit_override.grant_ttl_minutes')),
                        'created_at' => $now,
                    ]);
                    $this->auditor->stockEntryApproved($cashier, $terminal->id, $grant, $context);

                    return ['grant' => $grant, 'token' => $token, 'approver' => $approver];
                });
        } catch (ApproverRefusal $refusal) {
            throw match ($refusal->kind) {
                'LOCKED' => StockEntryApprovalException::locked($refusal->reason, (int) $refusal->minutes),
                'NOT_ALLOWED' => StockEntryApprovalException::notAllowed($refusal->reason, $refusal->getMessage()),
                default => StockEntryApprovalException::invalid($refusal->reason),
            };
        }
    }

    /** The grant row for a presented token, locked FOR UPDATE; null when no such grant exists. */
    public function lockByToken(string $token): ?StockEntryGrant
    {
        return StockEntryGrant::where('token_hash', StockEntryGrant::hashToken($token))->lockForUpdate()->first();
    }

    /**
     * @throws StockEntryApprovalException
     */
    public function consume(?StockEntryGrant $grant, Terminal $terminal, string $cashierId, string $stockDate, CarbonInterface $now): StockEntryGrant
    {
        if ($grant === null || $grant->store_id !== $terminal->store_id) {
            throw StockEntryApprovalException::invalid('TOKEN_UNKNOWN');
        }
        $context = ['grant_id' => $grant->id, 'approver_id' => $grant->approver_id, 'stock_date' => $stockDate];

        $binding = match (true) {
            $grant->terminal_id !== $terminal->id => 'WRONG_TERMINAL',
            $grant->cashier_id !== $cashierId => 'WRONG_CASHIER',
            $grant->stock_date->toDateString() !== $stockDate => 'WRONG_STOCK_DATE',
            default => null,
        };
        if ($binding !== null) {
            throw StockEntryApprovalException::notAllowed($binding, 'This approval was given for another till, cashier or day. Ask a manager to approve again.', $context);
        }
        if ($grant->consumed_at !== null) {
            throw StockEntryApprovalException::expired('ALREADY_USED', $context);
        }
        if ($grant->expires_at->lessThanOrEqualTo($now)) {
            throw StockEntryApprovalException::expired('EXPIRED', $context);
        }

        $approver = User::where('store_id', $grant->store_id)->find($grant->approver_id);
        if ($approver === null || ! $approver->active || ! RoleCapabilityCatalog::hasForUser($approver, self::CAPABILITY) || $approver->id === $cashierId) {
            throw StockEntryApprovalException::notAllowed('APPROVER_NO_LONGER_ELIGIBLE', 'The manager who approved this can no longer approve it. Ask another manager.', $context);
        }

        $updated = DB::table('stock_entry_grants')->where('id', $grant->id)->whereNull('consumed_at')->update(['consumed_at' => $now]);
        if ($updated !== 1) {
            throw StockEntryApprovalException::expired('ALREADY_USED', $context);
        }
        $grant->consumed_at = $now;

        return $grant;
    }

    /** The audit line for a refusal that happened inside a transaction which has since rolled back. */
    public function recordEntryRefusal(StockEntryApprovalException $refusal, string $storeId, string $cashierId, string $terminalId): void
    {
        $this->auditor->refused($storeId, $cashierId, $terminalId, $refusal->reason, $refusal->auditContext + ['stage' => 'ENTRY'], 'STOCK_ENTRY');
    }
}
