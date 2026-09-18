<?php

namespace App\Domain\Exceptions;

/** invariants.md §5 state machine / error-catalog.md SHIFT_ALREADY_CLOSED. */
final class ShiftAlreadyClosedException extends DomainException
{
    public static function forId(string $shiftId): self
    {
        return new self("Shift \"{$shiftId}\" is already closed.", ['shift_id' => $shiftId]);
    }

    public function errorCode(): string
    {
        return 'SHIFT_ALREADY_CLOSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
