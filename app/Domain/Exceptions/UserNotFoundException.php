<?php

namespace App\Domain\Exceptions;

/** error-catalog.md USER_NOT_FOUND. */
final class UserNotFoundException extends DomainException
{
    public static function forId(string $userId): self
    {
        return new self(
            "User \"{$userId}\" was not found.",
            ['user_id' => $userId],
        );
    }

    public function errorCode(): string
    {
        return 'USER_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
