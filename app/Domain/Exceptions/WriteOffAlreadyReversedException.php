<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_ALREADY_REVERSED: the write-off was already cancelled; a reversal is final (write it off again as a new write-off). */
final class WriteOffAlreadyReversedException extends DomainException
{
    public static function forWriteOff(string $writeOffId, string $reversalId): self
    {
        return new self('This write-off was already reversed. If the amount should be written off again, make a new write-off.', ['write_off_id' => $writeOffId, 'reversal_id' => $reversalId]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_ALREADY_REVERSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
