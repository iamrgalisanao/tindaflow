<?php

namespace App\Domain\Financial;

use App\Domain\Money;
use App\Domain\Quantity;

/** One sale_item's pre-calculation input, in the shape FinancialCalculator needs -- not an Eloquent model. */
final readonly class SaleLineInput
{
    /** @param  'VATABLE'|'VAT_EXEMPT'|'ZERO_RATED'|'NON_VAT'  $taxClassification */
    public function __construct(
        public int $lineNumber,
        public Quantity $quantity,
        public Money $unitPrice,
        public Money $lineDiscountAmount,
        public bool $orderDiscountEligible,
        public string $taxClassification,
    ) {}
}
