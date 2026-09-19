<?php

namespace App\Domain\Exceptions;

/** invariants.md #35 / error-catalog.md SHIFT_NOT_OPEN: referenced shift is not open. */
final class ShiftNotOpenException extends DomainException
{
    public static function forShift(string $shiftId): self
    {
        return new self('The referenced shift is not open.', ['shift_id' => $shiftId]);
    }

    /** invariants.md #69: the executing user has no OPEN shift at the executing terminal. */
    public static function forExecutingUser(string $terminalId, string $userId): self
    {
        return new self('You have no open shift at this terminal, so this cannot be processed here.', ['terminal_id' => $terminalId, 'user_id' => $userId]);
    }

    public function errorCode(): string
    {
        return 'SHIFT_NOT_OPEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
