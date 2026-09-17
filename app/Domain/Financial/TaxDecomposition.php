<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/** Result of TaxCalculator::decompose() -- net sales + VAT for one tax category. */
final readonly class TaxDecomposition
{
    public function __construct(
        public Money $net,
        public Money $vat,
    ) {}
}
