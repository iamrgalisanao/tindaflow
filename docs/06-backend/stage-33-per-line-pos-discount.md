# Stage 33 — Per-line discount entry in the POS

## Status

**Done and tested**, frontend only. No API, contract, migration, PHP or frozen file changed — `CheckoutService`
already accepted `items[].line_discount_amount` and enforced `DISCOUNT_OVERRIDE` against it since Stage 31; this
stage only builds the UI Stage 31 explicitly left out ("Line-level discount entry stays API-only; no screen offers it
yet").

## 1. What changed

`CartPanel` gained a small per-line discount box under each cart line's unit price, shown only to a user holding
`DISCOUNT_OVERRIDE` (the same client-side gating already used for the order-level box and, before that,
`FISCAL_DAY_CLOSE`). Each line's own total updates live as the discount is typed, clamped so a line can never preview
below zero. The cart's summary box (Subtotal / Discount / Total) now reflects **both** line discounts and the
order-level discount combined — its visibility condition changed from "is the order-discount box non-zero" to "does
the subtotal exceed the total," which is the more correct condition regardless of which discount produced the
difference.

`posMoney.js` gained one shared helper, `clampedDiscountCents(discountText, maxCents)`, used for both the order-level
and every per-line discount (previously the order-level clamp was written inline in `Pos.jsx`).

`Pos.jsx`'s total-computation block was rewritten to compute, per line: the gross amount, the clamped line discount,
and the amount after it — then sums those into `subtotalCents` (gross) and `afterLineDiscountsCents`, against which
the order-level discount is clamped, giving `totalCents`. `checkoutSale`'s request body now includes
`line_discount_amount` per item when a line carries a non-zero discount.

## 2. A real display bug found and fixed on the way

Verifying this in a real browser (not just reading the diff) surfaced that `TenderPanel`'s Order Summary list — the
payment step's own line-by-line breakdown — was still computing each line's shown amount from `lineCents(price,
quantity)` alone, with no knowledge of the new per-line discount. The **total payable and the Complete-sale amount
were both already correct** (they come from `Pos.jsx`'s own `totalCents`, which was right), but the individual line
row still showed the pre-discount price — a customer or cashier glancing at the itemized list would see a number that
does not match what they are being charged, which reads as an error even though nothing was actually miscalculated.
Fixed by giving `TenderPanel` the same `clampedDiscountCents` computation `CartPanel` already had, plus a small
"`− ₱X.XX discount`" annotation under the line so the reduction is visible, not just its effect.

This is exactly the kind of defect the verification workflow (browser-driven, not diff-review-only) exists to catch —
a plausible-looking, individually-correct-seeming change that only breaks when two components that each derive the
same figure independently drift apart. Recorded here rather than silently folded in, per the standing self-heal
directive.

## 3. Verification

No backend changed, so no new PHPUnit coverage — `line_discount_amount`'s authorization and audit-trail behavior was
already covered by Stage 31's tests (`CheckoutServiceTest::test_a_cashier_cannot_apply_a_line_discount`,
`test_a_manager_discount_checkout_writes_a_discount_applied_audit_event`, etc.), and this stage sends the same field
those tests already exercise. Full regression: Unit+Feature 156 (unchanged), Pint clean (no PHP touched), frontend
builds.

Verified in a real browser against the real backend as the admin: added Instant Noodles (₱12.50) to the cart, typed a
₱2.50 line discount, watched the line total update to ₱10.00 live, moved to Payment and confirmed the Order Summary
now correctly showed "1 × ₱12.50 − ₱2.50 discount → ₱10.00" and Total Payable ₱10.00 (the bug above, fixed before
this check), and completed the sale — the server returned grand total ₱10.00 exactly, invoice #000003.

## 4. Not built

A discount reason/note field (the API has `items[].override_reason`; nothing in the UI writes it, matching Stage 31).
An `order_discount_eligible` toggle per line (defaults to `true` server-side; no UI control offered to change it).
