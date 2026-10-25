<?php

namespace App\Domain\Exceptions;

/** error-catalog.md EMPLOYEE_INACTIVE: the code is real but the employee has been deactivated, so they cannot be charged. */
final class EmployeeInactiveException extends DomainException
{
    public static function forCode(string $code): self
    {
        return new self('This employee is inactive and cannot be charged. Ask an administrator.', ['employee_code' => $code]);
    }

    public function errorCode(): string
    {
        return 'EMPLOYEE_INACTIVE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
