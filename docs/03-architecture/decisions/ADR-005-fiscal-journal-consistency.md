# ADR-005: Electronic Journal Written Synchronously, In-Process, No Message Broker

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2 defined `electronic_journal_entry` as a projection of authoritative
domain events, requiring exactly one entry per fiscally-journalable event,
written in the same database transaction as the event (invariant #49).
Stage 3 must confirm the mechanism that guarantees this and explicitly rule
out eventual-consistency approaches that would violate it.

## Decision
Every fiscally-journalable operation (sale finalization, void completion,
refund completion, X-Reading generation, Z-Reading generation, shift
open/close, cash in/out, stock adjustment) writes its
`electronic_journal_entry` row via a direct, synchronous, in-process call
from the operation's own database transaction — the same transaction that
writes the `sale`/`void`/`refund`/`x_reading`/`z_reading`/etc. row. No
queue, no message broker, no "outbox pattern with a relay worker," and no
asynchronous event bus sits between the domain event and the journal write
for V1.

Internally, the codebase may use Laravel's in-process model events
(`SaleFinalized`, `VoidCompleted`, etc.) purely as an **organizational
pattern** to decouple "the checkout service doesn't need to know about
journal-formatting logic" — but any listener that writes a journal entry
must be registered to run **synchronously, within the same transaction**
(Laravel's default in-process event dispatch, not a queued listener). This
is an important distinction to keep explicit: an in-process observer
pattern and a distributed/eventual messaging system are not the same
thing, even though both are sometimes casually called "events" — TindaFlow
uses only the former for anything fiscally consequential.

`source_type`/`source_id`/`event_type` uniqueness (Stage 2 invariant #49)
is enforced by a database unique constraint on
`electronic_journal_entry(source_type, source_id, event_type)`, not merely
application-level care — a duplicate write attempt fails loudly rather than
silently creating a second entry.

**Ordering:** entries are ordered by `occurred_at` (set from the same
transaction's timestamp) with `id` (a monotonically-allocated identifier,
e.g. a `bigserial` or a ULID) as a stable tie-breaker for entries with
identical timestamps — sufficient for chronological journal export without
requiring a separate sequence-number concept.

**Retention:** append-only, no automated deletion of any age (Stage 2
invariant #51, informed by [BIR-009](../../01-research/bir-reference-register.md)'s
5-year statutory floor) — retention/backup is an infrastructure concern
(ADR-008), not a reason to ever truncate this table from application code.

## Alternatives Considered
- **Message broker (RabbitMQ/Kafka) with an async consumer writing the
  journal** — rejected per the governing brief's explicit instruction not
  to introduce a broker for V1, and because it would make journal writes
  eventually consistent with the event that caused them: a Z-Reading
  generated microseconds after a sale, before that sale's journal entry
  had been consumed off the queue, would produce an incomplete Z-Reading —
  exactly the fiscal-integrity risk Stage 2's "same transaction" invariant
  exists to prevent.
- **Outbox pattern (write to an `outbox` table in the same transaction,
  relay to somewhere else later)** — unnecessary here specifically because
  the "somewhere else" *is* the same PostgreSQL database. The outbox
  pattern exists to bridge a database transaction to an external system
  (a broker, another service's database) transactionally; TindaFlow has no
  such external system in the fiscal-journal path for V1, so the pattern
  solves a problem that doesn't exist yet.
- **Database trigger-based journal population** (a Postgres trigger on
  `sale`/`void`/etc. inserts the journal row automatically) — considered
  and rejected as the primary mechanism: triggers would duplicate the
  `source_type`/`payload_json` construction logic in SQL/PL/pgSQL separate
  from the application's Money/Tax calculator (ADR/§11), risking drift
  between the two. Application-layer synchronous writes, protected by the
  database uniqueness constraint as a safety net, keep the formatting
  logic in one place (PHP) while still getting a hard guarantee against
  duplicates or omissions at the database level.

## Consequences / Trade-offs
- **Positive:** journal consistency is guaranteed by the same transaction
  boundary as the domain event itself — there is no window where a sale
  exists without its journal entry, or vice versa.
- **Positive:** no additional infrastructure (broker, relay worker) to
  deploy, monitor, or fail — consistent with the brief's "V1 should remain
  operationally simple" instruction.
- **Trade-off:** journal writes are on the critical path of every fiscal
  operation's transaction (a few extra milliseconds of write work per
  operation) rather than deferred — an acceptable, small cost given the
  correctness guarantee it buys, and consistent with the performance
  targets in architecture.md §21.
- **Trade-off:** if TindaFlow ever needs to *export* to an external
  fiscal-transmission system (the `SalesTransmissionProvider` abstraction
  from the Stage 1 brief, for a future BIR EIS integration), that export
  reads from the already-written `electronic_journal_entry` table
  asynchronously/on a schedule — it does not change how the entry gets
  written in the first place. This keeps the future EIS integration
  additive rather than a rework of this ADR.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 5 (`electronic_journal_entry` unique constraint),
Stage 6 (in-process event listeners), Stage 8 (test: forcing a listener
failure must roll back the whole transaction, not leave a domain row
without its journal entry).
