# Domain Invariants — TindaFlow POS

## Status
APPROVED — Stage 2 baseline (`stage-2-baseline`, tag to be moved to the
pass-3 commit), amended 2026-09-16 for a third time. Companion to
[domain-model.md](domain-model.md). Every invariant here should map to at
least one automated test named in [docs/08-testing/](../08-testing/) once
that stage is written (see the governing brief §15, "Critical Invariant
Tests," which this list extends).

Invariants are grouped by aggregate. Each is a rule the system must never
violate, regardless of UI behavior, client input, or concurrent requests —
enforced server-side (and, where practical, at the database-constraint
level), never trusted to the frontend alone. Items marked **(new)** or
**(revised)** were added or changed in pass 1; the **Discount allocation**
section (items 59–66, `DISC-xxx`) was added in pass 2 and item #57 was
revised in pass 2; the **Void & Refund processing context and settlement**
section (items 67–72) was added in pass 3 (this pass) — everything
else is carried over unchanged from revision 1.

---

## Sale

1. **No persisted intermediate status.** There is no `DRAFT` (or any other
   pre-completion) value in `sale.status`. **(revised)** The `sale` row
   itself does not exist until the finalize-sale transaction commits — an
   in-progress cart is client-side state only (see
   [domain-model.md §2.11](domain-model.md)), never a `sale` row with a
   pending status.
2. **Immutability after completion.** Once a `sale` row exists (i.e., it is
   `COMPLETED`), no application code path may `UPDATE` or `DELETE` it or
   any of its `sale_item`/`payment` children. Corrections happen
   exclusively via `void` or `refund` records that reference the original.
3. **No direct DELETE route.** There is no `DELETE /sales/{id}` endpoint,
   and no generic CRUD route exists for `sale`, `sale_item`, `payment`,
   `invoice`, `audit_event`, or `electronic_journal_entry`.
4. **Server-authoritative totals.** `subtotal`, `discount_total`,
   `taxable_sales`, `vat_exempt_sales`, `zero_rated_sales`, `vat_amount`,
   and `grand_total` are always recomputed server-side from `sale_item`
   rows and the store's tax configuration effective at finalization time.
   A client-submitted total is never persisted as-is.
5. **Idempotent finalization.** A finalize-sale request carries an
   `Idempotency-Key`. A duplicate request with the same key (same
   terminal, same key) returns the original result and creates no second
   `sale` row — enforced by a unique constraint on `(terminal_id,
   idempotency_key)`. **(Scope corrected during Stage 3 architecture,
   2026-09-16 — see ADR-010.)** The original Stage 2 baseline scoped this
   to `(store_id, idempotency_key)`; Stage 3's terminal-identity
   architecture (server-verified `terminal_id`, never client-asserted —
   ADR-011) did not exist yet when that scope was chosen. Binding
   idempotency to the *verified* terminal, rather than the whole store, is
   strictly more correct: it prevents an unrelated key collision or a
   copied-credential edge case (ADR-011's own acknowledged residual risk)
   on one terminal from ever being treated as a duplicate of a legitimate,
   distinct sale on another terminal. This is a scope refinement of how
   the same idempotency guarantee is enforced, not a change to the
   guarantee itself or to any other invariant.
6. **Atomicity of finalization.** Creating `sale`, `sale_item`, `payment`,
   allocating the invoice number, writing `invoice`, writing the `SALE`
   stock movements, and writing `audit_event`/`electronic_journal_entry`
   rows all happen inside one database transaction. Any failure rolls back
   all of it, and consumes no invoice number (see Invoice numbering §11).
7. **Historical snapshot integrity.** `sale_item` fields suffixed
   `_snapshot`, and every field in `invoice.invoice_snapshot_json`/its flat
   snapshot columns, are written once, at finalization/issuance, and never
   recomputed from live `product`/`tax_registration`/`store_settings`/
   `fiscal_installation` state afterward — not even by a report or an
   admin "recalculate" action.
8. **Server-authoritative payment sufficiency.** A sale cannot be created
   unless the sum of its `payment` rows is ≥ `grand_total` (V1 has no
   credit-sale concept); `change` is computed server-side, never accepted
   from the client.
9. **Explicit fiscal attribution — never inferred.** `sale.fiscal_day_id`
   and `sale.shift_id` are persisted at finalization from the currently
   `OPEN` fiscal day and shift for that terminal/cashier. **(new)** No
   report, reprint, or reading is ever permitted to determine "which fiscal
   day did this sale belong to" by comparing `sold_at` against a calendar
   date or a fiscal day's `business_date` range — the FK is the only
   source of truth, specifically because a store may operate across
   midnight.

## Invoice & invoice numbering

10. **One invoice per completed sale.** Exactly one `invoice` row exists
    per `sale`, created in the same transaction as the sale.
11. **No reuse, no reassignment, no renumbering.** Once `invoice_number` is
    allocated, it is permanent — never reused, reassigned, or edited, even
    if the sale is later voided. A voided sale's invoice number remains
    permanently associated with that voided sale.
12. **Allocation occurs only during authoritative sale finalization.**
    **(revised for clarity)** No other code path — not a refund, not a
    void, not an administrative action — allocates from `invoice_series`.
13. **Concurrency-safe, transaction-scoped allocation.** Two terminals
    finalizing sales against the same store's `invoice_series` concurrently
    can never receive the same number. Allocation is locked/serialized
    (e.g., `SELECT ... FOR UPDATE`) within the same transaction as sale
    finalization — enforced at the database level, not solely by an
    application-level lock/mutex.
14. **No gaps from failed attempts.** Because allocation and sale creation
    are one transaction, a failed attempt never consumes a number. The
    only source of gaps in a series is a legitimately voided sale, which
    keeps its number.
15. **Reprint never allocates.** Reprinting creates no new `invoice` row
    and no new number; it appends `audit_event`
    (`INVOICE_REPRINTED`)/`electronic_journal_entry` rows only, and the
    rendered output is visibly marked as a reprint/copy.
16. **Reprint renders exclusively from the snapshot.** **(new)** Rendering
    a reprint reads `invoice.invoice_snapshot_json` (and its flat snapshot
    columns) only. It never re-queries current `store_settings`,
    `tax_registration`, `fiscal_installation`, or `product` data to
    "rebuild" the invoice — a config change made after issuance must never
    change what a reprint shows.
17. **Refund and Void never allocate a replacement invoice number.**
    **(new)** Neither workflow produces a new fiscally-numbered document in
    V1. If a future phase introduces a required adjustment-document series
    (e.g., a formal credit note numbering scheme), that is an additive
    change to this model, not an implicit consequence of today's Void/Refund.
18. **No administrative rewind.** **(new)** No ordinary administrative
    function (including by `ADMIN`) can decrease `invoice_series.current_number`
    or otherwise move a series backward.
19. **Series exhaustion fails safely.** If an `invoice_series` has a
    non-null `ending_number` and the next allocation would exceed it, sale
    finalization fails with a clear error rather than wrapping around or
    borrowing from another series.

## Void

20. **Void eligibility is a five-part gate, checked at request and at
    approval.** **(revised — see [domain-model.md §2.8](domain-model.md)
    for full prose)** A void may proceed only when: (a) `sale.status =
    COMPLETED`; (b) the sale's `fiscal_day.status = OPEN`; (c) no
    `refund` with `status = COMPLETED` exists against the sale; (d) the
    actor holds the required capability (`SALE_VOID` to request,
    `SALE_VOID_APPROVE` to approve when the requester lacks void
    authority); (e) a non-empty `reason` is supplied. Failing any condition
    rejects the request server-side, regardless of client-side UI state.
21. **At most one successful void per sale.** Multiple `void` rows may
    exist per `sale_id` (rejected attempts, resubmissions), but at most one
    may ever reach `status = VOIDED` — enforced by a partial unique
    constraint (`UNIQUE(sale_id) WHERE status = 'VOIDED'`).
22. **Full reversal, not partial.** A successful void reverses the entire
    sale's inventory impact (one `SALE_RETURN`-type `stock_movement` per
    `sale_item`, full original quantity). Partial correction is a
    `refund`, never a partial void.
23. **Original rows untouched; ledger never deleted.** **(revised/merged)**
    Voiding a sale never edits or deletes `sale`, `sale_item`, `payment`,
    or the original `SALE`-type `stock_movement` rows it references — it
    only transitions `sale.status` to `VOIDED` and appends new
    `stock_movement`/`audit_event`/`electronic_journal_entry` rows.
    Inventory restoration happens exclusively through new, compensating
    `stock_movement` rows, never by editing the original movement.
24. **Mandatory audit trail.** Every void request, approval, rejection, and
    completion writes an `audit_event` (`SALE_VOID_REQUESTED`,
    `SALE_VOIDED`, or `SALE_VOID_REJECTED`) with `reason` and the
    identities of requester/approver.
25. **Voided sales are a dead end for refunds.** **(new — cross-invariant,
    see also #29)** Once `sale.status = VOIDED`, no `refund` may ever be
    requested against it.

## Refund

26. **Refund references, never mutates, the original sale.** `refund_item`
    rows reference `sale_item` rows by ID; refunding never updates the
    referenced `sale_item` or the original `SALE`-type `stock_movement`.
27. **Cumulative quantity cap.** The sum of `quantity_returned` across all
    `refund_item` rows for a given `sale_item` (across all refunds, past
    and present) can never exceed that `sale_item.quantity` — checked
    server-side by summing prior refunds, never trusted from client input.
28. **Cumulative monetary cap (sharpened by DISC-004 below).** The sum of
    `unit_refund_amount` across all `refund_item` rows for a given
    `sale_item` can never exceed that line's `net_line_amount` — precisely
    defined as the finalized, fully-discount-allocated amount (see
    [domain-model.md §2.7](domain-model.md)'s Order-level discount
    allocation), not an approximation. A refund can never return more
    money than was actually collected for that line.
29. **Refund is unavailable against a voided sale.** **(new — cross-
    invariant with #25)** A `refund` cannot be created with `sale_id`
    pointing to a `sale` whose `status = VOIDED`.
30. **Disposition determines stock effect, explicitly, never by default.**
    **(revised)** `refund_item.disposition` is a required field with four
    values (`RETURN_TO_STOCK`|`DAMAGED`|`EXPIRED`|`DISPOSED`). Only
    `RETURN_TO_STOCK` generates a `SALE_RETURN` stock movement; the other
    three generate none. No code path defaults an omitted disposition to
    `RETURN_TO_STOCK` — the field is mandatory, and a refund request
    missing it is rejected.
31. **Sale status reflects refund completeness, computed not asserted.**
    `sale.status` transitions to `PARTIALLY_REFUNDED` when some but not all
    value/quantity has been refunded, and to `REFUNDED` only when the full
    sale has been returned — computed server-side from the accepted-refund
    history (never set directly by a client request), and must be
    **deterministically derivable** from the set of `COMPLETED` refunds
    against that sale at any time (i.e., recomputing it from scratch always
    yields the same answer).

## Fiscal Day, Shift, and Readings

32. **At most one open fiscal day per terminal.** A `terminal` can have at
    most one `fiscal_day` with `status = OPEN` at any time — enforced by a
    partial unique constraint (`UNIQUE(terminal_id) WHERE status = 'OPEN'`).
33. **At most one open shift per terminal.** A `terminal` can have at most
    one `shift` with `status = OPEN` at any time — `UNIQUE(terminal_id)
    WHERE status = 'OPEN'`.
34. **At most one open shift per cashier.** A `cashier_id` can have at most
    one `shift` with `status = OPEN` at any time, across all terminals —
    `UNIQUE(cashier_id) WHERE status = 'OPEN'`. **(confirmed, not merely
    proposed, per owner decision)** A cashier who needs to work a different
    terminal must close (or hand off, if a future handoff feature exists)
    their current shift before opening a new one — there is no
    simultaneous multi-terminal cashier session in V1.
35. **A sale requires an open shift and an open fiscal day.** `sale.shift_id`
    must reference a `shift` with `status = OPEN`, and `sale.fiscal_day_id`
    must reference a `fiscal_day` with `status = OPEN`, both matching the
    sale's `terminal_id`, at the moment of finalization.
36. **A fiscal day cannot close while it has an open shift.** **(new)** A
    `fiscal_day`'s `OPEN → CLOSED` transition (and the corresponding
    `z_reading` generation) is rejected if any `shift` referencing that
    `fiscal_day_id` still has `status = OPEN` — end-of-day closure requires
    every shift within that business day to already be closed.
37. **Shift totals are computed, not entered.** `expected_cash`,
    `cash_sales`, `non_cash_sales`, `refunds_total`, `cash_in_total`, and
    `cash_out_total` are computed server-side from the
    sales/refunds/cash_movements attributed to that shift — never accepted
    as client-submitted values.
38. **Variance is recorded, never corrected away.** `variance =
    declared_cash − expected_cash` is stored as-is; no operation edits
    `declared_cash` after close to zero out variance, and no operation
    edits historical `sale`/`payment` rows to "fix" a shift's numbers.
39. **Cash-out authorization.** A `CASH_OUT` `cash_movement` at or above a
    configurable threshold requires `authorized_by` to hold the `CASH_OUT`
    capability.
    *(Wording corrected 2026-09-27 from "above" to "at or above": the implementation has always compared with `>=`, which is the
    stricter control, and a test pins it. owner-delegated decision, 2026-09-27; stage-23 addendum 8.)*
40. **Readings are reproducible, never authoritative inputs.** **(new)**
    `x_reading.totals_snapshot` and `z_reading.totals_snapshot` are always
    fully recomputable from `sale`/`payment`/`void`/`refund`/
    `cash_movement` for their respective scope (shift or fiscal day). No
    other calculation in the system — a later report, a subsequent
    reading, an accumulated total — ever reads a *prior* reading's stored
    totals as an input; it always recomputes from the underlying ledger.
    *(Clarified 2026-09-27: a shift's reading is recomputable **as of that shift's closing instant**. A sale voided after the shift
    closed, while its business day is still open, belongs to the voiding shift and day and does not rewrite the closed shift's reading.
    owner-delegated decision, 2026-09-27; stage-23 addendum 8; tested in `CloseAtomicityTest`.)*
41. **Readings are append-only.** Once generated, `x_reading` and
    `z_reading` rows are never updated or deleted.
42. **Exactly one Z-Reading per fiscal day.** **(new)** A `fiscal_day`
    produces exactly one `z_reading` row, created atomically with the
    `OPEN → CLOSED` transition (same database transaction) — never zero
    (closure requires it), never more than one (there is no "regenerate
    Z-Reading" operation; a data-entry mistake before EOD is corrected via
    Void/Refund on the underlying sales, not by re-running EOD).
43. **X-Readings do not require shift closure.** **(new)** An `x_reading`
    may be generated at any time while its `shift.status = OPEN` (an
    on-demand accountability pull) as well as automatically at shift close
    — generating one never changes `shift.status` or resets any total.

## Inventory ledger

44. **`stock_balance` is derived, never authoritative.** For any
    `(product_id, location_id)`, `stock_balance.quantity_on_hand` must
    always equal the signed sum of `stock_movement.quantity` for that
    pair. No code path writes to `stock_balance` except as an atomic
    side-effect of inserting a `stock_movement` row in the same
    transaction.
    *Amended by ADR-017 (built, slice B, 2026-10-27):* the same holds for
    `daily_stock_balances` per `(product_id, location_id, stock_date)`: it
    equals the signed sum of the movements carrying that `stock_date`;
    `stock_balances` is the signed sum of the movements WITHOUT a
    `stock_date`. `inventory:rebuild-balances` recomputes both.
45. **Every stock movement is append-only and attributed.**
    `stock_movement` rows are never updated or deleted, and every row
    records `reference_type`/`reference_id` and `created_by`.
46. **Adjustments require a reason.** Any `stock_movement` with
    `movement_type` in (`STOCK_ADJUSTMENT_IN`, `STOCK_ADJUSTMENT_OUT`,
    `DAMAGE`, `EXPIRED`, `DAILY_PORTIONS_REMOVED` (ADR-017)) must have a non-empty `reason`.
47. **Sale deducts, refund/void restore — always through the ledger, never
    through a direct balance edit.** A `COMPLETED` sale's inventory effect
    is one `SALE`-type movement per `sale_item` (negative); a `void` or a
    `RETURN_TO_STOCK` refund line produces a `SALE_RETURN`-type movement
    (positive). See Refund §30 for the disposition rule governing when a
    movement is or isn't created.
    *Restated by ADR-017 (built, slice B, 2026-10-27):* one `SALE` movement per
    sale line of a tracked product; a product linked to a daily dish writes
    against the dish's pool product with `quantity = line quantity x
    stock_portion`; a meal line writes one `SALE` per CHOICE selection of a
    daily dish (`quantity = meal quantity x the selection's
    stock_portion_snapshot`); its backing product and add-ons keep the
    untracked movement they always had. **Exception (client decision 4,
    2026-10-27): cooked food is never put back.** A void, or a
    `RETURN_TO_STOCK` refund line, of a line that drew from DAILY stock (a
    plain daily product, an ala carte product linked to a dish, or a meal
    whose chosen dishes drew portions) writes NO movement, and the refund
    records the disposition as `DISPOSED`; running stock behaves as before.

## Audit & electronic journal

48. **Append-only, structurally.** No application code path issues
    `UPDATE` or `DELETE` against `audit_event` or `electronic_journal_entry`
    — enforced at the database layer (e.g., `REVOKE UPDATE, DELETE` on
    these tables from the application's DB role) in Stage 5, not merely
    "the app doesn't happen to do this."
49. **Exactly one journal entry per fiscally-journalable event — no
    duplicates, no omissions.** **(revised/strengthened)** Every event
    classified as fiscally journalable (sale finalization → `INVOICE`;
    void completion → `VOID`; refund completion → `REFUND`; X-Reading/
    Z-Reading generation; shift open/close; cash in/out; stock adjustment)
    writes **exactly one** `electronic_journal_entry` row, in the same
    database transaction as the underlying domain change — enforced by a
    uniqueness constraint on `(source_type, source_id, event_type)`. A
    fiscally-journalable event that completes without a corresponding
    journal row is a bug, not an acceptable edge case; the reverse (a
    journal row with no real underlying event) cannot occur because the
    row is written as part of the same transaction as the event, never
    independently.
50. **Journal entries reference their source, not just describe it.**
    **(new)** Every `electronic_journal_entry` populates `source_type`/
    `source_id` pointing at the authoritative record (e.g., the `sale`,
    `void`, `refund`, `x_reading`, or `z_reading` row) and, where one
    exists, `audit_event_id` — the journal is a representation of that
    source, never an independently-maintained parallel fact.
51. **No user, including ADMIN, can edit or purge audit/journal history**
    through any application-level feature in V1. Per
    [BIR-009](../01-research/bir-reference-register.md), V1 implements no
    automated deletion of any age; any future retention-driven purge would
    be a deliberate, manually-executed, out-of-band administrative
    procedure — never a product feature.

## Authorization

52. **Capability-gated, not role-string-gated, at every sensitive
    endpoint.** **(revised)** Every sensitive operation (void request/
    approval, refund request/approval, price override, discount override,
    stock adjustment, cash out at or above threshold, settings change, fiscal
    day closure) checks a named capability
    (`can($user, CAPABILITY)` — see
    [domain-model.md §2.2](domain-model.md)) server-side, independent of
    what the frontend does or does not render. A cashier cannot execute a
    manager-only operation by calling the API directly, regardless of role
    naming or UI state.

## Tax

53. **Tax registration changes never rewrite history.** Changing a store's
    `tax_registration` inserts a new effective-dated row; every historical
    `sale`/`sale_item`/`invoice` continues to report using its own
    snapshot fields, never a join to the store's *current* registration.
54. **A store's tax registration is never silently defaulted.** No sale
    can be finalized while a store has zero active `tax_registration`
    rows — this is a required setup step, not an implicit fallback.

## Money & Quantity

55. **No floating-point arithmetic in any authoritative monetary
    calculation**, frontend or backend. **(new, generalizing the earlier
    "integer centavos" rule)**
56. **Rounding happens only at defined materialization points**, never
    mid-calculation — see [domain-model.md §3.1](domain-model.md) for the
    exact seven-point list (gross line amount, line discount, allocated
    order discount, sale-level tax decomposition, per-line tax allocation,
    refund amounts).
57. **Line totals reconcile to the grand total exactly. (revised — see
    DISC-006 for the authoritative statement.)** The formula is
    `SUM(sale_item.net_line_amount) = sale.grand_total`, **not**
    `SUM(line_total) − discount_total = grand_total` (that formula is
    retracted along with the un-prorated discount model it assumed — see
    [domain-model.md §3.1](domain-model.md)). This holds exactly for every
    `sale`, by construction of the rounding/allocation policy — not by a
    corrective adjustment applied after the fact.
58. **`Quantity` and `Money` are distinct types.** No code path treats a
    `sale_item.quantity`, `stock_movement.quantity`, or
    `refund_item.quantity_returned` value as a `Money` value or vice versa.

## Discount allocation (new — correction pass 2, 2026-09-16)

This section codifies the Deterministic Proportional Allocation algorithm
described in [domain-model.md §2.7](domain-model.md), retracting the
earlier "order-level discounts are never prorated" rule. Each invariant
below carries the `DISC-xxx` label the owner specified, in addition to a
sequential number.

59. **DISC-001 — Exact allocation sum.** The sum of
    `sale_item.allocated_order_discount_amount` across all
    `order_discount_eligible = true` lines of a sale must exactly equal
    `sale.order_level_discount_amount` — enforced by construction of the
    largest-remainder allocation algorithm, never by a corrective
    adjustment.
60. **DISC-002 — Allocation immutability.** Once a `sale` is `COMPLETED`,
    `sale_item.allocated_order_discount_amount` (and every other financial
    snapshot field on that line) is never recalculated or edited — not by
    a refund, not by a later promotion/discount configuration change, not
    by an administrative action.
61. **DISC-003 — No recomputation from current configuration.** Historical
    discount allocations, tax classifications, and tax rates are never
    recalculated using current product, promotion, tax, or store
    configuration. A report, a refund, or a reprint reads the
    `sale_item`/`invoice` snapshot fields only.
62. **DISC-004 — Refund eligibility derives from the finalized snapshot.**
    A `refund`'s monetary eligibility for a given `sale_item` derives
    exclusively from that line's finalized `net_line_amount` (which already
    incorporates `allocated_order_discount_amount` and
    `line_discount_amount`) — never from re-dividing
    `sale.order_level_discount_amount` again at refund time, and never from
    the item's current `selling_price`. The cumulative refunded monetary
    value for a `sale_item` can never exceed its `net_line_amount` (this
    restates and sharpens invariant #28's "eligible finalized consideration"
    to mean, precisely, `net_line_amount`).
63. **DISC-005 — Deterministic rounding residual.** Any rounding residual
    arising during discount allocation (§2.7's step 3) or tax allocation
    (the same algorithm's second application) is assigned using the
    largest-fractional-remainder rule with `sale_item.line_number` as the
    deterministic tie-breaker — never by random selection, iteration order,
    or any other non-reproducible mechanism. The same determinism
    requirement applies to the cumulative-recompute-then-subtract rounding
    rule for partial-quantity refunds ([domain-model.md §2.9](domain-model.md)).
64. **DISC-006 — Grand-total reconciliation (authoritative statement,
    supersedes #57's earlier formula).** `SUM(sale_item.net_line_amount) =
    sale.grand_total` holds exactly, for every `sale`, with no unexplained
    rounding adjustment — subject only to the sale-level amounts explicitly
    modeled (there is no residual "rounding line" or hidden correction).
65. **Mixed-tax-basket allocation scope.** The per-line `tax_amount`/
    `taxable_base` allocation (DISC-001–DISC-006's sibling mechanism for
    tax rather than discount) is scoped **only** to `VATABLE`-classified
    lines within a sale; `VAT_EXEMPT`/`ZERO_RATED`/`NON_VAT` lines are never
    included in that allocation's basis and always have `tax_amount = 0`,
    `taxable_base = net_line_amount` directly. A basket is never assumed to
    share one tax classification across all its lines.
66. **Discount eligibility is explicit, never inferred.** A `sale_item` not
    marked `order_discount_eligible = true` receives no share of
    `sale.order_level_discount_amount`, and this flag is never defaulted
    based on tax classification, product category, or any other inferred
    rule — it is set explicitly (defaulting to `true`) at the point the
    order-level discount is applied.

## Void & Refund processing context and settlement (new — Stage 2 amendment pass 3, 2026-09-16)

Approved following a Stage 3 remediation gap report: Stage 3's concurrency
architecture could not correctly lock a void/refund's own *processing*
fiscal day against a concurrent Z-Reading closure without a field to
identify it, and `shift.refunds_total` (already a Stage 2 field) had no
way to be computed without `refund` carrying its own `shift_id`. These
invariants close that gap; none of them changes any existing entity's
shape beyond the additive fields described in
[domain-model.md §2.8/§2.9](domain-model.md), any state machine transition,
or the meaning of any prior invariant.

67. **Processing context is always populated at execution, never at
    request.** `void.terminal_id`/`fiscal_day_id`/`shift_id` are set at
    the `APPROVED → VOIDED` transition; `refund.terminal_id`/
    `fiscal_day_id`/`shift_id` are set at the `APPROVED → COMPLETED`
    transition. Neither is inferred, defaulted, or copied from the
    `REQUESTED` row's context — a request and its execution are not
    guaranteed to happen at the same terminal.
68. **Processing context is independent of the original sale's context.**
    A `void`/`refund`'s `terminal_id`/`fiscal_day_id`/`shift_id` are never
    required to equal, and are never derived from,
    `sale.terminal_id`/`fiscal_day_id`/`shift_id`. A correction executed
    today against a sale from a prior, already-closed fiscal day is
    attributed to *today's* processing fiscal day and shift — it is never
    retroactively attributed to the original, closed `fiscal_day`.
69. **A void/refund requires an open processing fiscal day and shift at
    execution — analogous to invariant #35 for `sale`.** At the moment of
    the `APPROVED → VOIDED`/`APPROVED → COMPLETED` transition, the
    executing terminal's own `fiscal_day` must have `status = OPEN`, and
    the executing user must have an `OPEN` `shift` at that terminal. This
    is Void's new eligibility condition 6
    ([domain-model.md §2.8](domain-model.md)) and applies identically to
    Refund, which has no other fiscal-day-related eligibility condition
    (unlike Void, a refund's *original* sale's fiscal day is explicitly
    allowed to already be closed).
70. **`refund_settlement` sums reconcile exactly to `refund.refund_total`.**
    `SUM(refund_settlement.amount)` for a given `refund_id` must equal
    `refund.refund_total` exactly, checked at the same `APPROVED →
    COMPLETED` transition that creates the settlement rows — analogous to
    Sale's payment-sufficiency invariant (#8), in reverse.
71. **Cash-drawer effect is explicit per settlement method.** A `CASH`
    `refund_settlement` reduces the processing shift's expected physical
    drawer cash (factored into `shift.refunds_total`/`expected_cash` at
    close). A `GCASH`/`MAYA`/`CARD`/`OTHER` `refund_settlement` does
    **not** affect physical drawer cash — no code path treats a non-cash
    refund settlement as a cash-drawer event.
72. **`refund_settlement` rows are created atomically with the refund's
    completion, never independently.** All `refund_settlement` rows for a
    given `refund` are inserted in the same database transaction as the
    `APPROVED → COMPLETED` transition (per ADR-003's transaction-boundary
    discipline, extended here to Refund) — there is no code path that
    completes a refund without its settlement, or that creates a
    settlement row against a refund that is not `COMPLETED`.

## Non-VAT tax-summary model (new — Stage 6A implementation-discovered amendment, 2026-09-17)

Approved after Stage 6A's `FinancialCalculator` implementation found that
`TaxClassification.NON_VAT` (present since revision 1) had no
corresponding `sale`-level summary bucket, and that folding it into
`VAT_EXEMPT` would misrepresent a Non-VAT seller's transactions as a
VAT-registered seller's VAT-exempt sales — a real distinction under BIR
RR 7-2024/RMC 77-2024, not a cosmetic one. See
[domain-model.md §4a](domain-model.md) for the full rule. The
`non_vat_sales` field name is TindaFlow's own modeling decision, not a
BIR-mandated field name.

73. **TAX-NV-001 — NON_VAT sales are not VAT_EXEMPT sales.** No code path
    reports, aggregates, or persists a `NON_VAT`-classified line or the
    consideration of a `NON_VAT`-registered sale under `vat_exempt_sales`
    or any other VAT-registered-store bucket, and no code path infers a
    sale's registration type from its buckets being zero.
74. **TAX-NV-002 — A VAT-registered sale has `non_vat_sales = 0.00`.**
    Every `sale` finalized while the store's effective `tax_registration`
    is `VAT` has `sale.non_vat_sales = 0.00`, and every one of its
    `sale_item.tax_classification_snapshot` values is `VATABLE`,
    `VAT_EXEMPT`, or `ZERO_RATED` — never `NON_VAT`.
75. **TAX-NV-003 — A NON_VAT-registered sale zeroes every VAT bucket.**
    Every `sale` finalized while the store's effective `tax_registration`
    is `NON_VAT` has `taxable_sales = vat_exempt_sales = zero_rated_sales
    = vat_amount = 0.00`, every one of its
    `sale_item.tax_classification_snapshot` values is `NON_VAT`, and its
    finalized consideration is represented entirely by
    `sale.non_vat_sales = sale.grand_total`.
76. **TAX-NV-004 — Historical tax summaries are never reinterpreted.**
    A change to a store's `tax_registration` (`VAT → NON_VAT` or the
    reverse) never causes an already-finalized `sale`'s
    `non_vat_sales`/`taxable_sales`/`vat_exempt_sales`/`zero_rated_sales`/
    `vat_amount` values, or any `sale_item.tax_classification_snapshot`,
    to be recomputed, reclassified, or reported differently than at
    finalization — consistent with invariant #53's general rule for tax
    registration changes.
77. **TAX-NV-005 — Finalized `sale_item` tax classification is
    immutable.** Once a `sale_item.tax_classification_snapshot` is set at
    sale finalization, no subsequent operation (void, refund, tax
    registration change, report generation) modifies it; corrections
    happen only through the Void/Refund domain, never by mutating a
    finalized line's tax classification in place.

## InvoiceSeries bootstrap and resolution (new — Stage 6B implementation-discovered amendment, 2026-09-17; hardened after a second owner review the same day)

Discovered while implementing `InvoiceSeriesAllocator`. Item 78
corrects an off-by-one the frozen corpus never explicitly settled
either way (see `stage-6b-invoice-series-allocation.md` §8 for the full
investigation); items 79–81 close the resolution gap `DB-INV-018`
already implied but never a frozen document assigned a selection rule
to. Item 82 closes a related range-validity gap surfaced during the
second review pass.

78. **INVSERIES-001 — `starting_number` is never itself skipped.** A
    newly configured `invoice_series` is bootstrapped with
    `current_number = starting_number - 1`. `current_number` continues
    to represent the last allocated serial (ADR-004's increment-then-use
    mechanic, unchanged); the first real allocation therefore yields
    exactly `starting_number`, never `starting_number + 1`.
    `starting_number >= 1` always — `current_number = 0` may exist only
    as this pre-allocation bootstrap state for a series whose
    `starting_number = 1`, never as an issued `invoice_number`.
79. **INVSERIES-002 — `invoice_series` belongs to exactly one
    `fiscal_installation`.** `invoice_series.fiscal_installation_id` is
    required (never null); resolution of the applicable series for a
    finalizing sale is keyed by the terminal's currently-effective
    `fiscal_installation` (via `terminal_fiscal_installation`), never by
    `store_id` alone. `store_id` is retained on `invoice_series` for
    coherence/reporting but is never sufficient by itself to choose a
    series when a store has more than one.
80. **INVSERIES-003 — for V1 accountable sales invoicing, a
    `fiscal_installation` may have at most one `ACTIVE` `invoice_series`
    at a time; historical `CLOSED` `invoice_series` remain retained.**
    Mirrors the existing "current/active singleton, history preserved"
    pattern already applied to `shift`/`fiscal_day`/
    `terminal_fiscal_installation`. A store may still have multiple
    `invoice_series` rows (`DB-INV-018`), through multiple or historical
    `fiscal_installation`s, but never more than one simultaneously
    `ACTIVE` row per `fiscal_installation`. **Operational consequence
    (V1 does not invent automatic rollover):** an exhausted `ACTIVE`
    series does **not** automatically become `CLOSED` — after
    exhaustion, checkout against that `fiscal_installation` receives
    `INVOICE_SERIES_EXHAUSTED` on every attempt until an authorized
    configuration process explicitly closes/replaces the series; a
    replacement `invoice_series` cannot become `ACTIVE` for that
    `fiscal_installation` until the previous `ACTIVE` one is `CLOSED`
    (INVSERIES-003 itself, database-enforced, would otherwise reject
    it).
81. **INVSERIES-004 — a configured range is never backwards or
    zero-length by omission.** `ending_number IS NULL OR ending_number
    >= starting_number`. Without this, a misconfiguration such as
    `starting_number = 100, ending_number = 99` would only ever be
    observable as "already exhausted" rather than being rejected
    outright as invalid configuration at the point it is created.

---

## Canteen extension (new — ADR-016, proposed 2026-10-25, not yet built)

Items 82-99. Nothing above is weakened: #8 is met by a `CREDIT` payment row, #2/#7 cover the new snapshot tables, #35/#36/#42 keep
applying to the business day in slip mode.

82. **MENU-001 — snapshot immutability.** A meal line's menu group path, meal name, chosen dish and option names, dish category and prices
    are copied into `sale_item` / `sale_item_selection` in the finalize transaction and never updated. No report, slip, reprint or
    billing statement reads `dish`, `meal`, `meal_option` or `menu_price` names or amounts for a sale that already exists. Renaming,
    hiding or repricing menu data cannot change a past sale.
83. **MENU-002 — the server prices a meal.** The unit price of a meal line and of an add-on is the `menu_price` in force at `sold_at`,
    resolved server-side from the option in the `prices_the_meal` group's dish category; a client-submitted price is never used.
84. **MENU-003 — a valid selection.** Each choice group's selected count is within `min_select..max_select`; every option belongs to the
    meal, is active at finalization, and exactly one `prices_the_meal` group exists per meal; a meal line has quantity 1.
85. **MENU-004 — price history is append-only and unambiguous.** `menu_price` rows are only inserted; for any (meal, dish category) or add-on
    option at an instant, the row with the latest `effective_from <= instant` is the price; two rows may not share the same key and `effective_from`.
86. **MENU-005 — no hard delete of menu data.** Dishes, meals, groups and options are hidden (`active = false`), never deleted, because
    snapshots and the inventory phase reference `dish.id`.
87. **EMP-001 — the code is system generated.** `employee_code` is `TSC`/`SUB` plus a gap-free-per-type five-digit number from a locked
    counter, unique per store, immutable, never reused (including after deactivation). Only a user holding `EMPLOYEE_MANAGE` (ADMIN or MANAGER, amended 2026-10-26) can create or
    edit an employee; no cashier or till endpoint can. Every creation and change is audited with the acting user.
    *Built in slice 3.1 (2026-10-25):* the counter is `employee_code_sequences`, one row per store and type, locked `FOR UPDATE` in the creating transaction (a rollback gives the number back; two real processes get distinct gap-free codes — `EmployeeCodeConcurrencyTest`). The number grows past 99999 (`TSC100000`) rather than failing. Immutability is enforced twice: the API refuses `employee_code`/`employee_type` in a request, and the application database role has no UPDATE on those columns. The till's `employeeLookup` is an exact match only (case and surrounding whitespace ignored), store-scoped, and refuses an inactive employee (`EMPLOYEE_INACTIVE`).
88. **CREDIT-001 — credit is a whole-sale tender.** A `CREDIT` payment is the sale's only payment, equals its grand total, requires an
    active employee on the sale, and is refused in a sale that has any other tender.
    *Built in slice 3.2 (2026-10-26):* `CREDIT_MIXED_TENDER` / `CREDIT_EMPLOYEE_REQUIRED` (422), `EMPLOYEE_NOT_FOUND` (an unknown or other-store employee) and `EMPLOYEE_INACTIVE`; the recorded amount is always the server's grand total. `CreditSaleHttpTest`.
89. **CREDIT-002 — credit never touches the drawer.** A credit sale adds nothing to `cash_sales`, `expected_cash` or change allocation, and
    a refund or void of a credit sale settles to the credit ledger, never to a cash refund.
    *Built in slice 3.2:* the shift, X- and Z-readings already net the change through `NetCollectionService`, so CREDIT shows in `payment_breakdown` and `non_cash_sales` only; a credit refund must settle to CREDIT (`CREDIT_REFUND_SETTLEMENT_INVALID`) and never appears in `cash_refunds`. `CreditSaleHttpTest` pins expected cash, cash sales and the refund path.
90. **CREDIT-003 — the limit holds unless an audited override was consumed.** For an employee and cut-off period, `SUM(CHARGE) - SUM(REVERSAL)`
    plus a new charge never exceeds the limit in force, except by consuming a valid override grant in the same transaction. The balance is read
    under `FOR UPDATE` on the employee row, so two tills cannot both approve; the check is authoritative at checkout, the till's warning is not.
    *Built in slice 3.2, with the override added in slice 3.3 (see OVR-001):* exactly the limit is allowed, one centavo over is `CREDIT_LIMIT_EXCEEDED` (409, with limit, used, remaining, requested). Lock order: shift, fiscal day, **employee**, sale. `CreditConcurrencyTest` (real processes; fails without the lock).
91. **CREDIT-004 — the ledger is append-only and the period is fixed.** `employee_credit_entry` rows are never updated or deleted; the balance is
    derived, never stored. The period is computed from `sold_at` in the store timezone (1st-15th, 16th-end of month); a reversal carries the
    original charge's period.
    *Built in slice 3.2:* `App\Domain\CutOff` (unit-tested at the 15th/16th, month ends, leap and non-leap February, Manila midnight against UTC); one CHARGE per sale and one REVERSAL per void or refund are partial unique indexes; the application role has no UPDATE or DELETE (`AppendOnlyPrivilegesTest`).
92. **OVR-001 — an override is one sale, one approver, audited.** A grant is bound to terminal, employee, cashier and a maximum charge, expires in
    minutes, is single use (consumed atomically), and the approver differs from the cashier. `CREDIT_OVERRIDE_GRANTED`, `_USED` and `_REFUSED` audit
    events are written for every attempt; the ledger entry it enabled references the grant.
    *Built in slice 3.3 (2026-10-26):* `credit_override_grants` (token stored only as SHA-256; `max_amount`; 5-minute expiry; `consumed_at` is the one column the application role may UPDATE) and `CreditOverrideService`. Checkout consumes a grant ONLY when the charge would exceed the limit, under the employee lock and in the sale's transaction: it locks the grant row `FOR UPDATE`, checks terminal, employee, cashier, ceiling, expiry, and that the approver is still active, still holds `CREDIT_LIMIT_OVERRIDE` and is not the cashier, then stamps `consumed_at`; the CHARGE row carries `override_grant_id` (UNIQUE, so one grant can enable at most one charge). Refusals are `CREDIT_OVERRIDE_INVALID` / `_LOCKED` / `_EXPIRED` / `_NOT_ALLOWED` (422/423/409/403). A within-limit sale never consumes the grant; the next sale meets the limit again (no standing exemption); voids and refunds are unchanged. The `CREDIT_OVERRIDE_USED` event is written in the sale's own transaction; a refusal at checkout is audited after the rollback. The request hash covers the token, so a replay of the same sale returns it without consuming twice. Tests: `CreditOverrideHttpTest`, `CreditOverrideCheckoutTest`, the two-process replay in `CreditConcurrencyTest`, `AppendOnlyPrivilegesTest`.
93. **OVR-002 — override codes are secret and locked out.** Codes are stored only as slow hashes, differ from the login password, and five wrong
    attempts lock that approver for a period; failure counts are committed outside the checkout transaction so a rolled-back sale cannot erase them.
    *Built in slice 3.3 (2026-10-26):* `user_override_codes` (6 to 8 digits, `Hash::make`, NULL after an administrator clears it; the approver sets it for themselves after re-entering the account password; it must differ from that password and not be a repeated digit, a straight run or a repeated short block). The approver is NAMED at the till; the check runs in its own committed transaction with the approver's row locked `FOR UPDATE`; five wrong codes set `locked_until` (15 minutes) and zero the counter; a refusal never says whether an approver exists, is active, may approve or has a code (one generic `CREDIT_OVERRIDE_INVALID`). The verify endpoint is also throttled per terminal and per user (10 in 15 minutes, `RATE_LIMITED`). Checkout never touches a code, so a rolled-back sale cannot erase a count, and the verifier refuses to run inside any transaction (`LogicException`) so a wrapping rollback cannot either. No code, hash or token is written to a log or an audit payload. Tests: `CreditOverrideHttpTest`, `CreditOverrideLockoutTest` (real commits seen from a second connection).
94. **BILL-001 — a billing statement is derived and reconciles.** Statements are recomputed from `employee_credit_entry` and the sale snapshots;
    a saved `billing_run` is a frozen snapshot that is never edited; a later re-run shows differences as adjustments. The statement's net equals
    SUM(CHARGE) - SUM(REVERSAL) of the same sales in the ledger, and so does the sum of its item lines; a statement that does not reconcile is
    refused (`BILLING_RECONCILIATION_FAILED`) and never shown or saved.
    *Built in slice 4.1 (2026-10-26):* `BillingStatementBuilder::reconcile()` compares three independent figures (the accounts' total, the item
    lines, a separate ledger query) and, for a named cut-off, also the ledger's stored `period_start` grouping. Grouping reads snapshots only
    (MENU-001): Account = the CHARGE's employee snapshot (code, name, type); Meal type = the meal name snapshot for a meal line AND its add-on
    lines, the menu-group path snapshot for a plain product in a group, otherwise "Other items"; Item = the chosen dishes joined with " + ", the
    add-on label (its own row, counted as an item) or the product name. Amounts are `sale_items.net_line_amount` (DISC-006: they add up to the
    charge); a void reverses every line, a refund the `refund_items` it recorded. Tests: `BillingStatementHttpTest`.
95. **BILL-002 — cheques only reduce what is owed, and never more than is owed.** A `billing_cheque` is allocated IN FULL to one or more real
    cut-off periods (`billing_cheque_allocation`: append-only, positive, one row per cheque and period, the periods starting on the 1st or the
    16th), the allocations add up to the cheque amount exactly (no "on account" balance), and each allocation is at most what its period owes at
    the moment of recording (billed minus the allocations of RECEIVED cheques), checked under a row lock on the billing party so concurrent
    cheques cannot over-allocate. Recording a cheque never creates a `cash_movement`, a sale, a payment, a ledger entry, a journal entry or a
    reading, and never touches a shift or expected cash.
    *Built in slice 4.2 (2026-10-26):* `BillingChequeRequest` (shape, sum, real cut-off starts, no duplicate period), `BillingChequeService::record`
    (party lock, owed check via `BillingBalanceService::owedFor`, one transaction with the audit event), tables with `CHECK (amount > 0)`.
    Tests: `BillingChequeHttpTest`, `BillingChequeConcurrencyTest` (real processes), `AppendOnlyPrivilegesTest`.
    BILL-006 — **owed is derived.** Per cut-off period owed = billed - received, where billed = SUM(CHARGE) - SUM(REVERSAL) of the party's ledger
    entries with that `period_start` (the figure the statement of the same cut-off shows) and received = the allocations of cheques that are not
    RETURNED; nothing stores a balance. A void or refund recorded after a cheque lowers billed, so a period can become OVERPAID (owed < 0): it is
    shown as a credit to the party and never clamped or netted against other periods. "As of" ignores ledger entries and cheques recorded after the
    instant and counts a cheque returned only after it as received. Tests: `BillingChequeHttpTest`.
    BILL-007 — **a returned cheque reinstates the amount, once and for good.** `billing_cheques` is a lifecycle table: a recorded cheque is never
    edited or deleted; its only change is RECEIVED -> RETURNED with the reason, date, actor and instant, which makes its allocations stop counting
    (they are kept). A second return is refused (`CHEQUE_ALREADY_RETURNED`), a returned cheque is never un-returned, and a re-presented cheque is a
    new row; a second RECEIVED cheque with the same bank and number is refused (`CHEQUE_DUPLICATE`). The application role may UPDATE only
    `status`, `returned_on`, `returned_at`, `returned_by`, `return_reason`. Tests: `BillingChequeHttpTest`, `BillingChequeConcurrencyTest`,
    `AppendOnlyPrivilegesTest`.
    BILL-008 — **a write-off never exceeds what is owed, and is not money received.** A `billing_write_offs` row of kind WRITE_OFF names one real
    cut-off period, an amount > 0 and a required reason, and is at most what that period owes at the moment (billed - received - net written off),
    checked under the same row lock on the billing party a cheque takes, so a cheque and a write-off (or two write-offs) cannot both claim the same
    owed money. Owed is derived: billed - received - (SUM(WRITE_OFF) - SUM(REVERSAL)); nothing stores it. A write-off never creates a
    `cash_movement`, a sale, a payment, a ledger entry, a journal entry, a reading or a billing statement change, and never touches a shift or
    expected cash. A period that owes zero or less cannot be written off. If a later void or refund leaves more written off than is unpaid, the
    excess is reported (`write_off_excess_amount`, status OVER_WRITTEN_OFF), never shown as a credit to the party. A linked cheque, when given, must
    be a RETURNED cheque of the same store that paid that period. *Built in slice 4.3 (2026-10-26):* `BillingWriteOffService::record`,
    `BillingBalanceService`. Tests: `BillingWriteOffHttpTest`, `BillingWriteOffConcurrencyTest` (real processes), `AppendOnlyPrivilegesTest`.
    BILL-009 — **write-offs are append-only; a mistake is cancelled by a reversal.** `billing_write_offs` has neither UPDATE nor DELETE for the
    application role. A REVERSAL row carries exactly the amount and period of the write-off it cancels (composite foreign key), points at it
    through a UNIQUE `reverses_write_off_id` (a write-off is reversed at most once: `WRITE_OFF_ALREADY_REVERSED`), needs a reason, and cannot itself
    be reversed (`WRITE_OFF_NOT_REVERSIBLE`); writing the amount off again is a new write-off, checked against owed again. Every write-off and
    reversal writes an audit event in its own transaction (actor, period, amount, reason, cheque, owed before and after); a refused attempt writes
    none. Recording and reversing need `BILLING_MANAGE`. A retried `request_id` returns the original row. Tests: `BillingWriteOffHttpTest`,
    `BillingWriteOffConcurrencyTest`, `AppendOnlyPrivilegesTest`.
96. **SLIP-001 — one document per sale, in the store's mode.** A sale has exactly one of an `invoice` or a `sale_slip`, matching `sale.document_mode`.
    A slip sale never consumes an invoice number; a slip number is a per-terminal running integer allocated in the finalize transaction (no gap
    from a rolled-back sale), formatted so it cannot be mistaken for an invoice number. The store's `sale_document_mode` changes only under
    `FISCAL_CONFIGURATION_MANAGE`, while no shift or fiscal day is open, with an audit event.
    *Built in slice 2.2a (2026-10-25):* enforced by `CheckoutService` (one document per sale, in the mode read inside the checkout
    transaction), `slip_counters` (the number rolls back with the sale; `UNIQUE (terminal_id, slip_sequence)` and `UNIQUE (sale_id)` back it),
    `SaleDocumentSettingsService` (capability, nothing open, audit; a shared lock in `ShiftOpenService` closes the open-shift race) and
    `CHECK (document_mode IN ('INVOICE','SLIP'))`. There is no database trigger tying `sales.document_mode` to the presence of an `invoices`
    or `sale_slips` row; `tests/Database/SlipModeHttpTest` and `InvoiceModeUnchangedTest` pin it. The slip carries no invoice wording (it
    never says "invoice" or "official receipt"; the store's footer line is the only free text).
97. **BILL-003 — a reversal belongs to the original period.** For a statement, a charge belongs to a date range by the Manila calendar date of its
    sale, and a void or refund belongs to the range of the charge it reverses, whenever it was recorded (CREDIT-004). A statement "as of" an
    instant ignores every entry recorded after it, so any past statement can be reproduced, and a re-run after a later refund differs from the
    earlier run by exactly that adjustment. A named cut-off ends on the real last day of the month (28, 29, 30 or 31). Tests: `BillingStatementHttpTest`.
98. **BILL-004 — a saved billing run is immutable.** `billing_runs` is append-only (the application role has neither UPDATE nor DELETE); a re-run is a
    new row. The row stores the whole statement and the SHA-256 of its canonical JSON, so a stored statement that no longer matches its hash is
    reported (`statement_intact: false`). Tests: `BillingStatementHttpTest`, `AppendOnlyPrivilegesTest`.
99. **BILL-005 — run numbers are gap-free per store.** `BR-000001`, `BR-000002` ... come from `billing_run_counters`, locked `FOR UPDATE` and bumped
    in the same transaction as the insert, so a failed save leaves no gap and concurrent saves get distinct numbers. Tests:
    `BillingRunConcurrencyTest` (two processes), `BillingStatementHttpTest`.
100. **STK-001 — with blocking on, a sale never takes tracked stock below zero (except by a valid stock override).** When
    `store_settings.block_sale_without_stock` is true, every aggregated demand `(pool, stock_date, quantity)` of a sale must be at most the
    available quantity (a missing balance row is zero), checked inside the checkout transaction with the balance rows locked `FOR UPDATE` in the
    canonical stock order; otherwise the sale is refused (`INSUFFICIENT_STOCK`, 409, listing every short line) and nothing is written. Two tills
    selling the last portion: exactly one succeeds. With the setting false the ledger behaves as before and balances may go negative. Void and
    refund are never blocked. *Built, ADR-017 slice A (RUNNING stock) and slice B (the daily pools: a missing entry is zero, the demand of a meal, a Combo side and an ala carte portion of one dish is pooled per dish across the cart).* Tests: `StockBlockingHttpTest`, `StockBlockingConcurrencyTest` (two processes), `StockDemandResolverTest`, `DailyStockSalesTest`, `DailyStockConcurrencyTest` (two processes selling the last portion as a meal, as an ala carte order and mixed).
101. **STK-002 — daily stock belongs to its stock date and nothing resets it.** A movement of a DAILY item carries `stock_date`; a sale consumes only
    the balance of its own `stock_date` (the store-local date of `sold_at` less `stock_day_cutover_hour`, never a terminal's fiscal day: with the
    canteen's cutover of 5 a sale at 04:59 uses yesterday's stock and one at 05:00 today's); yesterday's remaining quantity is never available
    today; a day's leftover is its remaining balance, derived, with no closing movement and no scheduled job; entry is allowed for the current
    stock date only. *Built, ADR-017 slice B.* Tests: `DailyStockSalesTest` (the 04:59 and 05:00 boundaries, the midnight default, yesterday not counting),
    `StockDayTest` (UTC instants, year and leap-day boundaries), `DailyStockAdminTest` (the report across days).
102. **STK-003 — a portion is snapshotted, and pooled per dish.** The portion drawn for a meal selection is stored on
    `sale_item_selection.stock_portion_snapshot` (and `sale_item.stock_portion_snapshot` for a dish-linked product) when the sale is made; a
    later change to the menu or product never alters it. The client's portions (2026-10-27): a Standard Meal viand 1.0, a Combo main 1.0, the Combo
    vegetable side 1.0, an ALA CARTE sale of a dish 2.0 (each a per-option or per-product setting); rice and other add-ons draw nothing. The
    demand of a whole cart is added up per dish pool before it is checked. **Cooked food is never put back:** a void or refund of such a line
    writes no `SALE_RETURN` (the ADR's earlier "return exactly the snapshotted portion to the original date" was withdrawn by client decision 4),
    so the leftover of a day never grows after the food was served. *Built, ADR-017 slice B.* Tests: `DailyStockSalesTest`, `StockDemandResolverTest`.
103. **STK-004 — daily entry is ledger-only, by STOCK_ADJUST or by a cashier with a manager's code.** Receipts and adjustments need `STOCK_ADJUST`
    (ADMIN, MANAGER; never CASHIER) and an enrolled terminal; daily entry is `DAILY_PORTIONS_ADDED` / `DAILY_PORTIONS_REMOVED` movements through
    `StockLedger` (the only writer), attributed (`created_by`, and `approved_by` for an approved cashier entry), idempotent; it sets a day's cooked total
    or adds a second batch; lowering needs a reason and may not take a day below what is already sold (`DAILY_STOCK_BELOW_SOLD`). A product's or
    dish's stock mode changes only when its current position is zero (`STOCK_MODE_CHANGE_BLOCKED`). The store's stock rules, including the cutover
    hour, are switched by `STOCK_ADJUST` (MANAGER and ADMIN since 2026-10-27). *Built, ADR-017 slice B.* Tests: `DailyStockEntryTest`, `DailyStockAdminTest`,
    `StockBlockingHttpTest`.
104. **STK-005 — a stock override is one sale, one approver, audited.** A `stock_override_grant` is bound to terminal, cashier and the exact shortfall
    demand (hash), expires in minutes, is single use (consumed atomically, only when a shortfall actually exists, with `sale_id` recorded), the
    approver holds `STOCK_SALE_OVERRIDE` and differs from the cashier, approver codes are the OVR-002 codes (same lockout), and `STOCK_OVERRIDE_*`
    audit events are written for every attempt. With `stock_override_enabled` false no grant is issued or accepted. *Built, ADR-017 slice A.* `sale_id` set on the grant is the "oversold" flag. Tests: `StockBlockingHttpTest`, `StockBlockingConcurrencyTest` (a grant raced by two sales is consumed once), `AppendOnlyPrivilegesTest`.
106. **STK-007 — a cashier's daily stock entry is one save, one manager, audited.** A cashier enters daily stock only with a `stock_entry_grant`: a
    manager's own code (OVR-002, the same lockout as the credit and stock overrides) issues a single-use token bound to the terminal, the cashier, the
    store and the stock date, valid five minutes; the approver holds `STOCK_ADJUST` and differs from the cashier; the entry consumes it in its own
    transaction (a failed save leaves it usable); the movements and the `DAILY_STOCK_ENTERED` audit event carry both ids; no code or token is ever
    logged or audited. *Built, ADR-017 slice B.* Tests: `DailyStockEntryTest`, `AppendOnlyPrivilegesTest`.
107. **STK-008 — a dish's pool and its ala carte items.** A DAILY dish owns one hidden pool product (hidden, inactive, tracked, DAILY, renamed with the
    dish, reused if the dish is switched off and on); every daily balance of the dish is keyed by it. An ala carte product linked to the dish
    (`products.dish_id`) never writes its own balance. "Create ala carte items" makes one per daily dish without one, is idempotent and audited, and
    never invents a price. `products.stock_mode` equals `track_inventory` (tracked exactly when not NONE). Pool products never appear in the running
    stock views, the product list, the CSV or the pre-flight. *Built, ADR-017 slice B.* Tests: `DailyStockAdminTest`.
105. **STK-006 — shelf life is advisory.** `product.shelf_life_days` (1 to 3650, NULL = none; set with `CATALOG_MANAGE`, so ADMIN and MANAGER) only drives a
    derived "check freshness" flag: a RUNNING product with stock on hand whose latest `OPENING_STOCK` or `PURCHASE_RECEIPT` is more than `shelf_life_days`
    store-local calendar days old (`TRANSFER_IN`, returns and adjustments do not count; a product never received is not flagged). It is computed on read
    from the ledger, never stored, never blocks a sale, never changes a balance, and stores no expiry date or batch. A fresh receipt on top of old units
    clears it (accepted weakness). *Built, ADR-017 slice C.* Tests: `StockFreshnessHttpTest`, `ProductCsvHttpTest`.
108. **EOD-001 — the end-of-day sales by meal type reconciles with the day's net sales, and a reprint equals the original.** For a slip-mode day the
    reading's `sales_by_meal_type` section satisfies: `sold_amount` = `gross_sales`; `returned_amount` = `refund_total`; `net_amount` = `gross_sales` -
    `refund_total`; the meal types' net amounts add up to `net_amount` and each type's items add up to the type. A voided sale is in no line (its grand
    total is `voided_amount` = `void_total`, memo only). Credit sales count as sales. The section is written once, with the reading, and printed
    only from the stored copy, so an original and every copy show identical figures whatever happens to the menu or the sales afterwards; it exists
    only for a slip-mode day with something to list, and an invoice-mode day's snapshot is unchanged. The standalone report `reportSalesByMealType`
    runs the same query and gives the same figures for the same day. Meal type / item classification is shared with the billing statement
    (`SaleLineClassifier`). *Built, ADR-016 slice 4.4.* Tests: `EndOfDayMealTypeTest`, `BillingStatementHttpTest`.
