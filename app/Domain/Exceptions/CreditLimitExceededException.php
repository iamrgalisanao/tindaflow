<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md CREDIT_LIMIT_EXCEEDED: the sale would take the employee past their credit limit for the cut-off period. `details`
 * carries what the till shows: the limit, what is already used (charges minus reversals), what remains, and what this sale asks for.
 * There is no override yet (slice 3.3).
 */
final class CreditLimitExceededException extends DomainException
{
    public static function forAmounts(string $employeeCode, string $limit, string $used, string $remaining, string $requested, string $periodStart, string $periodEnd): self
    {
        return new self(
            "This sale would take {$employeeCode} past the credit limit of P{$limit} for the period {$periodStart} to {$periodEnd}: P{$used} is already used, P{$remaining} remains, and this sale is P{$requested}.",
            [
                'employee_code' => $employeeCode, 'limit' => $limit, 'used' => $used, 'remaining' => $remaining, 'requested' => $requested,
                'period_start' => $periodStart, 'period_end' => $periodEnd,
            ],
        );
    }

    public function errorCode(): string
    {
        return 'CREDIT_LIMIT_EXCEEDED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
