<?php

namespace App\Domain\Exceptions;

/** invariants.md #2/#69, BIR-014 / error-catalog.md FISCAL_DAY_CLOSED. */
final class FiscalDayClosedException extends DomainException
{
    public static function forFiscalDay(string $fiscalDayId): self
    {
        return new self('The relevant fiscal day is already closed.', ['fiscal_day_id' => $fiscalDayId]);
    }

    /** invariants.md #69: the executing terminal has no OPEN fiscal day. */
    public static function noOpenDayAtTerminal(string $terminalId): self
    {
        return new self('This terminal has no open fiscal day, so this cannot be processed here.', ['terminal_id' => $terminalId]);
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
