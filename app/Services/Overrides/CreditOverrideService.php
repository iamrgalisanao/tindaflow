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
use App\Services\Auth\RoleCapabilityCatalog;
use App\Services\Credit\CreditLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
    private readonly ApproverCodeVerifier $verifier;

    public function __construct(
        private readonly OverrideAuditor $auditor,
        private readonly CreditLedger $ledger,
        ?ApproverCodeVerifier $verifier = null,
    ) {
        $this->verifier = $verifier ?? new ApproverCodeVerifier($auditor);
    }

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

        try {
            // The code check, the lockout and the refusal audit lines are shared with the stock override (ApproverCodeVerifier).
            return $this->verifier->verify('CREDIT', OverrideCodeService::CAPABILITY, $terminal, $cashier, $approverId, $code, $context,
                function ($row, User $approver, CarbonInterface $now) use ($cashier, $terminal, $employee, $amount, $context): array {
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
        } catch (ApproverRefusal $refusal) {
            throw match ($refusal->kind) {
                'LOCKED' => CreditOverrideException::locked($refusal->reason, (int) $refusal->minutes),
                'NOT_ALLOWED' => CreditOverrideException::notAllowed($refusal->reason, $refusal->getMessage()),
                default => CreditOverrideException::invalid($refusal->reason),
            };
        }
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
}
