<?php

namespace App\Domain\Exceptions;

/** ADR-010: same (terminal, key) presented with a different request hash. */
final class IdempotencyKeyReusedException extends DomainException
{
    public static function forKey(string $terminalId, string $idempotencyKey): self
    {
        return new self(
            'This idempotency key was already used for a different request.',
            ['terminal_id' => $terminalId, 'idempotency_key' => $idempotencyKey]
        );
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
