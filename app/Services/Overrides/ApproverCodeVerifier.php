<?php

namespace App\Services\Overrides;

use App\Models\Terminal;
use App\Models\User;
use App\Models\UserOverrideCode;
use App\Services\Auth\RoleCapabilityCatalog;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The part of a supervisor override that is the same for every kind of override (ADR-016 section 6, ADR-017 section 9): a NAMED approver
 * types their own code, and it is checked here. Credit-limit overrides and stock overrides both call this, so the code table, the
 * wrong-code counter and the lockout are one thing: five wrong codes lock that approver for fifteen minutes whichever override the code
 * was typed for.
 *
 * The attempt is verified in ITS OWN committed transaction, never inside a checkout: the wrong-code counter is written and committed
 * before the refusal is thrown. The approver's `user_override_codes` row is locked FOR UPDATE for the whole check, so parallel guesses are
 * counted one after another. On a good code the caller's `$issue` closure runs INSIDE that same locked transaction (it creates the grant
 * and its GRANTED audit line) and its result is returned.
 *
 * Audit lines of refusals use the family prefix ("CREDIT" or "STOCK"): CREDIT_OVERRIDE_REFUSED / STOCK_OVERRIDE_REFUSED, and _LOCKED.
 * Nothing here ever logs or audits a code, a hash or a token.
 */
final class ApproverCodeVerifier
{
    public function __construct(private readonly OverrideAuditor $auditor) {}

    /**
     * @param  string  $family  'CREDIT' or 'STOCK' (the audit event prefix and the lockout configuration are shared)
     * @param  string  $capability  what the approver's role must hold
     * @param  array<string, mixed>  $context  ids and amounts for the audit lines
     * @param  Closure(UserOverrideCode, User, CarbonInterface): mixed  $issue  runs in the locked transaction after a good code
     *
     * @throws ApproverRefusal
     */
    public function verify(string $family, string $capability, Terminal $terminal, User $cashier, string $approverId, string $code, array $context, Closure $issue): mixed
    {
        $storeId = $terminal->store_id;

        // Nobody approves their own sale. Said plainly (the cashier knows who they are) and checked before any code is tried or counted.
        if ($approverId === $cashier->id) {
            $this->auditor->refused($storeId, $cashier->id, $terminal->id, 'SELF_APPROVAL', $context, $family);
            throw new ApproverRefusal('NOT_ALLOWED', 'SELF_APPROVAL', 'You cannot approve your own sale. Ask another manager.');
        }

        $approver = User::where('store_id', $storeId)->find($approverId);
        $ineligible = $this->ineligibleReason($approver, $capability);
        if ($ineligible !== null) {
            // The same single generic answer as a wrong code, after a hash check of the same cost, so neither the message nor the time
            // says whether this approver exists, is active, may approve or has a code.
            Hash::check($code, $this->decoyHash());
            $this->auditor->refused($storeId, $cashier->id, $terminal->id, $ineligible, $context, $family);
            throw new ApproverRefusal('INVALID', $ineligible);
        }

        $max = (int) config('tindaflow.credit_override.max_wrong_codes');
        $lockMinutes = (int) config('tindaflow.credit_override.lock_minutes');
        $this->assertNotInsideATransaction();

        $outcome = DB::transaction(function () use ($family, $terminal, $cashier, $approver, $code, $context, $max, $lockMinutes, $issue): array {
            $row = UserOverrideCode::lockForUpdate()->find($approver->id);
            if ($row === null || ! $row->hasCode()) {
                // Lost a race with an administrator's reset between the eligibility read and the lock.
                Hash::check($code, $this->decoyHash());
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'NO_CODE', $context, $family);

                return ['refusal' => new ApproverRefusal('INVALID', 'NO_CODE')];
            }

            $now = now();
            if ($row->isLockedAt($now)) {
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'APPROVER_LOCKED', $context, $family);

                return ['refusal' => new ApproverRefusal('LOCKED', 'APPROVER_LOCKED', '', (int) ceil($now->diffInSeconds($row->locked_until, true) / 60))];
            }

            if (! Hash::check($code, $row->code_hash)) {
                $failed = $row->failed_attempts + 1;
                if ($failed >= $max) {
                    $row->forceFill(['failed_attempts' => 0, 'locked_until' => $now->copy()->addMinutes($lockMinutes)])->save();
                    $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'WRONG_CODE', $context, $family);
                    $this->auditor->locked($terminal->store_id, $cashier->id, $terminal->id, $approver->id, $lockMinutes, $context, $family);

                    return ['refusal' => new ApproverRefusal('LOCKED', 'WRONG_CODE', '', $lockMinutes)];
                }
                $row->forceFill(['failed_attempts' => $failed])->save();
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'WRONG_CODE', $context, $family);

                return ['refusal' => new ApproverRefusal('INVALID', 'WRONG_CODE')];
            }

            if ($row->failed_attempts !== 0) {
                $row->forceFill(['failed_attempts' => 0])->save();
            }

            return ['result' => $issue($row, $approver, $now)];
        });

        if (isset($outcome['refusal'])) {
            throw $outcome['refusal'];
        }

        return $outcome['result'];
    }

    private function ineligibleReason(?User $approver, string $capability): ?string
    {
        if ($approver === null) {
            return 'UNKNOWN_APPROVER';
        }
        if (! $approver->active) {
            return 'APPROVER_INACTIVE';
        }
        if (! RoleCapabilityCatalog::hasForUser($approver, $capability)) {
            return 'APPROVER_LACKS_CAPABILITY';
        }
        $row = UserOverrideCode::find($approver->id);

        return $row === null || ! $row->hasCode() ? 'NO_CODE' : null;
    }

    /** A real hash of the configured cost, made once per process, so a refusal for an ineligible approver takes as long as a wrong code. */
    private function decoyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(bin2hex(random_bytes(8)));
    }

    /**
     * The failed-attempt counter is only a lock if it survives whatever the caller does next, so the verification must not run inside
     * someone else's transaction (which a failure could roll back, erasing the count). In production the request holds none, so any open
     * transaction is refused loudly instead of silently weakening the lockout. `base_transaction_level` is how many the process legitimately
     * holds already: 0, except in tests/Database, whose base class wraps every test in one.
     */
    private function assertNotInsideATransaction(): void
    {
        if (DB::transactionLevel() > (int) config('tindaflow.credit_override.base_transaction_level', 0)) {
            throw new \LogicException('Override codes must be verified outside any transaction, or a rollback would erase the failed-attempt count.');
        }
    }
}
