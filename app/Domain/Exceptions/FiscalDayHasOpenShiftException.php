<?php

namespace App\Domain\Exceptions;

/** invariants.md #36 / error-catalog.md FISCAL_DAY_HAS_OPEN_SHIFT. */
final class FiscalDayHasOpenShiftException extends DomainException
{
    public static function forFiscalDay(string $fiscalDayId): self
    {
        return new self(
            'This fiscal day cannot close while a shift referencing it is still open.',
            ['fiscal_day_id' => $fiscalDayId],
        );
    }

    public function errorCode(): string
    {
        return 'FISCAL_DAY_HAS_OPEN_SHIFT';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
