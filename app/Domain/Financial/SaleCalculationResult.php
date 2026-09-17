<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/** Sale-level totals + every line's snapshot, ready to persist onto sale/sale_item verbatim. */
final readonly class SaleCalculationResult
{
    /** @param  array<int, SaleLineResult>  $lines  keyed by line_number */
    public function __construct(
        public Money $subtotal,
        public Money $orderLevelDiscountAmount,
        public Money $discountTotal,
        public Money $taxableSales,
        public Money $vatExemptSales,
        public Money $zeroRatedSales,
        public Money $vatAmount,
        public Money $nonVatSales,
        public Money $grandTotal,
        public array $lines,
    ) {}
}
