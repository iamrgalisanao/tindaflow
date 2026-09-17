<?php

namespace App\Domain\Financial;

use App\Domain\Money;
use InvalidArgumentException;

/**
 * Order-level discount allocation (domain-model.md §2.7a/§3.1,
 * invariants.md DISC-001/002/003/005/006). Applies the domain's
 * eligibility rule (order_discount_eligible) on top of the generic
 * Deterministic Proportional Allocation primitive (Money::allocate()),
 * which owns the largest-remainder mechanics shared with
 * TaxCalculator's per-line VAT allocation.
 */
final class DiscountAllocator
{
    /**
     * @param  array<int, DiscountAllocationLine>  $lines  MUST be supplied in ascending line_number order
     *                                                     (DISC-005's deterministic tie-breaker).
     * @return array<int, Money> line_number => allocated_order_discount_amount, zero for ineligible lines
     */
    public function allocateOrderDiscount(Money $orderLevelDiscountAmount, array $lines): array
    {
        $eligible = array_filter($lines, fn (DiscountAllocationLine $line) => $line->eligible);

        if ($eligible === []) {
            if (! $orderLevelDiscountAmount->isZero()) {
                throw new InvalidArgumentException(
                    'A non-zero order-level discount has no order_discount_eligible line to allocate against.'
                );
            }

            return array_map(fn (DiscountAllocationLine $line) => Money::zero(), $this->keyByLineNumber($lines));
        }

        $weights = [];
        foreach ($eligible as $line) {
            // Basis: gross_line_amount - line_discount_amount (domain-model.md §2.7a).
            $basis = $line->grossLineAmount->subtract($line->lineDiscountAmount);
            $weights[$line->lineNumber] = $basis->amount();
        }

        $allocated = $orderLevelDiscountAmount->allocate($weights);

        $result = [];
        foreach ($lines as $line) {
            $result[$line->lineNumber] = $allocated[$line->lineNumber] ?? Money::zero();
        }

        return $result;
    }

    /** @param  array<int, DiscountAllocationLine>  $lines */
    private function keyByLineNumber(array $lines): array
    {
        $keyed = [];
        foreach ($lines as $line) {
            $keyed[$line->lineNumber] = $line;
        }

        return $keyed;
    }
}
