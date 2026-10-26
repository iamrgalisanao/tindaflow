<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_NOT_FOUND: no such write-off in this store (another store's is indistinguishable). */
final class WriteOffNotFoundException extends DomainException
{
    public static function forId(string $writeOffId): self
    {
        return new self('The referenced write-off does not exist.', ['write_off_id' => $writeOffId]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
