<?php

namespace App\Domain\Financial;

use App\Domain\Money;
use App\Domain\Quantity;

/** One sale_item's fully-computed financial snapshot, ready to persist verbatim (domain-model.md §2.7). */
final readonly class SaleLineResult
{
    public function __construct(
        public int $lineNumber,
        public Quantity $quantity,
        public Money $unitPrice,
        public Money $grossLineAmount,
        public Money $lineDiscountAmount,
        public bool $orderDiscountEligible,
        public Money $allocatedOrderDiscountAmount,
        public Money $netLineAmount,
        public string $taxClassification,
        public Money $taxableBase,
        public Money $taxAmount,
    ) {}
}
