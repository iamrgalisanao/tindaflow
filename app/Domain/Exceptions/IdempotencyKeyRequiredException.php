<?php

namespace App\Domain\Exceptions;

/** error-catalog.md IDEMPOTENCY_KEY_REQUIRED (ADR-010). */
final class IdempotencyKeyRequiredException extends DomainException
{
    public static function make(): self
    {
        return new self('The Idempotency-Key header is required for this operation.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REQUIRED';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
