<?php

namespace App\Domain\Exceptions;

/** error-catalog.md EMPLOYEE_NOT_FOUND: no such employee in this store (an id of another store, or an unknown code at the till). */
final class EmployeeNotFoundException extends DomainException
{
    public static function forId(string $employeeId): self
    {
        return new self("Employee \"{$employeeId}\" was not found.", ['employee_id' => $employeeId]);
    }

    public static function forCode(string $code): self
    {
        return new self('No employee has this code. Check the ID card or type it again.', ['employee_code' => $code]);
    }

    public function errorCode(): string
    {
        return 'EMPLOYEE_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
