<?php

namespace App\Domain\Exceptions;

/** invariants.md SLIP-001 / error-catalog.md SALE_DOCUMENT_MODE_CHANGE_BLOCKED: the mode only changes while no shift or business day is open. */
final class SaleDocumentModeChangeBlockedException extends DomainException
{
    public static function becauseSomethingIsOpen(int $openShifts, int $openFiscalDays): self
    {
        return new self(
            'The way sales are documented cannot be changed while a shift or business day is open. Close every shift and the business day on every terminal first.',
            ['open_shifts' => $openShifts, 'open_fiscal_days' => $openFiscalDays],
        );
    }

    public function errorCode(): string
    {
        return 'SALE_DOCUMENT_MODE_CHANGE_BLOCKED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
