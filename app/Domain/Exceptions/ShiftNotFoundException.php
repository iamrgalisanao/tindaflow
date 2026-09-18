<?php

namespace App\Domain\Exceptions;

/** error-catalog.md SHIFT_NOT_FOUND. */
final class ShiftNotFoundException extends DomainException
{
    public static function forId(string $shiftId): self
    {
        return new self("Shift \"{$shiftId}\" was not found.", ['shift_id' => $shiftId]);
    }

    public function errorCode(): string
    {
        return 'SHIFT_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
