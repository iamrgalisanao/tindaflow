# Stage 36 — Senior Citizen / PWD statutory discount at checkout

## Status

**Done and tested**, backend, an additive `SaleFinalizeRequest` field, an invoice-snapshot beneficiary, an audit-event
extension, and a POS UI addition. **No frozen file's text was edited** and no `stage-*-baseline` tag moved;
`scripts/validate-baselines.sh` holds. `CheckoutService` is touched under the same "self-heal/extend a live file that
already carries an owner-approved exception" basis Stage 28/31 established — no new exception opened.

## 1. The legal basis, and the scope decision actually made

Two distinct Philippine statutory-discount regimes apply to a retail sale, and they are easy to conflate:

- **RA 9994 (Expanded Senior Citizens Act) and RA 10754 (Magna Carta for Persons with Disability, amending RA 7277)**:
  a flat **20% discount and full VAT exemption**, but only on specific enumerated categories (restaurants, medicine,
  hotels/lodging, medical/dental services, domestic transport, funeral services, admission fees). A sari-sari/grocery
  sale of ordinary merchandise is **not** one of the enumerated categories under a strict reading.
- **DTI-DA-DOE Joint Administrative Order 24-02 (2024)**: a **5% discount, no VAT exemption**, capped at ₱125/week
  (₱2,500 purchase cap) per beneficiary, scoped specifically to "Basic Necessities and Prime Commodities" — the
  category that actually covers what a sari-sari store sells (rice, canned goods, bottled water, etc.).

Strictly applied, a grocery-only POS should implement the 5% BNPC rule, not the 20%+VAT-exempt one. The owner was
given this exact distinction and explicitly chose the simpler, broader pattern anyway: **auto-compute the common 20%
+ VAT-exempt pattern**, matching what general-purpose Philippine POS software (including the named competitor
reference, KaHero) actually ships, rather than the more legally precise but rarer 5%-with-a-weekly-cap rule. This is
recorded here as the deliberate scope decision it is, not an oversight — a future pass could add the 5% BNPC rule as
a second, selectable discount type if the owner later wants strict compliance for grocery lines specifically.

Out of scope, deliberately: **Solo Parent (RA 11861)** discounts, which follow different eligibility and category
rules entirely and are not part of this pass.

## 2. What was built

**`StatutoryDiscountCalculator`** (`app/Domain/Financial/StatutoryDiscountCalculator.php`, new, frozen-adjacent but
not itself owned by any earlier stage) computes the discount from figures `FinancialCalculator::calculateSale()`
already produced — `taxableSales` (VAT-exclusive VATABLE base), `vatAmount`, and the combined VAT_EXEMPT/ZERO_RATED/
NON_VAT net — using only `Money`'s two governed materialization points (`percentageOf()`, `add()`). It never
multiplies or divides a raw figure itself; every number it touches was already rounded by an existing, tested
materialization point. This is the same "reuse the governed primitives, add zero new arithmetic" discipline
`DiscountAllocator`/`TaxCalculator` already established.

**Two-pass computation in `CheckoutService`.** `calculateSale()` runs once, unmodified, to obtain the governed
intermediate figures. The statutory discount is computed from those figures only. If the store is VAT-registered,
every `VATABLE` line is reclassified to `VAT_EXEMPT` (the discount removes the VAT, so the second pass must not
charge it again). The statutory amount is folded into `order_level_discount_amount`, and `calculateSale()` runs a
second time with the reclassified lines and the combined discount — reusing 100% of the existing allocation/rounding/
reconciliation machinery. **Zero changes to `FinancialCalculator`, `TaxCalculator`, `DiscountAllocator`, or `Money`.**

**Authorization — a deliberate asymmetry with Stage 31's `DISCOUNT_OVERRIDE` gate.** A manual discount
(`line_discount_amount`/`order_level_discount_amount` from the request) still requires `DISCOUNT_OVERRIDE`
(MANAGER/ADMIN), unchanged from Stage 31. The statutory discount does **not** — any cashier with an open shift can
apply it. The reasoning: `DISCOUNT_OVERRIDE` gates a *discretionary* markdown a cashier could otherwise use to give
away margin; the SC/PWD discount is the customer's own legal entitlement, not a discretionary choice by the person
ringing the sale, so gating it the same way would just make a lawful discount harder to give than it should be. A
statutory discount does **not** exempt a sale from the manual-discount check — combining both still requires
`DISCOUNT_OVERRIDE` for the manual portion, proven by test.

