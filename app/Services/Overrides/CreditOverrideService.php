<?php

namespace App\Services\Overrides;

use App\Domain\Exceptions\CreditOverrideException;
use App\Domain\Exceptions\EmployeeInactiveException;
use App\Domain\Exceptions\EmployeeNotFoundException;
use App\Domain\Money;
use App\Models\CreditOverrideGrant;
use App\Models\Employee;
use App\Models\Terminal;
use App\Models\User;
use App\Models\UserOverrideCode;
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Credit\CreditLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The supervisor override of the credit limit (ADR-016 section 6, invariants.md OVR-001, OVR-002), in two halves:
 *
 *  grant()    A named approver types their code on the cashier's till. The attempt is verified in ITS OWN committed transaction, never
 *             inside a checkout: the wrong-code counter is written and committed before the refusal is thrown, so nothing that happens
 *             afterwards (an exception unwinding, a request that fails) can erase it, and checkout never touches codes at all. Five wrong
 *             codes lock that approver for fifteen minutes. The approver's row is locked FOR UPDATE for the whole check, so parallel
 *             guesses at one approver are counted one after another and cannot outrun the lock. A good code issues a single-use grant
 *             bound to the terminal, the employee, the cashier and a ceiling amount; its random token is returned once and only its
 *             SHA-256 is stored.
 *  consume()  Checkout, only when the charge would exceed the limit, UNDER the employee's row lock and inside the sale's transaction:
 *             locks the grant row (FOR UPDATE, so a parallel replay waits and then finds it consumed), checks every binding and the
 *             approver's present eligibility, and marks it consumed. A rolled-back sale rolls the consumption back with it.
 *
 * Lock order (architecture.md): ... employee -> credit_override_grant -> sale. grant() locks one user_override_codes row and nothing else;
 * no path takes a grant row before an employee row.
 */
final class CreditOverrideService
{
    public function __construct(
        private readonly OverrideAuditor $auditor,
        private readonly CreditLedger $ledger,
    ) {}

