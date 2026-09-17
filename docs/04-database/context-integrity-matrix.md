# Context-Integrity Matrix — TindaFlow POS

## Status

DRAFT — owner-requested hardening pass, 2026-09-16. Produced in response
to the owner's conditional approval of Stage 5, which correctly
identified that DB-INV-063 (cross-store/cross-terminal referential
consistency) could not be deferred wholesale to Stage 6: a
single-column FK only proves a referenced row *exists*, never that it
belongs to the same Store/Terminal execution context as its parent row.
This document reviews every table the owner named, states whether an
invalid combination was structurally possible before this pass, and
records what was added and why.

**Mechanism used throughout: composite `UNIQUE` + composite `FOREIGN
KEY`, never a trigger.** PostgreSQL supports foreign keys over column
groups provided the referenced column set is backed by a unique (or
primary key) constraint. No table needed a trigger to express these
rules — every one is a straightforward multi-column FK.

**Nullable processing context (Void/Refund):** a composite FK using
PostgreSQL's default `MATCH SIMPLE` semantics is automatically skipped
when *any* column in the FK's column list is `NULL`. Since
`voids_processing_context_check`/`refunds_processing_context_check`
already guarantee `terminal_id`/`fiscal_day_id`/`shift_id` are either
all `NULL` (not yet executed) or all populated, the new composite FKs
compose cleanly with that existing CHECK: there is nothing to validate
before execution, and everything to validate once execution context
exists.

---

## Matrix

