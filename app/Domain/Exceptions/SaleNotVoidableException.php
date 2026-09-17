<?php

namespace App\Domain\Exceptions;

/**
 * domain-model.md §2.8 five-part eligibility gate / error-catalog.md
 * SALE_NOT_VOIDABLE. Used both at void request time and, re-checked, at
 * approval/execution time -- a failed execution-time recheck must leave
 * the void REQUESTED, never auto-REJECTED (Stage 2 amendment pass 4);
 * throwing this exception inside a transaction that then rolls back is
 * exactly what delivers that guarantee (see IdempotencyService).
 */
final class SaleNotVoidableException extends DomainException
{
    public static function becauseNotEligible(string $saleId, string $reason): self
    {
        return new self("This sale cannot be voided: {$reason}", ['sale_id' => $saleId, 'reason' => $reason]);
    }

    public function errorCode(): string
    {
        return 'SALE_NOT_VOIDABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
