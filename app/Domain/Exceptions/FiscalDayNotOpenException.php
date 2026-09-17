<?php

namespace App\Domain\Exceptions;

/** error-catalog.md FISCAL_DAY_NOT_OPEN: operation requires an open fiscal day; none exists at this terminal. */
final class FiscalDayNotOpenException extends DomainException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self('This operation requires an open fiscal day, and none exists at this terminal.', ['terminal_id' => $terminalId]);
    }

    public function errorCode(): string
    {
        return 'FISCAL_DAY_NOT_OPEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
