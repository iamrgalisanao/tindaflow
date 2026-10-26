<?php

namespace App\Domain\Exceptions;

/** error-catalog.md WRITE_OFF_CHEQUE_INVALID: the cheque a write-off is linked to is not a RETURNED cheque of this store that paid that period. */
final class WriteOffChequeInvalidException extends DomainException
{
    public static function notFound(string $chequeId): self
    {
        return new self('The cheque you linked does not exist.', ['cheque_id' => $chequeId, 'problem' => 'not_found']);
    }

    public static function notReturned(string $chequeId, string $number): self
    {
        return new self("Cheque {$number} has not been marked returned. Only a returned cheque can be linked to a write-off; mark it returned first.", ['cheque_id' => $chequeId, 'problem' => 'not_returned']);
    }

    public static function didNotPayPeriod(string $chequeId, string $number, string $periodStart): self
    {
        return new self("Cheque {$number} did not pay the period starting {$periodStart}, so it cannot be linked to a write-off of that period.", ['cheque_id' => $chequeId, 'problem' => 'wrong_period', 'period_start' => $periodStart]);
    }

    public function errorCode(): string
    {
        return 'WRITE_OFF_CHEQUE_INVALID';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