| Table | Context columns | Independent FK before this pass | Invalid combination possible before this pass? | Database enforcement added | Reason |
|---|---|---|---|---|---|
| `terminals` | `store_id` | Yes (`store_id → stores.id`) | N/A — only one context column, nothing to disagree with it | Added `UNIQUE(store_id, id)` | Not itself an invariant fix — this is the referenced side every downstream composite FK needs |
| `fiscal_installations` | `store_id` | Yes | N/A — same reasoning as `terminals` | Added `UNIQUE(store_id, id)` | Referenced side for `terminal_fiscal_installations`' composite FK |
| `terminal_fiscal_installations` | `store_id` (new), `terminal_id`, `fiscal_installation_id` | `terminal_id → terminals.id`, `fiscal_installation_id → fiscal_installations.id` | **Yes.** A terminal from Store B could be associated with a `fiscal_installation` belonging to Store A — both single-column FKs would happily accept it | Added `store_id` column (disclosed denormalization) + `FOREIGN KEY (store_id, terminal_id) REFERENCES terminals(store_id, id)` + `FOREIGN KEY (store_id, fiscal_installation_id) REFERENCES fiscal_installations(store_id, id)` | The owner explicitly named "any FiscalInstallation/Terminal relationship where store ownership can become inconsistent" — this is exactly that relationship |
| `fiscal_days` | `store_id`, `terminal_id` | `store_id → stores.id`, `terminal_id → terminals.id` | **Yes.** A `fiscal_day` could declare `store_id = A` while `terminal_id` actually belongs to Store B | Added `UNIQUE(terminal_id, id)` (for downstream joins) + `FOREIGN KEY (store_id, terminal_id) REFERENCES terminals(store_id, id)` | Closes the fiscal_day's own internal store/terminal coherence, so nothing downstream can inherit an already-broken pairing |
| `shifts` | `terminal_id`, `fiscal_day_id` | `terminal_id → terminals.id`, `fiscal_day_id → fiscal_days.id` | **Yes.** A shift could open on Terminal 1 while citing a `fiscal_day` that actually belongs to Terminal 2 | Added `UNIQUE(terminal_id, id)` (for downstream joins) + `FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days(terminal_id, id)` | Verified live: `test_shift_rejects_fiscal_day_from_a_different_terminal` (schema-validation.md) |
| `sales` | `store_id`, `terminal_id`, `shift_id`, `fiscal_day_id` | Four independent single-column FKs | **Yes — the owner's original example exactly.** Store A's sale could cite Store B's shift, or a terminal-A sale could cite a terminal-B fiscal_day, while every individual FK stayed satisfied | Added three composite FKs: `(store_id, terminal_id) → terminals(store_id, id)`, `(terminal_id, shift_id) → shifts(terminal_id, id)`, `(terminal_id, fiscal_day_id) → fiscal_days(terminal_id, id)` | **DATABASE**, verified live by 5 negative tests in `ContextIntegrityTest` (schema-validation.md) covering both the cross-store and same-store/cross-terminal cases the owner named |
| `voids` | `terminal_id`, `fiscal_day_id`, `shift_id` (nullable, processing context) | Three independent nullable single-column FKs | **Yes**, once execution context is populated — the processing terminal/shift/fiscal_day could mutually disagree the same way `sales`' could | Added `FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts(terminal_id, id)` + `FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days(terminal_id, id)`, composing with the existing `voids_processing_context_check` | **DATABASE**, verified live: `test_void_processing_context_rejects_mismatched_terminal_and_fiscal_day` |
| `refunds` | `terminal_id`, `fiscal_day_id`, `shift_id` (nullable, processing context) | Same as `voids` | Same as `voids` | Same construction as `voids` | **DATABASE**, verified live: `test_refund_processing_context_rejects_mismatched_terminal_and_shift` |
| `cash_movements` | `shift_id` only | `shift_id → shifts.id` | **No.** A single context column has no sibling to disagree with — the row is scoped to exactly one shift, and that shift's own internal coherence is already closed at the `shifts` level above | None needed | Reviewed and found sound as-is; adding a composite FK here would be exactly the "mechanically add composite FKs everywhere" the owner said not to do |
| `x_readings` | `terminal_id`, `shift_id` | Two independent single-column FKs | **Yes.** An x_reading could claim `terminal_id = A` while `shift_id` references a shift that actually ran on Terminal B | Added `FOREIGN KEY (terminal_id, shift_id) REFERENCES shifts(terminal_id, id)` | **DATABASE** |
| `z_readings` | `terminal_id`, `fiscal_day_id` | Two independent single-column FKs | **Yes.** Same shape as `x_readings`, against `fiscal_day_id` instead of `shift_id` | Added `FOREIGN KEY (terminal_id, fiscal_day_id) REFERENCES fiscal_days(terminal_id, id)` | **DATABASE** |
| `idempotency_records` | `terminal_id` only | `terminal_id → terminals.id` | **No.** Single context column, same reasoning as `cash_movements` | None needed | Reviewed and found sound as-is |
| `invoices` | `store_id` (new), `sale_id`, `invoice_series_id`, `terminal_id` | `sale_id → sales.id`, `invoice_series_id → invoice_series.id`, `terminal_id → terminals.id` | **Yes, at two levels.** (1) A Store A sale could be documented by a Store B invoice_series, or cite a Store B terminal. (2) Even after (1) was closed, a Store A invoice could still cite a Store A sale finalized on a *different* Store A terminal than the invoice itself declared — a same-store, cross-terminal disagreement no store-level check could see | Added `store_id` column + `(store_id, invoice_series_id) → invoice_series(store_id, id)` + `(store_id, terminal_id, sale_id) → sales(store_id, terminal_id, id)` (this single three-column FK replaced two narrower composite FKs from the first follow-up pass, which became redundant once it existed — see below) | **DATABASE** for structural store coherence AND Invoice/Sale terminal identity (owner follow-up passes, 2026-09-16 — see below); **TRANSACTION** for the separate, harder question of whether the series/installation was temporally eligible at `issued_at` |

---

## Invoice structural coherence (owner follow-up pass, 2026-09-16)

The owner's review of the first hardening pass correctly separated
Invoice's exposure into two distinct invariants and asked that only the
first be closed now:

- **(A) Structural ownership** — the `Sale` and `InvoiceSeries` an
  `Invoice` cites must belong to the same `Store`. This is ordinary
  relational integrity, closeable the same way as every other row in
  the matrix above.
- **(B) Temporal eligibility** — whether the selected `InvoiceSeries`
  was `ACTIVE`, or the `FiscalInstallation` assignment was effective,
  *at the exact `issued_at` instant*. A composite FK cannot express
  "valid at this historical moment"; this remains **TRANSACTION**
  (Stage 6: lock/resolve the series, verify eligibility, allocate,
  persist — all inside the same transaction), not weakened into a
  database constraint it cannot honestly express.

**(A) is now closed, and was further strengthened by a second owner
follow-up review.** `invoices` gained a `store_id` column (disclosed
denormalization, same pattern as `terminal_fiscal_installations`
earlier). The first pass added three composite FKs (store↔sale,
store↔series, store↔terminal); the owner's second follow-up correctly
identified that this trio still permitted a structurally impossible
state: a Store A invoice citing a Store A sale that was actually
finalized on Terminal 01, while the invoice itself declared Terminal
02 — both terminals legitimately belong to Store A, so no store-level
check ever saw the disagreement.

