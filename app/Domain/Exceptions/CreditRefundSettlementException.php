<?php

namespace App\Domain\Exceptions;

/** invariants.md CREDIT-002: a credit sale is refunded to credit (never cash) and only a credit sale is. error-catalog.md CREDIT_REFUND_SETTLEMENT_INVALID (422). */
final class CreditRefundSettlementException extends DomainException
{
    public static function creditSaleNeedsCredit(): self
    {
        return new self('A sale paid with credit is refunded to credit: the employee\'s balance is reduced and no cash leaves the drawer. Settle the refund with CREDIT.');
    }

    public static function creditOnACashSale(): self
    {
        return new self('Only a sale that was paid with credit can be refunded to credit.');
    }

    public function errorCode(): string
    {
        return 'CREDIT_REFUND_SETTLEMENT_INVALID';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
