# Stage 38 — The 5% basic-necessities discount for Senior Citizens and PWDs

## Status

**Done and tested**: backend, two additive `SaleFinalizeRequest` fields, and a rule selector in the POS. No frozen file
text edited and no `stage-*-baseline` tag moved. `CheckoutService` is touched under the same basis Stages 28, 31 and 36
already use.

## 1. What the rule is

DTI-DA-DOE Joint Administrative Order 24-02 (2024) gives every Senior Citizen and PWD 5% off the regular retail
price of basic necessities and prime commodities, with no VAT exemption, capped at PHP 125.00 of discount per week
(equivalent to a PHP 2,500.00 purchase cap at 5%). Stage 36 shipped the other regime, 20% plus VAT exemption, at the
owner's request; this stage adds the 5% rule as a second, selectable rule so a grocery-only store can comply with the
rule that actually covers its goods. Stage 36's default is unchanged.

The JAO text (read from the DTI/DA/DOE publication summaries; the primary PDF was not fetched) leaves three things
undefined, and this stage does not invent an answer for any of them:

- **The week.** No calendar or rolling week is defined. The JAO tracks the cap on the beneficiary's purchase booklet,
  so the cashier reads the discount already taken this week off the booklet and enters it. Only the remainder of the
  PHP 125.00 is available. TindaFlow keeps no per-beneficiary weekly ledger, so it cannot get this wrong by choosing a
  week boundary.
- **Excess over the cap.** No guidance. The discount stops at the cap and the rest of the sale is charged at the regular
  price.
- **Seller records.** Not specified. The beneficiary name and ID number, the rule and the weekly amount reported are
  recorded on the invoice snapshot and the `DISCOUNT_APPLIED` audit event.

## 2. What was built

`StatutoryDiscountCalculator::computeBasicNecessitiesDiscount()` takes the sale's VAT-inclusive net and the weekly
amount already used, and returns 5% of the net (through `Money::percentageOf()`, the existing governed rounding),
limited to what is left of the cap. It adds no arithmetic of its own beyond `subtract`, `greaterThan` and
`percentageOf`. `CheckoutService` folds the result into the order-level discount and re-runs `calculateSale()`, exactly
as Stage 36 does, but without reclassifying VATABLE lines: the price shown, VAT included, is the base and VAT stays
in the sale (a PHP 100.00 line at 5% gives grand total 95.00 with VAT recomputed on it).

`SaleFinalizeRequest` gains `statutory_discount.rule` (`STANDARD_20` or `BNPC_5`; absent means `STANDARD_20`) and
`statutory_discount.weekly_discount_used` (a two-decimal amount, ignored for the 20% rule). Both are additive and are
recorded here, not in the frozen `openapi.yaml`, following Stage 29 and Stage 36. The receipt's beneficiary line reads
"Senior Citizen (5% basic necessities)" for this rule. The audit event gains `statutory_discount_rule` and
`statutory_weekly_discount_used`.

The authorization asymmetry from Stage 36 holds: any cashier can apply either rule, and a stacked manual discount
still needs `DISCOUNT_OVERRIDE`.

POS: the Senior Citizen / PWD box gains a "20% + no VAT" / "5% basic goods" selector and, for the 5% rule, an "Already
discounted this week (from the booklet)" field. A malformed amount disables Charge.

## 3. Verification

New tests: four calculator unit tests (5% of a VAT-inclusive price, remaining-cap limit, cap already spent, a large
sale stopping at 125.00); three `CheckoutServiceTest` cases (VAT kept with 95.00 / 84.82 / 10.18 and the snapshot and
audit fields, partial cap left, cap spent); one `SaleFinalizationHttpTest` case (unknown rule and malformed weekly
amount give `VALIDATION_FAILED`). Verified in a real browser: Rice at PHP 55.00 with the 5% rule and PHP 124.00
already used gave a grand total of PHP 54.00 (PHP 1.00 of cap left, below the PHP 2.75 the rate would give), and the
stored invoice snapshot recorded the beneficiary with the 5% label.

## 4. Not built

Per-product eligibility. The JAO covers a defined list of 43 basic necessities and prime commodities, but products
carry no flag for it, so as with Stage 36 the rule applies to the whole ticket and the cashier rings up only
qualifying items when using it. A per-product `is_basic_necessity` flag needs a `products` migration (a Stage 5-owned
table) and an owner decision, so it is left open. A stored weekly ledger per beneficiary is also not built, for the
reasons in section 1.
