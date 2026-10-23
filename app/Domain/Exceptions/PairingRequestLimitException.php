<?php

namespace App\Domain\Exceptions;

/** error-catalog.md PAIRING_REQUEST_LIMIT (ADR-015): too many till requests are waiting for approval at once. */
final class PairingRequestLimitException extends DomainException
{
    public static function make(int $limit): self
    {
        return new self("There are already {$limit} till requests waiting for approval. Ask an administrator to approve or decline them, or try again in a few minutes.", ['limit' => $limit]);
    }

    public function errorCode(): string
    {
        return 'PAIRING_REQUEST_LIMIT';
    }

    public function httpStatus(): int
    {
        return 429;
    }
}
