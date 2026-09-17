<?php

namespace App\Domain\Exceptions;

/** error-catalog.md SHIFT_REQUIRED: operation requires an open shift; none exists at this terminal. */
final class ShiftRequiredException extends DomainException
{
    public static function forTerminal(string $terminalId): self
    {
        return new self('This operation requires an open shift, and none exists at this terminal.', ['terminal_id' => $terminalId]);
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
