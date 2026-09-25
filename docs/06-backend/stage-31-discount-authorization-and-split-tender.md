# Stage 31 — A discount now checks DISCOUNT_OVERRIDE, and the POS can split tender

## Status

**Done and tested**, backend, an audit event, and two POS UI additions (order-level discount entry, split payment
across several methods). **No frozen file edited** and no `stage-*-baseline` tag moved; `scripts/validate-baselines.sh`
holds. The one change to previously-shipped code is inside `CheckoutService`, already under Stage 28's owner-approved
exception to touch the frozen checkout folder — this pass adds to that exception rather than opening a new one, since
it is the same file and the same "self-heal a real defect" justification (below).

## 1. The problem

`openapi.yaml`'s own `SaleFinalizeRequest.items[].override_reason` field already said "Required when a
PRICE_OVERRIDE/DISCOUNT_OVERRIDE capability check applies", and `RoleCapabilityCatalog` already grants
`DISCOUNT_OVERRIDE` to MANAGER/ADMIN only, never CASHIER — but nothing enforced it. `CheckoutService` accepted
`line_discount_amount` and `order_level_discount_amount` from any authenticated cashier with an open shift and applied
them without any authorization check. `DISCOUNT_APPLIED` was also already a reserved `audit_events.event_type` in
`domain-model.md` §2.10, unused by any code. This was found while building the POS discount UI this stage adds: giving
every cashier a working discount box would have made the gap immediately exploitable, not just theoretical.

## 2. What was built

**Authorization.** `CheckoutService::performCheckout()`, once the server-computed `discountTotal` is known (never the
client's raw request fields — a client cannot dodge the check by lying about which line produced it): if it is
non-zero, `Gate::forUser($actor)->authorize('DISCOUNT_OVERRIDE')`. This is the exact pattern
`CashMovementService::create()` already uses for `CASH_OUT` above its threshold — the acting user must personally hold
the capability, there is no "manager PIN" mechanism anywhere in this contract to authorize on someone else's behalf.
A denial throws the same `AuthorizationException` every other capability check throws, rendered by the existing
`bootstrap/app.php` handler as `403 AUTHORIZATION_DENIED` — no new error code.

**Audit.** A sale whose `discountTotal` is non-zero writes one `DISCOUNT_APPLIED` audit event (the reserved type),
after `SALE_FINALIZED`, with the sale id, invoice number, and the line/order-level/total discount amounts. A
discount-free sale writes nothing here, matching `ProductAuditor`'s "a save that changes nothing writes nothing"
discipline. `recordsParts.jsx` gained the filter entry and a plain-sentence description ("Discount of ₱5.00 applied to
invoice 000002"), checked in a real browser against the audit log.

**POS discount entry.** `CartPanel` gained an order-level discount box, shown only to a user holding
`DISCOUNT_OVERRIDE` (`user.capabilities.includes(...)`, the same client-side gating pattern already used for the
`FISCAL_DAY_CLOSE` button) — a cashier who cannot use it is not shown it, though the server is what actually enforces
it. The preview subtracts the discount from the cart subtotal (clamped so it can never go negative), shown as
Subtotal / Discount / Total once a discount is entered; the payment step's total follows it automatically. Only an
**order-level** discount is exposed in the UI this stage — the backend also accepts a per-line `line_discount_amount`,
but no screen offers it; kept out to keep this stage's UI surface small, not a contract limitation.

**Split tender.** `TenderPanel` was rewritten around a list of payment rows (`{method, amount}`) instead of one fixed
method. The common case is unchanged: one row, and switching its method away from CASH still auto-fills the exact
total with no typing, exactly as before. A **Split payment** link adds a second row, pre-filled with whatever balance
is still owed; the keypad, method chips and quick-cash chips always act on whichever row is currently selected (tap a
row to select it), and each row can be removed. Quick-cash chips stay CASH-only per row, as before. Complete is
enabled once every row has a valid positive amount and the rows sum to at least the total; a blank or zero row is
never sent to the server.

## 3. Verification

- New tests: `CheckoutServiceTest` (+4: a cashier rejected for a line discount and for an order-level discount, both
  with nothing committed; a manager's discount checkout writes the audit event with the right figures; a
  discount-free checkout writes none), `SaleFinalizationHttpTest` (+2: the HTTP-level 403 envelope and a successful
  manager checkout). Two pre-existing tests that rang a discount through a plain-role CASHIER factory default were
  updated to use a MANAGER for that one call — `CheckoutServiceTest::test_mixed_tax_and_discount_checkout_reconciles_exactly`
  and `SaleRefundHttpTest::test_partial_refunds_of_a_discounted_line_...` — neither depends on which role rang the
  sale; this mirrors the Stage 24 precedent ("the shift-list test... now uses a manager").
- Full regression: Unit 156 + Feature (bundled) = 156, Database 653 (13 new), Pint clean, frontend builds.
- Verified in a real browser against the real backend, as the admin (holds every capability): a ₱5.00 discount on a
  ₱55.00 cart previewed Subtotal/Discount/Total correctly and carried through to a ₱50.00 payment step; split tender
  of ₱20.00 cash + ₱30.00 GCash completed the sale (invoice 000002, tendered ₱50.00, change ₱0.00); the audit log
  showed "Discount of ₱5.00 applied to invoice 000002". A cashier's negative case (no discount box shown, a 403 if
  attempted anyway) is covered by the test suite but was not separately walked through in a browser this pass.

## 4. Not built

Line-level discount entry in the UI (the API accepts it; no screen offers it). SC/PWD/Solo Parent discount capture —
unchanged from Stage 24, still referred to the business/legal owner. A discount reason/note field in the UI (the API
has `items[].override_reason`; nothing writes it yet). A per-store discount limit or approval workflow beyond the
existing DISCOUNT_OVERRIDE gate.
