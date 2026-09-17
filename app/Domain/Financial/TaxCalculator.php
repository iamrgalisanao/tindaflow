<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * Sum-then-decompose VAT calculation and per-line tax allocation
 * (domain-model.md §3.1's "sum-then-decompose, not decompose-then-sum"
 * policy). The 12% VAT rate lives here as the one centrally-configured
 * value ADR-012 calls for -- a future rate change touches this constant
 * alone, never a rules engine.
 */
final class TaxCalculator
{
    private const VAT_RATE = '0.12';

    private const VAT_DIVISOR = '1.12'; // 1 + VAT_RATE

    /**
     * Materialization point 5 (domain-model.md §3.1): decomposes the
     * VATable-classified lines' summed net_line_amount (already exact,
     * summed at full precision, not independently rounded per line)
     * into net sales + VAT, in one rounding step.
     */
    public function decompose(Money $vatableGrossSum): TaxDecomposition
    {
        if ($vatableGrossSum->isZero()) {
            return new TaxDecomposition(Money::zero(), Money::zero());
        }

        // net = gross / 1.12, rounded half-up to 2dp; vat = gross - net
        // (subtraction of two already-2dp values needs no further rounding).
        $net = Money::fromApiString($this->divideAndRound($vatableGrossSum->amount(), self::VAT_DIVISOR));
        $vat = $vatableGrossSum->subtract($net);

        return new TaxDecomposition($net, $vat);
    }

    /**
     * Materialization point 6: allocates the sale-level VAT amount back
     * down to individual VATABLE lines using the same Deterministic
     * Proportional Allocation primitive as order-discount allocation,
     * with each line's net_line_amount as the basis. taxable_base =
     * net_line_amount - tax_amount follows with no separate rounding.
     *
     * @param  array<int, Money>  $vatableLineNetAmounts  line_number => net_line_amount, ascending line_number order
     * @return array<int, TaxAllocationLine> line_number => {taxable_base, tax_amount}
     */
    public function allocateVatToLines(Money $totalVat, array $vatableLineNetAmounts): array
    {
        $weights = array_map(fn (Money $net) => $net->amount(), $vatableLineNetAmounts);
        $allocated = $totalVat->allocate($weights);

        $result = [];
        foreach ($vatableLineNetAmounts as $lineNumber => $netLineAmount) {
            $taxAmount = $allocated[$lineNumber];
            $result[$lineNumber] = new TaxAllocationLine(
                taxableBase: $netLineAmount->subtract($taxAmount),
                taxAmount: $taxAmount,
            );
        }

        return $result;
    }

    private function divideAndRound(string $dividend, string $divisor): string
    {
        return Money::roundHalfUp(bcdiv($dividend, $divisor, 6));
    }
}
