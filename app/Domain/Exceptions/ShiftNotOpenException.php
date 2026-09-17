<?php

namespace App\Domain\Exceptions;

/** invariants.md #35 / error-catalog.md SHIFT_NOT_OPEN: referenced shift is not open. */
final class ShiftNotOpenException extends DomainException
{
    public static function forShift(string $shiftId): self
    {
        return new self('The referenced shift is not open.', ['shift_id' => $shiftId]);
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