**Request/response shape.** `SaleFinalizeRequest` gains an optional `statutory_discount: {type, id_number, name}`
object (`type` is `SENIOR_CITIZEN` or `PWD`) — additive to the frozen `saleFinalize` operation, following Stage 29's
precedent of recording an additive field in the owning stage document rather than editing `openapi.yaml`'s text.
`Invoice.invoice_snapshot_json.discount_beneficiary` (already rendered verbatim by
`InvoiceSnapshotV2Renderer::beneficiaryBlock()` since Stage 24, previously always `null` in practice) is now
populated: `type` is translated to a human-readable label ("Senior Citizen"/"PWD") at snapshot time, since the
renderer prints it as-is on the receipt and the raw enum value would look wrong there; `tin` stays `null` (not
collected by this flow). The `DISCOUNT_APPLIED` audit event gains `statutory_discount_type` and
`statutory_discount_beneficiary_name` alongside its existing discount-amount fields.

**POS UI.** `CartPanel` gained a "Senior Citizen / PWD discount" checkbox, visible to every cashier (unlike the
`DISCOUNT_OVERRIDE`-gated manual-discount box next to it), which reveals a SENIOR_CITIZEN/PWD toggle and ID-number/
name fields. The Charge button is disabled with an inline message until both fields are filled. Deliberately **no
client-side discount preview math**: duplicating the VAT decomposition in the browser would risk a second calculation
engine drifting from the server's, so the "Total amount due" shown throughout cart entry stays at the pre-discount
(higher, always-safe) figure; the receipt step shows the server-authoritative `grand_total`/`discount_total` once the
sale is finalized.

## 3. Verification

- New tests: `StatutoryDiscountCalculatorTest` (5, unit) covering a VATABLE base, a NON_VAT-only base, a mixed case, a
  zero-eligible case, and a fractional-rounding case. `CheckoutServiceTest` (+5): a cashier applying a Senior Citizen
  discount without `DISCOUNT_OVERRIDE`; a PWD discount on a NON_VAT store (no VAT to remove); a statutory discount
  combined with a manual discount still throwing `AuthorizationException` for the manual portion; the invoice
  snapshot's `discount_beneficiary` shape; the `DISCOUNT_APPLIED` audit event's new fields. `SaleFinalizationHttpTest`
  (+3): a successful statutory-discount checkout over HTTP; a 422 for a missing `id_number`/`name` (checked against
  this app's own `VALIDATION_FAILED` error envelope, not Laravel's default validation-error shape); a 422 for an
  invalid `type`.
- Full regression: Unit+Feature 161, Database 668 (8 new), Pint clean, frontend builds.
- Verified in a real browser against the real backend, as the admin, on a **NON_VAT** store (this store's tax
  registration is `NON_VAT`, confirmed directly from the database before asserting expected figures): added Rice
  (1kg, ₱55.00), checked the Senior Citizen / PWD box, entered ID `OSCA-00123` and name `Juana Dela Cruz`, tendered
  the exact pre-discount ₱55.00 shown by the register, and completed the sale. Server returned grand total **₱44.00**
  (55.00 − 20% = 11.00 discount) with ₱11.00 change — exactly the NON_VAT-path arithmetic (no VAT to remove, 20% of
  the net). The invoice snapshot's `discount_beneficiary` recorded `{type: "Senior Citizen", name: "Juana Dela Cruz",
  id_number: "OSCA-00123", tin: null}` and `sale.discount_total` was `11.00`, matching the database record exactly.

## 4. Not built

The 5% Basic-Necessities-and-Prime-Commodities rule (DTI-DA-DOE JAO 24-02) — the owner explicitly chose the simpler
20%+VAT-exempt pattern instead (see §1). Solo Parent (RA 11861) discounts — different eligibility rules, out of
scope. A per-beneficiary purchase cap or weekly-limit tracking (relevant only to the 5% rule this pass did not
implement). ID verification/lookup against any OSCA/NCDA registry — the ID number and name are captured as typed,
not validated against an external source, matching how every other POS in this market handles it at the till.
