<?php

namespace App\Domain\Exceptions;

/** error-catalog.md SHIFT_REQUIRED: operation requires an open shift; none exists at this terminal. */
final class ShiftRequiredException extends DomainException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self('This operation requires an open shift, and none exists at this terminal.', ['terminal_id' => $terminalId]);
    }

    /**
     * Module A decision register, Ruling 1: a shift open at this terminal
     * for a DIFFERENT cashier than the one this request authenticated as
     * does not satisfy the requirement -- it is not "no shift exists",
     * it is "no shift exists for this cashier/terminal pair", but the
     * same SHIFT_REQUIRED code applies (no distinct public outcome is
     * warranted for this case).
     */
    public static function forTerminalAndCashier(string $terminalId, string $cashierId): self
    {
        return new self(
            'This operation requires an open shift belonging to the authenticated cashier at this terminal, and none exists.',
            ['terminal_id' => $terminalId, 'cashier_id' => $cashierId]
        );
    }

    public function errorCode(): string
    {
        return 'SHIFT_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
