<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_REQUEST_REUSED: the same request_id was sent with different content than the write-off it created. */
final class WriteOffRequestReusedException extends DomainException
{
    public static function forRequest(string $requestId, string $existingWriteOffId): self
    {
        return new self('This request was already used for a different write-off. Reload the page and try again.', ['request_id' => $requestId, 'write_off_id' => $existingWriteOffId]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_REQUEST_REUSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
