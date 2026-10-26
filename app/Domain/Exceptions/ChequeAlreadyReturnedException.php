<?php

namespace App\Domain\Exceptions;

/** error-catalog.md CHEQUE_ALREADY_RETURNED: the cheque was already marked returned; a returned cheque is final (a re-presented cheque is recorded as a new cheque). */
final class ChequeAlreadyReturnedException extends DomainException
{
    public static function forCheque(string $chequeId, string $number, ?string $returnedOn): self
    {
        return new self("Cheque {$number} was already marked returned".($returnedOn ? " on {$returnedOn}" : '').'. If the bank paid it later, record it again as a new cheque.', ['cheque_id' => $chequeId, 'returned_on' => $returnedOn]);
    }

    public function errorCode(): string
    {
        return 'CHEQUE_ALREADY_RETURNED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
