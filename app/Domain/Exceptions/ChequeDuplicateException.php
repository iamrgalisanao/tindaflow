<?php

namespace App\Domain\Exceptions;

/** error-catalog.md CHEQUE_DUPLICATE: a cheque with this bank and number is already recorded and not returned (entered twice, or a double click). */
final class ChequeDuplicateException extends DomainException
{
    public static function forExisting(string $existingId, string $number, string $bank, string $receivedOn, string $amount): self
    {
        return new self("A cheque numbered {$number} from {$bank} is already recorded (received {$receivedOn}, P{$amount}). If it is a different cheque, put what tells them apart in the bank field; if the bank returned it, mark it returned first.", [
            'existing_cheque_id' => $existingId, 'cheque_number' => $number, 'bank' => $bank,
        ]);
    }

    public function errorCode(): string
    {
        return 'CHEQUE_DUPLICATE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
