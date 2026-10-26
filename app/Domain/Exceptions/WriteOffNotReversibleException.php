<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_NOT_REVERSIBLE: the row is itself a reversal; a reversal cannot be reversed (make a new write-off instead). */
final class WriteOffNotReversibleException extends DomainException
{
    public static function forReversal(string $writeOffId): self
    {
        return new self('That entry is a reversal, and a reversal cannot be reversed. If the amount should be written off again, make a new write-off.', ['write_off_id' => $writeOffId]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_NOT_REVERSIBLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
