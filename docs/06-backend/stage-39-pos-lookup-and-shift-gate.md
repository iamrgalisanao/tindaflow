# Stage 39 — Transaction lookup in POS mode, and the shift gate

## Status

**Done and browser-verified**, frontend only. These two changes landed on `main` as commits `d03b848` and `4deb72d`
without a stage document or manifest entry; this stage records them after a review. No API, contract, migration, PHP
or frozen file changed, and no `stage-*-baseline` tag moved. One small defect found in the review is fixed here
(section 3).

## 1. Transaction lookup at the till (`d03b848`)

`sitemap.md`'s Stage 7 validation settled that receipt lookup and reprint belong at the till. Until now the POS header
only offered a link to `/admin/sales`, which drops a cashier into the back-office shell mid-shift. The POS header gains
a **Lookup** tab beside Register / Payment / Shift, showing `PosLookupPanel`: a searchable journal on the left and a
transaction inspector on the right.

- **Scope is what `saleList` / `saleGet` expose.** Item count and tender method are not list columns (neither is on
  `SaleSummary`, and fetching each row's detail would be one request per row); both appear in the inspector. The status
  chips are the real enum (COMPLETED, VOIDED, PARTIALLY_REFUNDED, REFUNDED); the Stitch mockup's HOLD, e-receipt
  resend, CAS audit log, supervisor PIN and hardware bar have no endpoints and are not drawn.
- **Search** sends `transaction_number` when the term starts with `T-` (the Stage 6C format) and `invoice_number`
  otherwise, because the server exposes them as two separate exact-match filters.
- **Printing** opens the existing admin `InvoicePanel` rather than a third copy of the reprint logic. Every copy printed
  from here is marked REPRINT and audited; the unmarked original is only printed at the sale itself.
- **Cashier scoping.** A user without `REPORT_VIEW` is limited by the server to their own sales, and the panel says so.
  Void and refund are not offered; the panel points to Sales history, where a manager decides.

## 2. The shift gate (`4deb72d`)

`shiftCurrentGet` resolves the terminal's open shift, not the caller's. `CheckoutService` rejects a sale whose shift
belongs to another cashier, but only at finalisation, so a cashier could ring up a whole cart on a till showing "shift
open" and then fail at Complete sale with `SHIFT_REQUIRED`. `Pos.jsx` now compares the resolved shift's `cashier_id`
with the signed-in user and stops at a gate (`other-cashier-shift`) instead of the register, matching `sitemap.md`'s
"any route under /pos requires an open shift for the logged-in cashier/terminal".

The gate offers the close path rather than a dead end: only one shift can be open per terminal
(`shifts_one_open_per_terminal`), so opening your own is impossible until the other is closed. `ShiftCloseService` looks
the shift up by terminal, not cashier, so whoever is at the till can close it. It goes through the ordinary
declared-cash step, so the count is still blind, and the close is attributable: the closing user is recorded as
`generated_by` on the closing X-reading and as `actor_user_id` on the `SHIFT_CLOSED` audit event, while the shift keeps
its original `cashier_id`. `shiftCurrentGet` itself is left alone; making it cashier-scoped would change a frozen
operation and is an owner decision.

## 3. Review

**Verified in a real browser** (the two commits said the following were not):

- Lookup as the admin: the journal listed the day's two sales, selecting one opened the inspector (line, subtotal,
  discounts, VAT, total, tender and change all matched the sale, including the Stage 38 discount), and Receipt opened
  the invoice panel with the reprint notice. Printing a copy was not exercised, since it writes an audit record.
- Gate as the demo cashier with the admin's shift open on MAIN-01: the register did not render, the gate showed the
  other shift's open time, and "Count the drawer and close it" led to the blind declared-cash form. The shift was not
  closed.

**Defect found and fixed.** The lookup ran a request on every keystroke and did not discard superseded responses, so
eight keystrokes made eight `/sales` requests and a slow answer for a short prefix could replace the answer for the
full term. The data effect now waits 250 ms after typing stops and ignores any response whose query has changed; the
Search button re-runs the current query immediately. Eight keystrokes now make one request, and a full transaction
number still finds its sale.

**Observations, not changed:**

- While the gate's close form is open, the header shows the signed-in cashier as the operator next to a shift that
  belongs to someone else.
- The Today filter uses the browser's calendar date; the server's business timezone is `Asia/Manila`, so a browser in
  another timezone would ask for a different day.
- Search is an exact match on the full number; there is no partial or last-digits search, because the server exposes
  none.

## 4. Not built

Item count and tender columns in the list, partial-number search, e-receipt resend, supervisor PIN, and any cashier
scoping change to `shiftCurrentGet`.