    /**
     * @return array{grant: CreditOverrideGrant, token: string, approver: User}
     *
     * @throws CreditOverrideException
     */
    public function grant(User $cashier, Terminal $terminal, string $approverId, string $code, string $employeeId, Money $amount): array
    {
        $storeId = $terminal->store_id;
        $employee = Employee::where('store_id', $storeId)->find($employeeId) ?? throw EmployeeNotFoundException::forId($employeeId);
        if (! $employee->active) {
            throw EmployeeInactiveException::forCode($employee->employee_code);
        }

        $context = ['approver_id' => $approverId, 'employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'amount' => $amount->toApiString()];

        // Nobody approves their own sale. Said plainly (the cashier knows who they are) and checked before any code is tried or counted.
        if ($approverId === $cashier->id) {
            $this->refuse($storeId, $cashier->id, $terminal->id, 'SELF_APPROVAL', $context);
            throw CreditOverrideException::notAllowed('SELF_APPROVAL', 'You cannot approve your own sale. Ask another manager.');
        }

        $approver = User::where('store_id', $storeId)->find($approverId);
        $ineligible = $this->ineligibleReason($approver);
        if ($ineligible !== null) {
            // The same single generic answer as a wrong code, after a hash check of the same cost, so neither the message nor the time
            // says whether this approver exists, is active, may approve or has a code.
            Hash::check($code, $this->decoyHash());
            $this->refuse($storeId, $cashier->id, $terminal->id, $ineligible, $context);
            throw CreditOverrideException::invalid($ineligible);
        }

        $max = (int) config('tindaflow.credit_override.max_wrong_codes');
        $lockMinutes = (int) config('tindaflow.credit_override.lock_minutes');
        $this->assertNotInsideATransaction();

        $outcome = DB::transaction(function () use ($cashier, $terminal, $approver, $code, $employee, $amount, $context, $max, $lockMinutes): array {
            $row = UserOverrideCode::lockForUpdate()->find($approver->id);
            if ($row === null || ! $row->hasCode()) {
                // Lost a race with an administrator's reset between the eligibility read and the lock.
                Hash::check($code, $this->decoyHash());
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'NO_CODE', $context);

                return ['refusal' => CreditOverrideException::invalid('NO_CODE')];
            }

            $now = now();
            if ($row->isLockedAt($now)) {
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'APPROVER_LOCKED', $context);

                return ['refusal' => CreditOverrideException::locked('APPROVER_LOCKED', (int) ceil($now->diffInSeconds($row->locked_until, true) / 60))];
            }

            if (! Hash::check($code, $row->code_hash)) {
                $failed = $row->failed_attempts + 1;
                if ($failed >= $max) {
                    $row->forceFill(['failed_attempts' => 0, 'locked_until' => $now->copy()->addMinutes($lockMinutes)])->save();
                    $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'WRONG_CODE', $context);
                    $this->auditor->locked($terminal->store_id, $cashier->id, $terminal->id, $approver->id, $lockMinutes, $context);

                    return ['refusal' => CreditOverrideException::locked('WRONG_CODE', $lockMinutes)];
                }
                $row->forceFill(['failed_attempts' => $failed])->save();
                $this->auditor->refused($terminal->store_id, $cashier->id, $terminal->id, 'WRONG_CODE', $context);

                return ['refusal' => CreditOverrideException::invalid('WRONG_CODE')];
            }

            if ($row->failed_attempts !== 0) {
                $row->forceFill(['failed_attempts' => 0])->save();
            }

            $token = bin2hex(random_bytes(32));
            $grant = CreditOverrideGrant::create([
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
                'employee_id' => $employee->id,
                'cashier_id' => $cashier->id,
                'approver_id' => $approver->id,
                'max_amount' => $amount->toApiString(),
                'token_hash' => CreditOverrideGrant::hashToken($token),
                'expires_at' => $now->copy()->addMinutes((int) config('tindaflow.credit_override.grant_ttl_minutes')),
                'created_at' => $now, // the application's clock, like expires_at and the consumption check, so all three always agree
            ]);
            $status = $this->ledger->status($employee, $now);
            $this->auditor->granted($cashier, $terminal->id, $grant, $context + ['employee_code' => $employee->employee_code, 'limit' => $status['limit'], 'used' => $status['used']]);

            return ['grant' => $grant, 'token' => $token, 'approver' => $approver];
        });

        if (isset($outcome['refusal'])) {
            throw $outcome['refusal'];
        }

        return $outcome;
    }

    /**
     * Called by checkout, under the employee lock and inside the sale's transaction, only when the charge would exceed the limit.
     * Returns the consumed grant. Every refusal is a CreditOverrideException carrying the audit context; the CALLER writes the
     * CREDIT_OVERRIDE_REFUSED event after its transaction has rolled back (an event written inside it would roll back too).
     *
     * @throws CreditOverrideException
     */
    public function consume(string $token, Terminal $terminal, string $cashierId, Employee $employee, Money $total, CarbonInterface $now): CreditOverrideGrant
    {
        $context = ['employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'amount' => $total->toApiString()];

        $grant = CreditOverrideGrant::where('token_hash', CreditOverrideGrant::hashToken($token))->lockForUpdate()->first();
        if ($grant === null || $grant->store_id !== $terminal->store_id) {
            throw CreditOverrideException::invalid('TOKEN_UNKNOWN', $context);
        }
        $context += ['grant_id' => $grant->id, 'approver_id' => $grant->approver_id];

        $binding = match (true) {
            $grant->terminal_id !== $terminal->id => 'WRONG_TERMINAL',
            $grant->employee_id !== $employee->id => 'WRONG_EMPLOYEE',
            $grant->cashier_id !== $cashierId => 'WRONG_CASHIER',
            default => null,
        };
        if ($binding !== null) {
            throw CreditOverrideException::notAllowed($binding, 'This approval was given for another till, employee or cashier. Ask a manager to approve this sale.', $context);
        }
        if ($grant->consumed_at !== null) {
            throw CreditOverrideException::expired('ALREADY_USED', $context);
        }
        if ($grant->expires_at->lessThanOrEqualTo($now)) {
            throw CreditOverrideException::expired('EXPIRED', $context);
        }
        if ($total->greaterThan(Money::fromApiString((string) $grant->max_amount))) {
            throw CreditOverrideException::notAllowed(
                'AMOUNT_ABOVE_CEILING',
                "This approval covers up to P{$grant->max_amount}; this sale is P{$total->toApiString()}. Ask a manager to approve the new amount.",
                $context,
            );
        }

        $approver = User::where('store_id', $grant->store_id)->find($grant->approver_id);
        if ($approver === null || ! $approver->active || ! RoleCapabilityCatalog::hasForUser($approver, OverrideCodeService::CAPABILITY) || $approver->id === $cashierId) {
            throw CreditOverrideException::notAllowed('APPROVER_NO_LONGER_ELIGIBLE', 'The manager who approved this can no longer approve it. Ask another manager.', $context);
        }

        // The lock on the row makes this the only consumer; the WHERE keeps it honest even if that ever changed.
        $updated = DB::table('credit_override_grants')->where('id', $grant->id)->whereNull('consumed_at')->update(['consumed_at' => $now]);
        if ($updated !== 1) {
            throw CreditOverrideException::expired('ALREADY_USED', $context);
        }
        $grant->consumed_at = $now;

        return $grant;
    }

    /**
     * CREDIT_OVERRIDE_USED: written by checkout in the sale's own transaction, after the sale row exists, so it commits or rolls back with it.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordUsed(string $storeId, string $cashierId, string $terminalId, CreditOverrideGrant $grant, array $context): void
    {
        $this->auditor->used($storeId, $cashierId, $terminalId, $grant, $context);
    }

    /** The audit line for a refusal that happened inside a transaction which has since rolled back. */
    public function recordCheckoutRefusal(CreditOverrideException $refusal, string $storeId, string $cashierId, string $terminalId): void
    {
        $this->refuse($storeId, $cashierId, $terminalId, $refusal->reason, $refusal->auditContext + ['stage' => 'CHECKOUT']);
    }

    /** @param  array<string, mixed>  $context */
    private function refuse(string $storeId, string $cashierId, string $terminalId, string $reason, array $context): void
    {
        $this->auditor->refused($storeId, $cashierId, $terminalId, $reason, $context);
    }

    private function ineligibleReason(?User $approver): ?string
    {
        if ($approver === null) {
            return 'UNKNOWN_APPROVER';
        }
        if (! $approver->active) {
            return 'APPROVER_INACTIVE';
        }
        if (! RoleCapabilityCatalog::hasForUser($approver, OverrideCodeService::CAPABILITY)) {
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
     * holds already: 0, except in tests/Database, whose base class wraps every test in one (CreditOverrideLockoutTest runs with 0 and
     * proves both the guard and that the count is really committed).
     */
    private function assertNotInsideATransaction(): void
    {
        if (DB::transactionLevel() > (int) config('tindaflow.credit_override.base_transaction_level', 0)) {
            throw new \LogicException('Override codes must be verified outside any transaction, or a rollback would erase the failed-attempt count.');
        }
    }
}
