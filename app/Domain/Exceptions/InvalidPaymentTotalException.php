<?php

namespace App\Domain\Exceptions;

/** error-catalog.md INVALID_PAYMENT_TOTAL: structurally invalid payment total (e.g. negative or zero with no credit-sale support). */
final class InvalidPaymentTotalException extends DomainException
{
    public static function becauseNonPositive(string $amount): self
    {
        return new self(
            'Each payment amount must be strictly positive; V1 has no credit-sale support.',
            ['amount' => $amount]
        );
    }

    public function errorCode(): string
    {
        return 'INVALID_PAYMENT_TOTAL';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
