<?php

namespace App\Domain\Exceptions;

/** invariants.md #2/#69, BIR-014 / error-catalog.md FISCAL_DAY_CLOSED. */
final class FiscalDayClosedException extends DomainException
{
    public static function forFiscalDay(string $fiscalDayId): self
    {
        return new self('The relevant fiscal day is already closed.', ['fiscal_day_id' => $fiscalDayId]);
    }

    public function errorCode(): string
    {
        return 'FISCAL_DAY_CLOSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
