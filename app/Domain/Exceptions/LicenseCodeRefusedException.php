<?php

namespace App\Domain\Exceptions;

/** error-catalog.md LICENSE_CODE_REFUSED: an offline code was not applied (ADR-018 decisions 3 and 6). Nothing was changed. */
final class LicenseCodeRefusedException extends DomainException
{
    public static function make(string $why): self
    {
        return new self($why.' Nothing was changed.');
    }

    public function errorCode(): string
    {
        return 'LICENSE_CODE_REFUSED';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
