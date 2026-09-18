<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md SHIFT_ALREADY_OPEN. Deliberately non-enumerating
 * (invariants.md #33/#34): this cashier already has an open shift
 * (possibly on a different terminal) OR this terminal already has an
 * open shift (possibly a different cashier) -- the frozen contract
 * describes one outcome, not two codes to split across.
 */
final class ShiftAlreadyOpenException extends DomainException
{
    public static function make(): self
    {
        return new self('This cashier or this terminal already has an open shift.');
    }

    public function errorCode(): string
    {
        return 'SHIFT_ALREADY_OPEN';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
