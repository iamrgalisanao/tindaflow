<?php

namespace App\Domain\Exceptions;

/** invariants.md CREDIT-001: credit is a whole-sale tender. CREDIT_MIXED_TENDER and CREDIT_EMPLOYEE_REQUIRED (both 422). */
final class CreditTenderException extends DomainException
{
    private function __construct(private readonly string $errorKey, string $message)
    {
        parent::__construct($message);
    }

    public static function mixedWithOtherTender(): self
    {
        return new self('CREDIT_MIXED_TENDER', 'Credit must be the only payment of the sale: it cannot be combined with cash or any other payment.');
    }

    public static function employeeRequired(): self
    {
        return new self('CREDIT_EMPLOYEE_REQUIRED', 'A credit sale must name the employee it is charged to.');
    }

    public static function employeeWithoutCredit(): self
    {
        return new self('CREDIT_EMPLOYEE_REQUIRED', 'An employee can be named only on a sale paid with credit.');
    }

    public static function overrideWithoutCredit(): self
    {
        return new self('CREDIT_EMPLOYEE_REQUIRED', 'A supervisor approval can be used only on a sale paid with credit.');
    }

    public function errorCode(): string
    {
        return $this->errorKey;
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
