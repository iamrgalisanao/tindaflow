<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INSUFFICIENT_PAYMENT: sum of payments is less than the server-computed grand total. */
final class InsufficientPaymentException extends DomainException
{
    public static function forTotals(string $totalPayments, string $grandTotal): self
    {
        return new self(
            'The sum of payments is less than the computed grand total.',
            ['total_payments' => $totalPayments, 'grand_total' => $grandTotal]
        );
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_PAYMENT';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
