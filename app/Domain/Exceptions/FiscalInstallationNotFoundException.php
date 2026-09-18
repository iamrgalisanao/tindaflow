<?php

namespace App\Domain\Exceptions;

/** error-catalog.md FISCAL_INSTALLATION_NOT_FOUND. */
final class FiscalInstallationNotFoundException extends DomainException
{
    public static function forId(string $fiscalInstallationId): self
    {
        return new self(
            "Fiscal installation \"{$fiscalInstallationId}\" was not found.",
            ['fiscal_installation_id' => $fiscalInstallationId],
        );
    }

    public function errorCode(): string
    {
        return 'FISCAL_INSTALLATION_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
