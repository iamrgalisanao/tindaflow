<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md PAIRING_REQUEST_INVALID (ADR-015): one deliberately non-enumerating outcome for every way a till pairing request can be
 * unusable -- unknown, someone else's, wrong secret, expired, denied, already used, or too many wrong codes -- following
 * ENROLLMENT_TOKEN_INVALID's reasoning rather than a separate code per case.
 */
final class PairingRequestInvalidException extends DomainException
{
    public static function make(): self
    {
        return new self('This till request does not exist, has expired, was declined, or has already been used. Ask for a new one.');
    }

    public function errorCode(): string
    {
        return 'PAIRING_REQUEST_INVALID';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
