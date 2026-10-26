<?php

namespace App\Domain\Exceptions;

/** error-catalog.md CHEQUE_NOT_FOUND: no such billing cheque in this store (another store's is indistinguishable). */
final class ChequeNotFoundException extends DomainException
{
    public static function forId(string $chequeId): self
    {
        return new self('The referenced cheque does not exist.', ['cheque_id' => $chequeId]);
    }

    public function errorCode(): string
    {
        return 'CHEQUE_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
