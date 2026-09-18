<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md FISCAL_DAY_NOT_FOUND -- deliberately non-enumerating
 * between "does not exist" and (for `fiscalDayZReadingGet`) "exists but
 * has not closed yet, so has no Z-Reading".
 */
final class FiscalDayNotFoundException extends DomainException
{
    public static function forId(string $fiscalDayId): self
    {
        return new self("Fiscal day \"{$fiscalDayId}\" was not found.", ['fiscal_day_id' => $fiscalDayId]);
    }

    public function errorCode(): string
    {
        return 'FISCAL_DAY_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
