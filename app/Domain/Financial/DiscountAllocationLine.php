<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * Input DTO for DiscountAllocator/TaxCalculator -- deliberately a plain
 * data holder, not an Eloquent model, so the calculator stays decoupled
 * from persistence (ADR-012: controllers/services persist its output,
 * they don't hand it a model to mutate).
 */
final readonly class DiscountAllocationLine
{
    public function __construct(
        public int $lineNumber,
        public Money $grossLineAmount,
        public Money $lineDiscountAmount,
        public bool $eligible,
    ) {}
}