**Final FK set on `invoices`** (2 of the original 3 composite FKs were
removed as redundant, not kept alongside the stronger replacement):

| FK | Proves |
|---|---|
| `FOREIGN KEY (store_id, invoice_series_id) REFERENCES invoice_series(store_id, id)` | The invoice's own declared store matches the series it drew its serial from — an independent invariant, kept |
| `FOREIGN KEY (store_id, terminal_id, sale_id) REFERENCES sales(store_id, terminal_id, id)` | **Invoice/Sale terminal identity** — the invoice's declared store *and* terminal both match the sale it documents, not merely that both belong to the same store |

**Why the original `(store_id, sale_id) → sales(store_id, id)` and
`(store_id, terminal_id) → terminals(store_id, id)` FKs were removed
rather than kept alongside the new one:** the three-column FK is
strictly stronger than both. Matching `(store_id, terminal_id,
sale_id)` against a `sales` row necessarily implies matching
`(store_id, sale_id)` against that same row (a match on a superset of
columns implies a match on any subset) — so the first FK's guarantee
is subsumed directly. The second FK's guarantee (the invoice's terminal
genuinely belongs to its store) is subsumed *transitively*: `sales`
itself already carries `sales_store_terminal_fk` (added in the first
hardening pass), proving every sale's own `(store_id, terminal_id)`
pair is valid — since the new FK proves the invoice's `(store_id,
terminal_id)` pair is identical to some sale's already-validated pair,
it inherits that same guarantee for free. Keeping the two narrower FKs
would have proven nothing the three-column FK doesn't already prove,
so they were dropped rather than left as redundant constraints — this
directly answers the owner's item 3 ("prefer the smallest constraint
set that proves all required relationships without redundant
indexes/FKs").

This required one new supporting composite `UNIQUE(store_id, terminal_id,
id)` on `sales` (added specifically for this fix — the prior pass's
`UNIQUE(store_id, id)`/`UNIQUE(terminal_id, id)` on `sales` don't cover
the three-column tuple this FK needs) plus the `UNIQUE(store_id, id)`
on `invoice_series` from the first follow-up pass. No
`shift_id`/`fiscal_day_id` was added to `invoices` — the correction
concerns only `terminal_id`, a context field already persisted on
`invoices` per the frozen ERD; Invoice has no independent shift/
fiscal_day eligibility condition of its own.

Verified live by 8 tests in `InvoiceContextIntegrityTest.php`: a
Store A sale rejects a Store B invoice_series; a matching pair still
succeeds; an invoice whose own `store_id` disagrees with its sale is
rejected; an invoice citing a different-store terminal is rejected;
duplicate serials within one series are still rejected; the same
numeric serial in a different, legitimate series is still permitted;
**and — the two tests added for this pass — an invoice citing a
different terminal than the one that actually finalized its sale is
rejected even when both terminals belong to the same store, while an
invoice whose terminal matches its sale's terminal is accepted.**

**(B) remains explicitly TRANSACTION-level**, documented in
constraint-register.md as DB-INV-063k, unchanged by this pass — the
owner's instruction was explicit that temporal eligibility (series/
installation validity at the issuance instant) stays Stage 6 logic,
and this pass did not touch it.

## Explicitly still deferred, not silently ignored

**`sale_items.product_id`** carries the same class of risk one level
further down (a `sale_item` could reference a `product` belonging to a
different store than its `sale`) but was out of the owner's named list
for both hardening passes and is left as part of the broader,
already-disclosed DB-INV-063 family rather than fixed here.

---

## Same-store-is-not-enough verification

The owner specifically asked that same-store/different-terminal
combinations be checked, not just cross-store ones. Every composite FK
above joins on `terminal_id` (not `store_id` alone) wherever a shift or
fiscal_day is involved, so a same-store attack — Terminal A's sale
citing Terminal A2's shift or fiscal_day, both legitimately in Store A —
is rejected by exactly the same mechanism as a genuine cross-store one.
Verified live by `test_sale_rejects_terminal_a_with_terminal_b_shift_same_store`
and `test_sale_rejects_terminal_a_with_terminal_b_fiscal_day_same_store`
in `ContextIntegrityTest` — see schema-validation.md for full results.
