# ADR-003: Checkout Finalization Is One Atomic Database Transaction

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2's domain model (invariant #6, "Atomicity of finalization") already
requires that creating `sale`, `sale_item`, `payment`, allocating the
invoice number, writing `invoice`, writing `SALE`-type `stock_movement`
rows, and writing `audit_event`/`electronic_journal_entry` rows all happen
inside one database transaction. Stage 3's job is to translate that
domain-level invariant into a concrete transaction boundary a Laravel
service can implement, and to confirm no step is deferred to "eventual"
consistency.

## Decision
A single application-layer service (`CheckoutService::finalize()`, module:
Checkout/Sales) wraps the entire finalize-sale operation in one
`DB::transaction()` block, in this order:

1. Validate the idempotency key (see ADR-010); if a `sale` already exists
   for this `(store_id, idempotency_key)`, short-circuit and return the
   existing result — no further steps run.
2. Acquire the necessary row locks (the `shift` row, the `fiscal_day` row,
   and the `invoice_series` row — see ADR-004 and architecture.md §5/§8 for
   lock ordering) via `SELECT ... FOR UPDATE`.
3. Re-validate, inside the lock: the `shift` is `OPEN` and belongs to this
   terminal/cashier; the `fiscal_day` is `OPEN`; each requested product's
   current snapshot data is fetched (price, tax class) — this is the last
   point at which "current" data is read; everything after this is frozen
   into the sale.
4. Run the authoritative Money/Tax calculation (architecture.md §11):
   recompute line amounts, discount allocation, tax decomposition — never
   trusting client-submitted totals beyond comparing them for a
   user-facing mismatch warning.
5. Insert `sale` (status `COMPLETED` from the moment it exists — see
   Stage 2, there is no `DRAFT` row), `sale_item` rows with full financial
   snapshots, `payment` rows.
6. Allocate the invoice number (increment the locked `invoice_series` row)
   and insert `invoice` with its `invoice_snapshot_json`.
7. Insert one `SALE`-type `stock_movement` row per `sale_item`.
8. Insert the `audit_event` (`SALE_FINALIZED`) and
   `electronic_journal_entry` (`INVOICE`) rows.
9. Commit.

If any step from 2–8 throws, the entire transaction rolls back: no `sale`
row, no consumed invoice number (the lock is released without the
increment having been committed), no stock movement, no journal entry. The
client receives an error and may safely retry with the **same**
idempotency key (see ADR-010).

## Alternatives Considered
- **Two-phase: create a provisional `sale` first, then a background job
  finalizes invoice/stock/audit** — rejected. This reintroduces exactly the
  "partially finalized sale" state Stage 2's immutability invariant exists
  to prevent, and requires reconciliation logic for the failure window
  between the two phases that a single transaction avoids entirely.
- **Saga / compensating-transaction pattern** (common in distributed
  systems, where each step commits independently and failures trigger
  compensating actions) — rejected as unnecessary complexity: this is a
  distributed-systems pattern for when steps *can't* share a transaction
  (different services/databases). Everything here lives in one PostgreSQL
  database, so a native ACID transaction is strictly simpler and strictly
  stronger (no compensating-action bugs to write or test).
- **Fire audit/journal writes asynchronously (queue-based) for
  performance** — rejected for V1; see ADR-005. Fiscal journal entries
  specifically must not be eventually consistent.

## Consequences / Trade-offs
- **Positive:** "no partially finalized sale" is a database guarantee, not
  an application convention that could be violated by a missed `try/catch`.
- **Positive:** rollback behavior is uniform and simple to test (Stage 8):
  force a failure at each step and assert nothing was persisted.
- **Trade-off:** the transaction holds row locks (shift, fiscal_day,
  invoice_series) for the duration of steps 3–8, which is longer than the
  bare invoice-allocation lock alone. This is an intentional, bounded
  trade-off — see the concurrency analysis in architecture.md §24 — the
  transaction is expected to complete in low tens of milliseconds under
  V1's load (a handful of concurrent terminals), so lock contention is not
  expected to be a practical bottleneck; this should be confirmed against
  the performance targets in architecture.md §21 during Stage 8 testing.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 5 (migrations/constraints must support the
required row locks), Stage 6 (`CheckoutService` implementation), Stage 8
(rollback/idempotency test suite).
