<?php

namespace App\Domain\Exceptions;

/** error-catalog.md REFUND_NOT_FOUND: the refund does not exist, or belongs to another store. */
final class RefundNotFoundException extends DomainException
{
    public static function forId(string $refundId): self
    {
        return new self('The referenced refund does not exist.', ['refund_id' => $refundId]);
    }

    public function errorCode(): string
    {
        return 'REFUND_NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
