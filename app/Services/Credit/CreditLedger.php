<?php

namespace App\Services\Credit;

use App\Domain\CutOff;
use App\Domain\Exceptions\CreditLimitExceededException;
use App\Domain\Exceptions\EmployeeInactiveException;
use App\Domain\Exceptions\EmployeeNotFoundException;
use App\Domain\Money;
use App\Models\Employee;
use App\Models\EmployeeCreditEntry;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Services\Employees\CreditPolicyService;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * ADR-016 section 5: the credit ledger and the limit. Everything about what an employee owes is derived here from the append-only
 * `employee_credit_entries` rows (CHARGE minus REVERSAL for one cut-off period); nothing stores a balance.
 *
 * The limit check is only sound under the employee row's lock (invariants.md CREDIT-003): checkout, void and refund call
 * lockEmployee() first, in the Global Lock Order (after shift and fiscal day, before the sale), and only then read the balance and
 * write an entry, so two tills charging one employee queue behind each other and the second sees the first's charge.
 */
final class CreditLedger
{
    public function __construct(private readonly CreditPolicyService $policies) {}

    public function cutOffAt(CarbonInterface $instant): CutOff
    {
        return CutOff::containing($instant, (string) config('app.timezone'));
    }

    /**
     * Takes the employee row FOR UPDATE (Global Lock Order position 2). Scoped to the store: another store's employee is not found.
     * Does not check that the employee is active, because a void or a refund of an old sale must work for a deactivated employee.
     */
    public function lockEmployee(GlobalLockOrder $lockOrder, string $storeId, string $employeeId): Employee
    {
        $lockOrder->acquire(LockableResource::Employee);

        return Employee::where('store_id', $storeId)->whereKey($employeeId)->lockForUpdate()->first()
            ?? throw EmployeeNotFoundException::forId($employeeId);
    }

    /** The locking read a NEW charge needs: the employee must also be active. */
    public function lockEmployeeForCharge(GlobalLockOrder $lockOrder, string $storeId, string $employeeId): Employee
    {
        $employee = $this->lockEmployee($lockOrder, $storeId, $employeeId);
        if (! $employee->active) {
            throw EmployeeInactiveException::forCode($employee->employee_code);
        }

        return $employee;
    }

    /** What the employee owes for one cut-off period: SUM(CHARGE) - SUM(REVERSAL) of the rows carrying that period's start date. */
    public function usedIn(string $employeeId, CutOff $period): Money
    {
        $net = DB::table('employee_credit_entries')
            ->where('employee_id', $employeeId)
            ->where('period_start', $period->startDate())
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'CHARGE' THEN amount ELSE -amount END), 0) AS net")
            ->value('net');

        return Money::fromApiString(bcadd((string) $net, '0', 2));
    }

    /**
     * The limit, what is used and what remains for the cut-off containing $at (the figures the till shows). remaining can be negative if a
     * limit was lowered below what is already used.
     *
     * @return array{employee_id: string, employee_code: string, employee_type: string, limit: string, used: string, remaining: string, period_start: string, period_end: string}
     */
    public function status(Employee $employee, CarbonInterface $at): array
    {
        $period = $this->cutOffAt($at);
        $limit = Money::fromApiString($this->policies->limitFor($employee->store_id, $employee->employee_type));
        $used = $this->usedIn($employee->id, $period);

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_type' => $employee->employee_type->value,
            'limit' => $limit->toApiString(),
            'used' => $used->toApiString(),
            'remaining' => $limit->subtract($used)->toApiString(),
            'period_start' => $period->startDate(),
            'period_end' => $period->endDate(),
        ];
    }

    /**
     * CREDIT-003: used + this charge must not exceed the limit. Exactly meeting it is allowed. Call under the employee lock. A caller
     * holding a valid supervisor override (OVR-001, OVR-002, CreditOverrideService) catches the exception and carries on; the
     * rule itself is unchanged and nothing here knows about overrides.
     *
     * @throws CreditLimitExceededException
     */
    public function assertWithinLimit(Employee $employee, Money $charge, CarbonInterface $at): void
    {
        $status = $this->status($employee, $at);
        $remaining = Money::fromApiString($status['remaining']);

        if ($charge->greaterThan($remaining)) {
            throw CreditLimitExceededException::forAmounts(
                $employee->employee_code, $status['limit'], $status['used'], $status['remaining'], $charge->toApiString(),
                $status['period_start'], $status['period_end'],
            );
        }
    }

    /** $overrideGrantId names the supervisor override grant when the charge went past the limit (OVR-001); null for every ordinary charge. */
    public function recordCharge(Employee $employee, Sale $sale, Money $amount, CarbonInterface $at, ?string $overrideGrantId = null): EmployeeCreditEntry
    {
        $period = $this->cutOffAt($at);

        return EmployeeCreditEntry::create([
            'store_id' => $sale->store_id,
            'employee_id' => $employee->id,
            'entry_type' => EmployeeCreditEntry::CHARGE,
            'amount' => $amount->toApiString(),
            'period_start' => $period->startDate(),
            'period_end' => $period->endDate(),
            'sale_id' => $sale->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->fullName(),
            'employee_type' => $employee->employee_type->value,
            'billing_party_id' => $employee->billing_party_id,
            'recorded_at' => $at,
            'override_grant_id' => $overrideGrantId,
        ]);
    }

    /** A void takes the whole charge back. The reversal carries the ORIGINAL charge's period, however long ago that was. */
    public function recordReversalForVoid(Sale $sale, SaleVoid $void, CarbonInterface $at): EmployeeCreditEntry
    {
        $charge = $this->chargeOf($sale);

        return $this->reverse($charge, $charge->amount, $at, voidId: $void->id);
    }

    /** A refund takes back what it refunded, in the original charge's period. */
    public function recordReversalForRefund(Sale $sale, Refund $refund, Money $amount, CarbonInterface $at): EmployeeCreditEntry
    {
        return $this->reverse($this->chargeOf($sale), $amount->toApiString(), $at, refundId: $refund->id);
    }

    private function chargeOf(Sale $sale): EmployeeCreditEntry
    {
        return EmployeeCreditEntry::where('sale_id', $sale->id)->where('entry_type', EmployeeCreditEntry::CHARGE)->first()
            ?? throw new LogicException("Sale {$sale->id} names an employee but has no credit charge.");
    }

    private function reverse(EmployeeCreditEntry $charge, string $amount, CarbonInterface $at, ?string $voidId = null, ?string $refundId = null): EmployeeCreditEntry
    {
        return EmployeeCreditEntry::create([
            'store_id' => $charge->store_id,
            'employee_id' => $charge->employee_id,
            'entry_type' => EmployeeCreditEntry::REVERSAL,
            'amount' => $amount,
            'period_start' => $charge->period_start->toDateString(),
            'period_end' => $charge->period_end->toDateString(),
            'sale_id' => $charge->sale_id,
            'void_id' => $voidId,
            'refund_id' => $refundId,
            'employee_code' => $charge->employee_code,
            'employee_name' => $charge->employee_name,
            'employee_type' => $charge->employee_type,
            'billing_party_id' => $charge->billing_party_id,
            'recorded_at' => $at,
        ]);
    }
}
