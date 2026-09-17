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
39. **Cash-out authorization.** A `CASH_OUT` `cash_movement` above a
    configurable threshold requires `authorized_by` to hold the `CASH_OUT`
    capability.
40. **Readings are reproducible, never authoritative inputs.** **(new)**
    `x_reading.totals_snapshot` and `z_reading.totals_snapshot` are always
    fully recomputable from `sale`/`payment`/`void`/`refund`/
    `cash_movement` for their respective scope (shift or fiscal day). No
    other calculation in the system — a later report, a subsequent
    reading, an accumulated total — ever reads a *prior* reading's stored
    totals as an input; it always recomputes from the underlying ledger.
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
45. **Every stock movement is append-only and attributed.**
    `stock_movement` rows are never updated or deleted, and every row
    records `reference_type`/`reference_id` and `created_by`.
46. **Adjustments require a reason.** Any `stock_movement` with
    `movement_type` in (`STOCK_ADJUSTMENT_IN`, `STOCK_ADJUSTMENT_OUT`,
    `DAMAGE`, `EXPIRED`) must have a non-empty `reason`.
47. **Sale deducts, refund/void restore — always through the ledger, never
    through a direct balance edit.** A `COMPLETED` sale's inventory effect
    is one `SALE`-type movement per `sale_item` (negative); a `void` or a
    `RETURN_TO_STOCK` refund line produces a `SALE_RETURN`-type movement
    (positive). See Refund §30 for the disposition rule governing when a
    movement is or isn't created.

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
    stock adjustment, cash out above threshold, settings change, fiscal
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
