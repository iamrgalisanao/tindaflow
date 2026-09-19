<?php

namespace App\Domain\Exceptions;

/** invariants.md #70 / error-catalog.md REFUND_SETTLEMENT_MISMATCH: SUM(settlements.amount) must equal the refund total derived from the sale lines. */
final class RefundSettlementMismatchException extends DomainException
{
    public static function forTotals(string $settled, string $derived): self
    {
        return new self('The settlement amounts must add up to the refund total.', ['settlement_total' => $settled, 'refund_total' => $derived]);
    }

    public function errorCode(): string
    {
        return 'REFUND_SETTLEMENT_MISMATCH';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
