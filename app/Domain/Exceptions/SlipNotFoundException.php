<?php

namespace App\Domain\Exceptions;

/** error-catalog.md SLIP_NOT_FOUND: the slip does not exist, or belongs to another store (indistinguishable). */
final class SlipNotFoundException extends DomainException
{
    public static function forId(string $slipId): self
    {
        return new self('The referenced slip does not exist.', ['slip_id' => $slipId]);
    }

    public function errorCode(): string
    {
        return 'SLIP_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
