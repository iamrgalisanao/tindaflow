<?php

namespace App\Domain\Exceptions;

/** error-catalog.md VOID_NOT_FOUND: the void does not exist, or belongs to another store. */
final class VoidNotFoundException extends DomainException
{
    public static function forId(string $voidId): self
    {
        return new self('The referenced void does not exist.', ['void_id' => $voidId]);
    }

    public function errorCode(): string
    {
        return 'VOID_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
