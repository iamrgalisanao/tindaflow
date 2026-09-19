<?php

namespace App\Domain\Exceptions;

/** invariants.md #25/#29 / error-catalog.md REFUND_NOT_ALLOWED: a voided sale is not a valid refund target. */
final class RefundNotAllowedException extends DomainException
{
    public static function becauseSaleVoided(string $saleId): self
    {
        return new self('A voided sale cannot be refunded.', ['sale_id' => $saleId]);
    }

    public function errorCode(): string
    {
        return 'REFUND_NOT_ALLOWED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
