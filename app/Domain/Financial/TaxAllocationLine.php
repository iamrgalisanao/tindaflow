<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/** Result of TaxCalculator::allocateVatToLines() for one line: taxable_base + tax_amount = net_line_amount always. */
final readonly class TaxAllocationLine
{
    public function __construct(
        public Money $taxableBase,
        public Money $taxAmount,
    ) {}
}
