# Stage 9 — Shift Close / Cash Movements / X-Readings / FiscalDay Close

## Status

**Done and verified end-to-end.** Completes the Shift/FiscalDay lifecycle
`shiftOpen`/`shiftCurrentGet` started: a cashier can now actually close
their shift and reconcile cash, and a manager/admin can close the
business day and generate its Z-Reading.

## 1. Why this was built

Every operation in this module — `shiftClose`, `shiftCashMovementCreate`,
`shiftXReadingList`/`Create`, `fiscalDayClose`, `fiscalDayZReadingGet` —
was already fully specified in the frozen Stage 4 `openapi.yaml`
contract (unlike the store-setup pass's `InvoiceSeries`/
`InventoryLocation`, which had no draft anywhere). Only `ShiftOpenService`
and `ShiftController::open`/`current` existed before this pass; nothing
else in the lifecycle had a service or controller layer yet, so a
cashier could open a shift and sell but never close one out.

## 2. Sixth baseline reconstruction

`shiftClose`/`shiftCashMovementCreate`/`shiftXReadingCreate`/
`shiftXReadingList`/`fiscalDayClose`/`fiscalDayZReadingGet` all already
declared a generic `404 NotFound` response in the frozen contract, with
no specific code registered — the same shape as the `TERMINAL_NOT_FOUND`
and `NO_CURRENT_SHIFT` discoveries, and explicitly the category
`error-catalog.md`'s own 2026-09-18 governance note calls out as still
requiring the full reconstruction discipline (as opposed to genuinely
new operations, which the store-setup pass forward-committed). Two new
codes — `SHIFT_NOT_FOUND` (404) and `FISCAL_DAY_NOT_FOUND` (404,
deliberately non-enumerating between "doesn't exist" and "hasn't closed
yet" for `fiscalDayZReadingGet`) — were added at the `stage-4-baseline`
boundary and all 55 downstream commits replayed via `git rebase
--onto`. `SHIFT_ALREADY_CLOSED` and `FISCAL_DAY_HAS_OPEN_SHIFT` needed
no catalog change — both were already-registered Stage 4 codes with no
exception class or call site built yet. Full record in
`docs/PROJECT-MANIFEST.md`'s "Shift close... sixth baseline
reconstruction" section.

## 3. Scope decisions

- **Implemented**: `shiftClose`, `shiftCashMovementCreate`,
  `shiftXReadingList`, `shiftXReadingCreate`, `fiscalDayClose`,
  `fiscalDayZReadingGet`.
- **Deliberately deferred**: `shiftGet`/`shiftList`/`fiscalDayGet`/
  `fiscalDayList` — pure historical browsing with no bearing on the
  operational open→close flow, a natural fit for a future Reports
  module. `fiscalDayCurrentGet` — its own frozen `404` is descriptively
  labeled `FISCAL_DAY_NOT_OPEN`, the same already-frozen-and-mislabeled
  shape `shiftCurrentGet` had before `NO_CURRENT_SHIFT`, so implementing
  it now would need its own reconstruction; the frontend instead keeps
  the `fiscal_day_id` it needs to close in React state from the shift
  it already opened (`shiftOpen`/`shiftCurrentGet` both return it
  inline), so this isn't required to reach a working close flow.

## 4. Financial computation

`ShiftReadingAggregator` (X-Reading) and `FiscalDayReadingAggregator`
(Z-Reading) always recompute from the authoritative ledger (Sale/
Payment/Refund/RefundSettlement/CashMovement/SaleVoid) — invariant #40,
never trusting a prior reading's own stored totals. Key formulas:

- `expected_cash = opening_cash + cash_sales - cash_refunds + cash_in_total - cash_out_total`,
  where `cash_sales` is what the sales collected **net of the change handed back** (section 8), and `cash_refunds` is pulled specifically from `refund_settlements`
  rows with `payment_method = CASH` (the shift-level `refunds_total`
  field shown to the user is a simple total across all methods; only
  the internal cash-reconciliation figure needs the cash-specific
  split).
- A `VOIDED` sale is excluded entirely from every "sales" total on both
  readings — its payment never happened, from a reporting point of
  view — and counted separately in Z-Reading's `void_total` instead,
  attributed to the fiscal day the void itself was recorded on
  (`SaleVoid.fiscal_day_id`), not the original sale's day.
- `accumulated_grand_total_sales_before` is the gross of every earlier
  closed day of the terminal, derived from the sales ledger (see the
  addendum at the end of this note; it was originally chained from the
  previous `ZReading.totals_snapshot`, which invariant #40 forbids), or
  `"0.00"` for a terminal's first-ever closure; `z_counter` is a derived count
  (`ZReading::where('terminal_id', ...)->count() + 1`), never a
  separately mutated column, matching the migration's own documented
  intent.
- `variance` is only ever non-null on the closing X-Reading
  (`shiftClose` receives `declared_cash`; the on-demand
  `shiftXReadingCreate` takes no request body at all, so it's always
  null there).

## 5. Cash-out authorization threshold

`shiftCashMovementCreate`'s request body has no separate authorizer
field (`{type, amount, reason}` only) — `authorized_by` is always the
acting user's own id, server-derived. At or above (corrected from "above" 2026-09-27, see the addendum at the end)
`tindaflow.cash_movements.cash_out_authorization_threshold` (config,
env `CASH_OUT_AUTHORIZATION_THRESHOLD`, default `1000.00`), a `CASH_OUT`
requires the acting user to personally hold the `CASH_OUT` capability
(invariant #39) — there is no "manager PIN" mechanism in this contract
to authorize on someone else's behalf. The default is a technical
placeholder, not a business decision (the contract only mandates
"configurable"); a store owner should set this per their own
cash-handling policy.

## 6. Frontend

`Pos.jsx` gained three steps (`close-shift`, `shift-closed`,
`fiscal-day-closed`) and a "Cash drawer" widget on the cart screen for
recording `CASH_IN`/`CASH_OUT` movements mid-shift. The close-shift form
deliberately asks for counted cash before revealing anything the server
computes — matching `state-machines.md` §5's own ordering note ("the
cashier enters `declared_cash` before the system reveals
`expected_cash`"). "Close business day" only shows for users holding
`FISCAL_DAY_CLOSE`; a rejection (e.g. `FISCAL_DAY_HAS_OPEN_SHIFT` because
another terminal still has an open shift) surfaces as a plain error
banner without losing the shift-closed summary already on screen.

## 7. Verified end-to-end in a real browser

Recorded a ₱200 cash-in on an open shift with real prior sales; closed
the shift with a counted amount that produced a real negative variance
(expected ₱1,465.00, counted ₱1,230.00, variance -₱235.00 — correctly
reflecting opening cash + this session's actual cash sales + the cash-in
just recorded); closed the business day as the admin (`FISCAL_DAY_CLOSE`
holder), producing Z-Reading #1 with gross sales ₱220.00 and VAT ₱23.56,
matching the four ₱55 sales completed during the Stage 7 pass 2 and
Stage 8 verification sessions exactly; confirmed `/pos` correctly
reverted to the open-shift form afterward; opened a fresh shift on the
same terminal and confirmed a new fiscal day opened automatically since
the prior one was closed.

Full regression: Unit 102 + Feature 1 + Database 281 (25 new tests) =
384 passing, 0 failures. Pint clean. `scripts/validate-baselines.sh`
14/14.

## 8. Change is not collected (correction, 2026-09-26)

**Defect.** A `payment` row stores what the customer *tendered* (invariant #8: `SUM(payment.amount) >= grand_total`; the
row is immutable, and `change` is only ever derived, in the sale detail and the invoice snapshot). Sections 4 and 7 summed
those rows as if they were what the sale brought in, so a P200.00 note on a P107.00 cash sale added P200.00 to
`cash_sales` and to `expected_cash`. Every honest count then closed short by all the change given during the shift, and the
Z-reading `payment_breakdown` and the sales-by-payment-method report overstated the same way. The section 7
verification did not expose it because those sales were rung with the exact amount.

**Decision.** Nothing that is stored changes (no payment row, sale or invoice snapshot is rewritten). What a sale
*collected* is the tender less the change, and change is handed back in cash:

- the change comes off the `CASH` tender first, never below zero;
- change larger than all the cash tendered means a non-cash method was over-tendered; that remainder comes off the
  non-cash payments, last listed first, so the payments of a sale always add up to exactly its `grand_total`;
- it is subtraction on already-rounded `Money` only; no new rounding point is introduced.

`App\Domain\Financial\NetTenderAllocator` is the rule. `App\Services\Payments\NetCollectionService` applies it to
whole sets of sales, and is the only reader of payment rows for money totals: the shift reading (`cash_sales`,
`non_cash_sales`, `payment_breakdown`, therefore `expected_cash`, the closing variance and the `cash-variance` report), the
Z-reading `payment_breakdown`, and the `sales-by-payment-method` report ("collections per tender type"; a sale paid
with two methods still counts once under each). Refunds are unchanged: `cash_refunds` still comes from the CASH refund
settlements of the shift that executed them.

**Not rewritten.** Shifts closed and readings generated before this correction keep the `expected_cash`, `variance` and
`payment_breakdown` they were closed with (readings are immutable snapshots, invariant #40); a shift that was closed short
only because of change given is therefore still recorded short. The payment-method report is computed live, so it now
shows the corrected figures for any date range.

**Tests.** `NetTenderAllocatorTest` (unit) and `CashChangeCollectionHttpTest` (real checkout, shift close, refund,
Z-reading and report). Sales-by-payment-method is also described in `stage-10-reports.md`.

## Addendum 2026-09-27 — reading integrity: a derived accumulated total, and `SHIFT_OPENED` journaled

The invariant audit ([invariant-test-coverage.md](../02-domain/invariant-test-coverage.md), findings 3 and 5) found two
places where the code contradicted the frozen invariants.

**#40, the accumulated grand total.** `FiscalDayReadingAggregator` chained `accumulated_grand_total_sales_before` from the
previous Z-reading's stored `accumulated_grand_total_sales_after`, so one wrong stored figure would have propagated to every
later reading, and readings are append-only. It is now derived from the ledger: the sum of `sales.grand_total` (status not
VOIDED) over the fiscal days of this terminal whose Z-reading has a lower `z_counter`. No "as of close" logic is needed,
because a closed day's gross cannot change: a sale can only be voided while its own fiscal day is open (`VoidService`), and a
refund never marks a sale VOIDED. Voids and refunds executed later land in the later day's `void_total` and `refund_total`.
A day that already has a reading keeps its own `z_counter`, so aggregating a closed day again reproduces its stored snapshot
(now tested). Printed Z-readings do not change unless a stored figure had been corrupted or data was edited outside the app;
the derivation fixes future readings and does not rewrite past ones. Before the pilot it is worth a read-only comparison of
each terminal's stored chain against the derived sums.

**Decided 2026-09-27 (owner-delegated decision, 2026-09-27; stage-23 addendum 8): a shift's reading is "as of close".** The reading stored at close is what was printed and
stays true: a sale voided after the shift closed (its business day still open) belongs to the voiding shift and day, matching the
tested refund attribution and the tested rule that a closed shift's stored figures cannot change. Writing the test found that
`ShiftReadingAggregator` dropped every sale that is *currently* voided, so a recomputation after a later void no longer matched
what was printed; it now cuts at the shift's closing instant (a void resolved at or after the close does not remove the sale; the
timestamps are whole seconds, so a void in the very second of the close counts as after it). Invariant #40 is clarified in
`invariants.md` accordingly. **Cash-out threshold:** the code compares with `>=` (the stricter control) and the frozen text said
"above"; the text is corrected to "at or above" in `invariants.md` #39, `openapi.yaml` and section 5 above, and the boundary test
stays.

**#49, `SHIFT_OPENED`.** `ShiftOpenService` now writes one audit event and one electronic-journal entry (`event_type`
`SHIFT_OPENED`, `source_type` `shift`, `source_id` the shift, payload: shift, terminal, cashier, fiscal day, opening cash,
time) in the shift's own transaction, mirroring `ShiftCloseService`. The event type was already in the journal CHECK and the
API enum, so there is no contract change; an idempotent replay never re-enters the service and the
`(source_type, source_id, event_type)` unique index backs "exactly one". **No backfill:** shifts opened before this change keep
no journal row, because a backfill would invent entries after the fact. The audit and journal screens describe the new event.

Tests: `FiscalDayCloseHttpTest` (2 new: derived from the ledger with a tampered earlier snapshot and a voided sale; a closed day
reproduces its stored reading), `ShiftOpenHttpTest` (4 new: one audit and one journal row with the right source; a retried open
journals once; every shift on an open day journals against its own id; a failure while journaling leaves no shift, audit or
journal row and the key reusable), `ShiftOpenConcurrencyTest` (the losing open leaves no journal row). Eight of these fail on the
old code.

