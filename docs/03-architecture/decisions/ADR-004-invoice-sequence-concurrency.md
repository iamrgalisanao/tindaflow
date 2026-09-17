# ADR-004: Invoice Sequence Concurrency via Row-Level Locking

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2 froze `invoice_series` as an explicit aggregate (not a
`stores.next_invoice_number` column) with the invariant that concurrent
terminals must never receive the same number, no number is ever reused,
and a failed attempt must never burn a number. Stage 3 must pick a concrete
PostgreSQL mechanism.

## Decision
**`SELECT ... FOR UPDATE` on the specific `invoice_series` row**, taken as
the first lock acquired inside the checkout transaction (ADR-003 step 2),
before the `current_number` is read or incremented:

```sql
SELECT id, current_number, ending_number, status
FROM invoice_series
WHERE store_id = :store_id AND status = 'ACTIVE'
FOR UPDATE;
```

A second terminal's transaction requesting the same row blocks at this
`SELECT` until the first transaction commits or rolls back — PostgreSQL's
native row-lock queuing handles the serialization; no application-level
mutex, distributed lock service, or in-memory counter is introduced.

After acquiring the lock: check `ending_number` (if set) is not exceeded
(architecture.md §7 gap analysis — series exhaustion fails the request
cleanly); increment `current_number`; use the new value to format
`invoice_number`. The increment and every other write in the transaction
commit together (ADR-003); if the transaction rolls back for any reason,
PostgreSQL rolls back the `UPDATE` to `current_number` along with it — the
row lock is released and the *next* transaction to acquire it sees the
pre-failure value, so the failed attempt's number was never actually
consumed.

**Correction (this revision): a legitimate void does not create a numbering
gap.** An earlier draft of this ADR conflated "voided" with "missing" —
they are not the same thing. When sale #1005 is voided, invoice number
1005 remains **permanently allocated and present** in the sequence (Stage
2 invariant #11: the number is never released, reassigned, or freed for
reuse); its `invoice` row and `sale` row still exist, dated, with
`status = VOIDED`, fully accounted for in the series. The sequence
1004 → 1005 → 1006 is **continuous** — there is no missing serial, only a
voided one. Nothing about this architecture's allocation mechanism ever
produces an actual gap (a number that was silently skipped, with no
corresponding `invoice`/`sale` row at all, voided or otherwise): allocation
and sale-creation are one transaction (ADR-003), so a failed attempt never
consumes a number in the first place, and a successful allocation is never
later un-allocated.

**The real exception condition to document is an unexpected/unexplained
missing serial** — a number that appears absent from the sequence with no
corresponding `invoice` row (voided or completed) at all. Under this
architecture, that should never occur through normal operation; it could
only arise from something outside the application's own control-flow
(e.g., direct database manipulation, a restored backup taken between two
allocations in a way that skips state, or an undiscovered bug). **This
architecture does not invent a BIR treatment for that scenario — it is
marked `BIR-REVIEW-REQUIRED`** (see
[bir-reference-register.md](../../01-research/bir-reference-register.md)):
no issuance reviewed so far specifies the operational/compliance handling
of a genuinely unexplained missing serial in an accountable-form series,
and this should be confirmed (and, ideally, never actually tested in
production) before a real accreditation attempt.

**Deadlock avoidance:** the checkout transaction always acquires locks in a
fixed order — `shift` → `fiscal_day` → `invoice_series` — across every code
path that takes more than one of these locks, specifically to prevent a
lock-ordering deadlock between checkout finalization and Z-Reading closure
(architecture.md §24 walks through this scenario explicitly).

## Alternatives Considered
- **A native PostgreSQL `SEQUENCE`** — rejected as the *sole* mechanism,
  though sequences remain fine for non-fiscal auto-increment needs
  elsewhere. A bare `SEQUENCE` cannot represent "no reuse even after
  rollback" as cleanly as a row-locked, transactionally-updated
  column, because `nextval()` on a sequence is **not** transactional by
  design (PostgreSQL sequences deliberately don't roll back, specifically
  to avoid the lock contention a transactional counter would cause under
  high concurrency) — which means a rolled-back transaction *would* burn a
  sequence value, directly violating Stage 2's "no gaps from failed
  attempts" invariant (#14). A locked table row, updated inside the same
  transaction as everything else, is transactional by construction and is
  the correct primitive here even though it holds a lock slightly longer
  than a sequence would.
- **Application-level in-memory counter (per Laravel worker process)** —
  rejected outright per Stage 2's explicit prohibition ("no application-
  memory counter"): multiple PHP-FPM worker processes (and, if the app
  ever scales beyond one server, multiple server instances) would each
  have their own counter, guaranteeing duplicates.
- **Optimistic locking via `invoice_series.version`** (read the row without
  a lock, attempt an `UPDATE ... WHERE version = :seen_version`, retry on
  conflict) — considered and rejected as the primary mechanism for this
  specific case: it would require wrapping the *entire* multi-step
  checkout transaction in a retry loop (since the version check happens
  after all the other work in the same transaction), which is more
  complex than a pessimistic lock taken up front for a resource (one row
  per store) with low contention (a handful of terminals, not thousands).
  The `version` column is retained on the table for potential future use
  (e.g., an administrative tool that reads series state without locking)
  but is not the concurrency-control mechanism for allocation.

## Consequences / Trade-offs
- **Positive:** correctness is guaranteed by PostgreSQL's MVCC + row
  locking, a well-understood, heavily-tested primitive — no custom
  distributed-locking code to get wrong.
- **Positive:** directly satisfies every Stage 2 requirement (no
  duplicates, no application-memory counter, no browser-assigned number,
  no reuse after successful allocation, rollback-safe).
- **Trade-off:** all terminals finalizing sales against the *same store*
  serialize on this one row for the (short) duration of the checkout
  transaction. At V1's expected scale (a handful of terminals in one
  store) this is not expected to be a bottleneck; if a future multi-store
  phase needs higher per-store terminal counts, revisit with real
  measurements rather than pre-optimizing now.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 5 (`invoice_series` table + migration), Stage 6
(`CheckoutService` implementation), Stage 8 (concurrent-allocation test:
two simultaneous requests must yield sequential, non-duplicate numbers).
