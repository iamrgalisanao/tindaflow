<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * The Senior Citizen (RA 9994) / PWD (RA 10754) checkout discount: 20% off the VAT-exclusive price, plus VAT
 * exemption where the store charges VAT at all -- "if applicable" (RA 9994 s.4; RA 10754 amending RA 7277 s.32).
 * Applied to the whole sale once a single beneficiary is flagged (the pattern real POS software documents --
 * KaHero's own SC/PWD feature is a headcount toggle over the whole ticket, not a per-item eligibility check),
 * a disclosed simplification, not the only way the two laws could be read; see
 * docs/06-backend/stage-36-statutory-discount.md.
 *
 * Deliberately reuses only already-governed materialization points -- Money::percentageOf() (materialization
 * point 2) and figures FinancialCalculator::calculateSale() already produced in an unmodified first pass
 * (its own materialization point 5, TaxCalculator::decompose()) -- and introduces no new rounding rule of its
 * own. This class only combines already-rounded Money values with add()/subtract()/percentageOf(); it never
 * multiplies or divides a raw figure itself.
 *
 * Two separate statutes (RA 9994, RA 10754) produce the identical 20%+VAT-exemption treatment, so one
 * calculator serves both -- the beneficiary "type" recorded on the sale is which law is being invoked, not a
 * different formula. Solo Parent (RA 11861) is a different, smaller-scope discount on specific items with its
 * own caps and is deliberately NOT covered here -- computing it would repeat the exact mistake this class
 * exists to avoid (inventing a statutory formula without verifying it).
 */
final class StatutoryDiscountCalculator
{
    private const RATE = '0.20';

    private const BNPC_RATE = '0.05';

    private const BNPC_WEEKLY_CAP = '125.00';

    /**
     * @param  Money  $vatExclusiveVatableBase  the sale's already-net-of-other-discounts VATABLE base
     *                                          (FinancialCalculator's own taxableSales from an unmodified
     *                                          first pass) -- zero for a NON_VAT store, since no VATABLE
     *                                          line can exist there (TAX-NV-002/003).
     * @param  Money  $vatPortion  that base's VAT (the same pass's vatAmount) -- removed
     *                             entirely, not partially.
     * @param  Money  $otherEligibleNet  the sum of VAT_EXEMPT + ZERO_RATED + NON_VAT lines' net
     *                                   amounts (already had no VAT to remove; the 20% discount
     *                                   alone applies to them).
     * @return Money the combined amount to add to the sale's order-level discount -- feeding this back into
     *               an UNMODIFIED calculateSale() call (with the affected lines' classification switched to
     *               VAT_EXEMPT) reproduces "remove VAT, then take 20% off what is left" exactly, using the
     *               existing order-discount allocation to spread it back across lines and keep DISC-006
     *               (sum of net_line_amount = grand_total) intact -- no separate persistence path needed.
     */
    public function computeDiscount(Money $vatExclusiveVatableBase, Money $vatPortion, Money $otherEligibleNet): Money
    {
        $vatableDiscount = $vatPortion->add($vatExclusiveVatableBase->percentageOf(self::RATE));
        $otherDiscount = $otherEligibleNet->percentageOf(self::RATE);

        return $vatableDiscount->add($otherDiscount);
    }

    /**
     * DTI-DA-DOE JAO 24-02: 5% of the regular retail price of basic necessities and prime commodities,
     * WITHOUT VAT exemption (the price shown, VAT included, is the base), capped at PHP 125.00 of discount
     * per week. The JAO tracks the weekly cap on the beneficiary's purchase booklet and does not define
     * the week, so the cashier reports the discount the booklet already shows this week and only the
     * remainder of the cap is available here -- this class never invents a week boundary.
     *
     * @param  Money  $eligibleNet  the sale's VAT-inclusive net after any line/manual discounts
     * @param  Money  $weeklyDiscountUsed  discount already taken this week per the booklet (zero if none)
     */
    public function computeBasicNecessitiesDiscount(Money $eligibleNet, Money $weeklyDiscountUsed): Money
    {
        $remainingCap = Money::fromApiString(self::BNPC_WEEKLY_CAP)->subtract($weeklyDiscountUsed);
        if (! $remainingCap->isPositive()) {
            return Money::zero();
        }

        $discount = $eligibleNet->percentageOf(self::BNPC_RATE);

        return $discount->greaterThan($remainingCap) ? $remainingCap : $discount;
    }
}
