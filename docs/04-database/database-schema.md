# Database Schema — TindaFlow POS

## Status

DRAFT — Stage 5, awaiting owner review, hardened per the owner's three
2026-09-16 conditional-approval passes (see §6a for the cross-store/
cross-terminal fix, §6b for the Invoice structural-coherence and
Invoice/Sale terminal-identity follow-ups). Companion to
[constraint-register.md](constraint-register.md),
[index-strategy.md](index-strategy.md),
[migration-plan.md](migration-plan.md),
[context-integrity-matrix.md](context-integrity-matrix.md), and
[schema-validation.md](schema-validation.md). Translates the frozen
[domain-model.md](../02-domain/domain-model.md),
[invariants.md](../02-domain/invariants.md),
[state-machines.md](../02-domain/state-machines.md),
[erd.md](../03-architecture/erd.md), [architecture.md](../03-architecture/architecture.md),
the 12 ADRs, and [openapi.yaml](../05-api/openapi.yaml)/[api-design.md](../05-api/api-design.md)/[operation-inventory.md](../05-api/operation-inventory.md)
into a PostgreSQL 17 schema. Implemented as 37 Laravel migrations under
`database/migrations/` and validated live against PostgreSQL 17 (see
[schema-validation.md](schema-validation.md) Pass A). No frozen-corpus
contradiction was found; this document records design decisions Stage 5
was explicitly asked to make, not amendments to Stages 1–4.

## Scope discipline

Per the governing brief's FROZEN-CORPUS RULE, this stage produces
migrations, constraints, indexes, factories, seeders, and validation
tests only. No controllers, services, jobs, queue workers, React
components, or business-transaction orchestration exist yet — those are
Stage 6. Where a rule cannot be expressed as a static schema constraint
(most concurrency/aggregate invariants), this document and
[constraint-register.md](constraint-register.md) say so explicitly and
mark it a transactional or application-policy obligation for Stage 6,
rather than silently weakening the database guarantee or inventing
application code early.

---

## 1. Identifier strategy

**UUID is the public/domain identifier for every business entity**, per
erd.md's `uuid id PK` typing on every table. Two mechanisms are used:

- **`HasUuids`** (Laravel's `Illuminate\Database\Eloquent\Concerns\HasUuids`,
  application-generated, no PostgreSQL extension required) on every
  business-entity model. This satisfies Stage 5 instruction §66
  ("determine whether the application will generate UUIDs instead to
  reduce deployment dependencies") — the schema depends on no
  `uuid-ossp`/`pgcrypto` extension for ID generation, keeping backup/
  restore (ADR-008) and container image requirements minimal.
- **`HasUlids`** on exactly one table: `electronic_journal_entries`. A
  ULID is a valid UUID-format 128-bit value that additionally sorts
  lexicographically by creation time. This resolves an apparent tension
  between erd.md's `uuid id PK` typing and ADR-005's suggestion of "a
  bigserial or a ULID" for stable same-timestamp journal ordering,
  without contradicting either frozen document: the column is `uuid`
  end-to-end (erd.md is satisfied literally), and its values are
  chronologically sortable (ADR-005 is satisfied literally). No other
  table needed this property — journal ordering is the one place
  same-instant insertion order is operationally meaningful (e.g.,
  reconstructing an EJournal export in the exact sequence events were
  recorded).

**One disclosed exception: `idempotency_records.id` is a plain bigint.**
Justification: idempotency records are pure Stage 3/4 request-
deduplication infrastructure. No API resource in openapi.yaml exposes an
"idempotency record" as a returned entity, and no other table has a
foreign key into `idempotency_records`. A UUID PK would buy nothing here
and would cost an extra 12 bytes per row on what can be the
highest-write-volume table in the system (every one of the 14 mandated
idempotent operations inserts one row per attempt). This is the "justify
any dual-key design" case Stage 5 instruction §4 anticipates, applied to
exactly one internal-infrastructure table, not to any domain entity.

No database sequence ID is ever exposed as a public API identifier
anywhere in the schema.

---

## 2. Money, Quantity, and numeric types

| Value object | Column type | Columns | Rationale |
|---|---|---|---|
| **Money** (transactional) | `NUMERIC(12,2)` | Every per-row financial amount: `sale_item.*_amount`/`unit_price_snapshot`, `sale.*_amount`/`grand_total`, `payment.amount`, `refund.refund_total`, `refund_item.unit_refund_amount`, `refund_settlement.amount`, `cash_movement.amount`, `shift.opening_cash`/`expected_cash`/`declared_cash`/`variance`/`cash_sales`/`non_cash_sales`/`refunds_total`/`cash_in_total`/`cash_out_total`, `product.cost`/`selling_price`, `stock_movement.unit_cost` | Max `9999999999.99`, ample for any single transaction, line, shift, or product price in this domain |
| **Quantity** | `NUMERIC(10,3)` | `sale_item.quantity`, `stock_movement.quantity`, `stock_balance.quantity_on_hand`, `refund_item.quantity_returned` | Supports fractional units (e.g., 0.5 kg of rice) without floating-point; distinct type from Money everywhere (invariant #58) |
| **AccumulatedMoney** (cumulative fiscal totals) | *not a persisted relational column anywhere* | `z_reading.totals_snapshot` JSONB fields only | See §5 below — deliberately not materialized as a column |
| Invoice/series counters | `BIGINT` | `invoice_series.current_number`/`starting_number`/`ending_number` | Range far exceeds any realistic BIR-mandated invoice series length; matches erd.md's `bigint` typing |
| `z_reading.z_counter` | `INTEGER` | Per-terminal Z-Reading sequence | RMO 24-2023 Z Counter is a small monotonic per-terminal integer; bounded well under `INTEGER` range for any terminal's realistic lifetime |

No `FLOAT`/`REAL`/`DOUBLE PRECISION` column exists anywhere in this
schema. Every monetary and quantity column additionally carries a
non-negative (or, where the domain requires it, strictly-positive) CHECK
constraint — see [constraint-register.md](constraint-register.md).

`NUMERIC(18,2)` maximum verified: `9999999999999999.99` (16 integer
digits + 2 fractional = 18 total precision), matching Stage 4 pass 3's
corrected `AccumulatedMoney` ceiling exactly.

---

## 3. AccumulatedMoney: why it is not a stored column

Stage 4 defines `AccumulatedMoney` (cumulative running fiscal totals,
`NUMERIC(18,2)`-capable) as an API-response concept
(`ZReadingTotalsSnapshot`'s cumulative fields). domain-model.md never
proposes a separately-mutated running-total column for it — it is always
described as *computed from the ledger*, matching invariant #40
("readings are reproducible, never authoritative inputs" — no
calculation ever reads a prior reading's stored total as an input).

Stage 5 therefore does not create any standalone `accumulated_*` column.
The cumulative totals a Z-Reading reports live **only** inside
`z_reading.totals_snapshot` (JSONB), computed at generation time by
summing the ledger (`sale`/`refund`/`void`/`cash_movement`) across every
prior fiscal day for that terminal. This keeps the schema consistent
with invariant #40's "always fully recomputable" requirement — there is
no separate running counter that could ever drift out of sync with the
ledger, because none exists. Stage 6 is responsible for the
`NUMERIC(18,2)`-precision arithmetic when computing these values before
they are serialized into the snapshot; the schema's obligation ends at
providing a `jsonb` column capable of holding the result and a ledger
precise enough to recompute it.

---

## 4. Enum strategy: VARCHAR + CHECK, not native PostgreSQL ENUM

Every bounded-value column (`status`, `role`, `movement_type`, `method`,
`event_type`, `disposition`, etc.) is `VARCHAR` with an explicit
`CHECK (... IN (...))` constraint added via `DB::statement`, not a native
PostgreSQL `CREATE TYPE ... AS ENUM`.

**Rationale:**
- **Laravel migration ergonomics.** Laravel has no first-class
  cross-database enum migration helper for PostgreSQL native enums;
  modeling them means hand-rolling `CREATE TYPE`/`ALTER TYPE ADD VALUE`
  raw SQL at every migration site anyway, with no ORM-level benefit over
  a CHECK constraint.
- **Safer additive changes.** Postgres native enums cannot have a value
  removed or reordered without rebuilding the type, and (pre-PG12)
  `ALTER TYPE ... ADD VALUE` could not run inside a transaction with
  other DDL. VARCHAR+CHECK changes are a single `DROP CONSTRAINT` +
  `ADD CONSTRAINT` inside an ordinary transactional migration — safer to
  write, review, and roll back.
- **Rollback safety.** A `down()` for a VARCHAR+CHECK migration is a
  plain `DROP CONSTRAINT`; a native-enum rollback risks orphaning a type
  object if any column still references it, or failing outright if a
  value was ever removed.
- **No functional loss.** A CHECK constraint enforces the identical set
  of valid values with identical query performance (VARCHAR equality on
  a short fixed vocabulary), and Laravel's model-layer casts/validation
  provide the same PHP-level type safety a native enum would.

This decision applies uniformly; no status/enum-shaped column anywhere
in the schema is left as unconstrained `VARCHAR`.

---

## 5. Table class matrix (immutability strategy, Stage 5 instruction §44)

| Class | Tables | UPDATE? | DELETE? | Timestamps |
|---|---|---|---|---|
| **Configuration/reference (mutable under authorization)** | `stores`, `store_settings`, `users`, `terminals`, `categories`, `brands`, `products`, `product_barcodes`, `inventory_locations`, `invoice_series` | Yes, application-gated | No generic delete route; `products`/`terminals` use `active`/`status` deactivation, never physical delete | `created_at`+`updated_at` |
| **Effective-dated history (append via new row, never edit in place)** | `tax_registrations`, `fiscal_installations`, `fiscal_installation_accreditations`, `fiscal_installation_permits_to_use`, `terminal_fiscal_installations` | Only to close a period (`effective_to`), never to rewrite historical fields | No | `created_at` only (a "close" write is a real event; see §7's note on `updated_at`) |
| **Operational lifecycle (controlled mutation during a bounded lifecycle, immutable after)** | `shifts`, `fiscal_days`, `sales` (`status` only — see state-machines.md §1/§2's `COMPLETED→VOIDED`/`PARTIALLY_REFUNDED`/`REFUNDED` transitions), `voids`, `refunds`, `idempotency_records` | Yes, but only the specific lifecycle-transition columns (status, resolved/approved/closed timestamps, processing context) — never financial snapshot fields once written | No | `created_at`+`updated_at` (state changes over time; see §7) |
| **Financial finalized / append-only** | `sale_items`, `payments`, `invoices`, `refund_items`, `refund_settlements`, `cash_movements`, `x_readings`, `z_readings`, `stock_movements` | No application UPDATE path after creation | No | `created_at`/`occurred_at`/`recorded_at`/`issued_at` only — no `updated_at` |
| **Fiscal journal (append-only, regulatory)** | `electronic_journal_entries` | No | No | `occurred_at` only |
| **Audit (append-only)** | `audit_events` | No | No | `occurred_at` only |
| **Derived/cached projection** | `stock_balances` | Yes, but *only* as an atomic side-effect of a `stock_movements` insert in the same transaction — never independently | No (row lifetime = product/location lifetime) | `updated_at` only (no `created_at` — it is not itself an event) |

No table in the **Financial finalized**, **Fiscal journal**, or **Audit**
classes has a generic CRUD UPDATE/DELETE path, satisfying Stage 5
instruction §44 directly.

---

## 6. Deletion policy (Stage 5 instruction §45)

**Every foreign key was reviewed individually.** Result: **exactly one**
`ON DELETE CASCADE` in the entire schema, **exactly two** `ON DELETE SET
NULL`, **73 single-column foreign keys at `RESTRICT`**, and (added in
the 2026-09-16 owner hardening pass — see §6a and
[context-integrity-matrix.md](context-integrity-matrix.md)) **13
composite foreign keys at `NO ACTION`** (PostgreSQL's default when no
delete action is specified on a raw `ALTER TABLE ADD CONSTRAINT`;
functionally equivalent to `RESTRICT` for a non-deferrable constraint,
which none of these are). 89 foreign keys total.

| FK | Action | Justification |
|---|---|---|
| `product_barcodes.product_id → products.id` | **CASCADE** | `product_barcodes` is a pure alternate-identifier child row with no independent meaning once its parent product is gone, and products are never physically deleted in normal operation anyway (deactivated via `active=false` instead) — this CASCADE is a safety net for the rare hard-delete of a genuinely mis-entered product, not a everyday path. No financial or fiscal row references `product_barcodes`. |
| `products.category_id → categories.id` | **SET NULL** | A category can be safely deleted without deleting the products in it; `category_id` becomes nullable and the product remains fully intact (Stage 5 instruction §13: "active state not destructive deletion" for catalog entities). |
| `products.brand_id → brands.id` | **SET NULL** | Same reasoning as `category_id`. |
| **All other 72 foreign keys** | **RESTRICT** | Verified individually in the running schema (see [schema-validation.md](schema-validation.md)); every FK from a Sale/Invoice/Refund/Void/StockMovement/AuditEvent/ElectronicJournalEntry/XReading/ZReading/Shift/FiscalDay chain back to its parent is `RESTRICT`, and every parent reference *from* those tables (e.g., `sale_items.sale_id`, `refund_items.sale_item_id`, `voids.sale_id`) is also `RESTRICT`. Deleting a `store`, `terminal`, `user`, `product`, `sale`, `shift`, or `fiscal_day` that has any financial/fiscal/audit history is refused by PostgreSQL itself — there is no code path, intentional or accidental, that can cascade-erase that history through a foreign key. |

This directly answers the owner's flagged scrutiny area: Laravel's
`cascadeOnDelete()` was **not** reached for by default anywhere in this
schema. Every cascade or null-on-delete is a named, reviewed exception
with a one-line justification in the migration file itself, and none of
the three touches a financial, fiscal, or audit-bearing table.

---

## 6a. Cross-store/cross-terminal context integrity (owner hardening pass, 2026-09-16)

A single-column foreign key proves only that a referenced row *exists*
— never that it belongs to the same Store/Terminal execution context as
the row referencing it. The owner's review identified this precisely: a
`sale` carrying independent `store_id`/`terminal_id`/`shift_id`/
`fiscal_day_id` foreign keys could, before this pass, structurally cite
a shift or fiscal_day belonging to an entirely different store or
terminal, and every individual FK would still be satisfied.

**Fix: composite `UNIQUE` + composite `FOREIGN KEY` over column groups**
— PostgreSQL supports this natively provided the referenced side has a
matching unique constraint. No trigger was needed anywhere. 13 composite
foreign keys were added across `fiscal_days`, `shifts`,
`terminal_fiscal_installations`, `sales`, `voids`, `refunds`,
`x_readings`, and `z_readings`, plus supporting `UNIQUE(store_id, id)`/
`UNIQUE(terminal_id, id)` indexes on `terminals`, `fiscal_installations`,
`fiscal_days`, and `shifts`. `terminal_fiscal_installations` gained a
`store_id` column (a disclosed denormalized addition) specifically to
support its two composite FKs.

For nullable Void/Refund processing context, PostgreSQL's default
`MATCH SIMPLE` composite-FK semantics skip the check entirely when any
column in the pair is `NULL` — this composes cleanly with the existing
`voids_processing_context_check`/`refunds_processing_context_check`
CHECK constraints (which already guarantee all three processing columns
are simultaneously `NULL` or simultaneously populated): there is nothing
to validate before execution, and full mutual coherence is enforced the
instant execution context exists.

Full table-by-table review (which tables had a real gap, which were
reviewed and found sound, and what was deliberately deferred to avoid a
broader schema redesign) is in
[context-integrity-matrix.md](context-integrity-matrix.md). Live
negative-test proof that every named invalid combination — including
same-store-different-terminal, not just cross-store — is now rejected
is in `tests/Database/ContextIntegrityTest.php`, summarized in
[schema-validation.md](schema-validation.md).

---

## 6b. Invoice structural store coherence (owner follow-up pass, 2026-09-16)

§6a's original pass deliberately deferred `invoices`, since it wasn't
named in the owner's review list. The owner's follow-up review correctly
split Invoice's exposure into two separate invariants rather than
treating it as one effective-dating problem: **(A) structural store
coherence** (the Sale and InvoiceSeries an Invoice cites must belong to
the same Store — ordinary relational integrity) and **(B) temporal
eligibility** (whether that InvoiceSeries/FiscalInstallation was
actually valid *at the issuance instant* — genuinely transaction-time
domain logic that a composite FK cannot express).

**(A) was closed in two stages.** The first stage gave `invoices` a
`store_id` column plus three composite FKs proving `sale_id`,
`invoice_series_id`, and `terminal_id` each agreed with the invoice's
own declared store. A second owner review then caught that this still
permitted a structurally impossible state: a Store A invoice citing a
Store A sale that was actually finalized on a *different* Store A
terminal than the invoice itself declared — both terminals belong to
Store A, so no store-level check ever saw the two disagree. Since Sale
finalization and Invoice issuance are one atomic operation in the
frozen checkout architecture (ADR-003), this is a structurally
impossible state, not merely an unlikely one, and was corrected as a
DATABASE invariant rather than deferred.

**Final shape**: `invoices` retains `FOREIGN KEY (store_id,
invoice_series_id) REFERENCES invoice_series(store_id, id)` (an
independent invariant), and its two other original composite FKs —
`(store_id, sale_id) → sales(store_id, id)` and `(store_id,
terminal_id) → terminals(store_id, id)` — were **replaced**, not
supplemented, by one stronger FK: `FOREIGN KEY (store_id, terminal_id,
sale_id) REFERENCES sales(store_id, terminal_id, id)`. This proves
`invoices.terminal_id` is not merely in the same store as
`sales.terminal_id`, but *identical* to it — the two narrower FKs are
strictly subsumed (the sale-coherence guarantee directly, by
column-superset matching; the terminal-store guarantee transitively,
via `sales`' own pre-existing `sales_store_terminal_fk`), so keeping
all three would have meant carrying redundant constraints that prove
nothing extra. This required one new `UNIQUE(store_id, terminal_id,
id)` on `sales` (the prior pass's two-column uniques on `sales` don't
cover this three-column tuple) alongside the `UNIQUE(store_id, id)` on
`invoice_series` from the first follow-up. No `shift_id`/`fiscal_day_id`
was added to `invoices` — the correction concerns only `terminal_id`, a
field already on `invoices` per the frozen ERD.

**(B) remains TRANSACTION-level**, untouched by either follow-up pass —
see [constraint-register.md](constraint-register.md) DB-INV-063k.

Full detail: [context-integrity-matrix.md](context-integrity-matrix.md)'s
"Invoice structural coherence" section. Verified live by 8 tests in
`tests/Database/InvoiceContextIntegrityTest.php` (6 from the first
follow-up, 2 new for the terminal-identity fix), including a
reconfirmation that the pre-existing `UNIQUE(invoice_series_id,
invoice_number)` scope was not accidentally tightened or loosened by
either change.

---

## 7. `created_at`/`updated_at` discipline (Stage 5 instruction §46)

Laravel's `$table->timestamps()` convention was **not** applied
uniformly. Each table's timestamp columns were chosen deliberately:

- **Append-only tables** (`sale_items`, `payments`, `invoices`, `voids`
  request row itself aside from lifecycle columns, `refund_items`,
  `refund_settlements`, `cash_movements`, `x_readings`, `z_readings`,
  `stock_movements`, `audit_events`, `electronic_journal_entries`) carry
  a single creation timestamp (`created_at`, `occurred_at`,
  `recorded_at`, `processed_at`, or `issued_at`, named per the frozen
  ERD's own field name where one exists) and **no `updated_at`** — an
  immutable row was never updated, so a column implying otherwise would
  be actively misleading.
- **Lifecycle aggregates that genuinely transition state after creation**
  (`voids`, `refunds`, `shifts`, `fiscal_days`, `idempotency_records`,
  and `sales` itself) carry `updated_at`, because `REQUESTED →
  APPROVED/REJECTED → VOIDED` (and the Shift/FiscalDay open/close
  transitions, and `sale.status`'s own `COMPLETED → VOIDED`/
  `PARTIALLY_REFUNDED`/`REFUNDED` transitions per invariant #31 and
  state-machines.md §1/§2) are real, meaningful state changes over time
  that `updated_at` legitimately describes. Invariant #2's "immutability
  after completion" is scoped to `sales`' financial/snapshot fields —
  never `status` itself, which is the one column the frozen model
  explicitly transitions after the row exists.
- **Effective-dated history tables** (`tax_registrations`,
  `fiscal_installations` and its two child tables,
  `terminal_fiscal_installations`) carry only `created_at`. "Closing" a
  period means inserting a new current row and setting the *old* row's
  `effective_to`/`superseded_at` — this is the one deliberate, narrow
  exception where an already-inserted history row's `effective_to`
  column is written a second time (Stage 6 concern); it was judged
  clearer to model as a plain nullable date/timestamp column than to
  add `updated_at` to a class of table that is otherwise conceptually
  append-only.
- **Configuration/reference tables** (`stores`, `users`, `terminals`,
  `products`, etc.) keep ordinary `timestamps()` — they are genuinely,
  routinely edited under authorization.

---

## 8. FiscalInstallation / Accreditation / PTU persistence shape

Per ADR-009 (deferred to Stage 5) and erd.md's Stage 3 forward note
(RMC 72-2025: Accreditation and PTU are independent lifecycles), Stage 5
implements **option (A): effective-dated child tables**, not a single
flat row with `accreditation_number`/`accreditation_date`/`ptu_number`/
`ptu_date` columns:

- **`fiscal_installations`** — the parent row per installation
  (`store_id`, `deployment_model`, `machine_serial_number`,
  `software_version`, `min` — Machine Identification Number,
  `installed_at`, `superseded_at`). Store-scoped, not terminal-scoped,
  per erd.md's explicit note; a `SERVER_CONNECTED` deployment associates
  several terminals to the same installation via
  `terminal_fiscal_installations`, while a `STANDALONE` deployment
  expresses one terminal per installation as a single history row in
  the same join table — no schema difference between the two deployment
  models, satisfying Stage 5 instruction §11's "preserve
  STANDALONE/SERVER_CONNECTED support."
- **`fiscal_installation_accreditations`** — its own effective-dated
  history (`accreditation_number`, `accreditation_date`, `effective_from`,
  `effective_to`), one current row (`effective_to IS NULL`) per
  installation enforced by a partial unique index.
- **`fiscal_installation_permits_to_use`** — its own effective-dated
  history (`ptu_number`, `ptu_date`, `effective_from`, `effective_to`),
  same current-row constraint, entirely independent of the
  Accreditation table's row count or dates.

**Why child tables over two flat column-pairs on `fiscal_installations`
directly:** RMC 72-2025 establishes that a PTU does not expire merely
because its software's Accreditation expires — the two can change on
independent schedules for independent reasons (a supplier renewing
accreditation vs. a taxpayer's PTU status). Flat columns would force
"replace the accreditation, keep the PTU" or vice versa to be modeled as
an in-place UPDATE on the parent row, silently destroying the prior
accreditation's history the moment a new one is recorded. Child history
tables let each lifecycle accumulate its own append-only timeline
without ever touching the other's rows or the parent installation row,
which is the more conservative, audit-friendly shape and was the user's
own stated preference (invariants.md's general historical-snapshot
posture, extended here).

---

## 9. Terminal enrollment (ADR-011)

`terminals` never stores a raw credential — only `credential_hash`
(nullable until enrolled), `credential_issued_at`, and `revoked_at`.
`terminal_enrollment_tokens` implements the one-time, expiring,
non-recoverable token flow: `token_hash` (unique — a token is looked up
by its hash at redemption, never by a reversible lookup of the plaintext),
`created_by`, `expires_at`, `used_at` (nullable; set once, never reset).
The plaintext token itself is never persisted anywhere — it exists only
in the single API response that issues it (per openapi.yaml's
`TerminalEnrollmentToken` schema) and in whatever the enrolling terminal
retains client-side.

---

## 10. Inventory ledger and the polymorphic-attribution exception (Stage 5 instruction §15)

`stock_movements` is the sole authoritative ledger (invariant #44/#45).
Every row is append-only, attributed (`created_by`, `occurred_at`), and
positive-quantity with direction implied by `movement_type` (a CHECK
enforces `quantity > 0`; the signed application of that quantity to a
balance is Stage 6 logic, not a stored sign).

**Attribution uses `reference_type`/`reference_id` (a disclosed
polymorphic pair), not four separate nullable FK columns
(`sale_item_id`, `refund_item_id`, `stock_receipt_id`,
`stock_adjustment_id`).** This was a deliberate choice against Stage 5
instruction §15's preference for explicit FKs, made because:

- A stock movement's source is one of four structurally unrelated
  origins (a `sale_item`, a `refund_item`, a manual stock-receipt entry,
  or a manual adjustment entry — the latter two have no dedicated table
  of their own in this schema; they are represented directly by
  `movement_type` + `reason`), so four mutually-exclusive nullable FK
  columns would mean three are always `NULL` on every row.
  `reference_type`/`reference_id` avoids that sparsity without losing
  any queryable information — both columns are indexed together
  (`stock_movements_reference_idx`).
- This pair is used for **audit/traceability display only** — no
  invariant in invariants.md or constraint in this schema relies on
  `reference_id` for referential integrity the way a direct FK would
  (there is no "a stock movement must reference an existing sale_item"
  rule to enforce; a sale_item can be deleted only if nothing else
  referencing it survives, and stock_movements never cascades from that
  anyway per §6's RESTRICT-everywhere policy). The trade-off Stage 5
  instruction §15 warns about — "avoid generic polymorphism if it
  destroys referential integrity" — does not apply here because no
  referential-integrity guarantee was ever available to lose: the four
  source kinds don't share a table to reference uniformly.
- The alternative (four nullable FK columns with a CHECK ensuring
  exactly one is non-null) was considered and rejected: it adds
  complexity (a `CHECK` counting non-null columns) for a benefit
  (declarative FK validation) that matters only for two of the four
  source kinds (`sale_item`/`refund_item` — the other two have no table
  to reference), and Stage 6 already needs a validating write-path
  service to keep `stock_balances` consistent regardless.

---

## 11. Invoice numbering (Stage 5 instructions §20/§21/§64)

`invoice_series` is first-class: `store_id`, `series_code`, `prefix`,
`current_number`/`starting_number`/`ending_number` (all `BIGINT`),
`status` (`ACTIVE`/`CLOSED`), `version` (optimistic-lock column per
erd.md). The counter (`current_number`) and the formatted serial
(`invoices.invoice_number`) are distinct, separately-stored concepts —
the series never stores a pre-formatted string as its counter.

`invoices.invoice_number` is `VARCHAR`, digits-only
(`CHECK (invoice_number ~ '^[0-9]{6,}$')`, preserving leading zeroes,
matching Stage 4 pass 2's tightened pattern), with uniqueness scoped to
`UNIQUE(invoice_series_id, invoice_number)` — not globally unique and
not scoped to `store_id` alone, because two series belonging to the same
store are legitimate (see api-design.md) and must not collide with each
other's numbering, while the same numeric value in two different series
is not a duplicate.

`sales.transaction_number` is a **separate column with no relationship
to `invoice_number`'s uniqueness scope or six-digit pattern** (Stage 5
instruction §64) — it has its own column, is never validated against the
invoice digit pattern, and carries no uniqueness constraint at the
database level in this stage (transaction numbers are an internal
operational reference, not a BIR-mandated fiscal serial; if a uniqueness
scope is later required, that is a Stage 6/documentation addition, not
implied by this column's mere existence).

---

## 12. Invoice reprint (Stage 5 instruction §63)

`invoices` has **no `is_reprint` column and no mutable field of any
kind** — the table is written once, at issuance, and never touched
again. A reprint is represented exclusively as an `audit_event`
(`event_type = 'INVOICE_REPRINTED'`) and, where the frozen contract
requires a fiscal-journal representation, an `electronic_journal_entry`
row — never as a change to the `invoices` row itself. Rendering a
reprint reads `invoices.invoice_snapshot_json` (and its flat snapshot
columns) exclusively, per invariant #16.

---

## 13. Void/Refund persistence

Both `voids` and `refunds` persist the full request lifecycle
(`requested_by`/`requested_at`/`reason`/`status`/`approved_by`/
`resolved_at`) plus a **processing context** (`terminal_id`,
`fiscal_day_id`, `shift_id`) that is independent of the original sale's
own context (invariants #67/#68).

**The processing-context CHECK constraint enforces all-or-nothing
population, not merely non-null individually:**

```sql
-- voids
CHECK (
  (status = 'VOIDED' AND terminal_id IS NOT NULL AND fiscal_day_id IS NOT NULL AND shift_id IS NOT NULL)
  OR
  (status <> 'VOIDED' AND terminal_id IS NULL AND fiscal_day_id IS NULL AND shift_id IS NULL)
)

-- refunds (analogous, gated on status = 'COMPLETED')
```

This is a genuine database-level guarantee that a `REQUESTED` or
`REJECTED` row can never carry stray processing context, and that a
`VOIDED`/`COMPLETED` row can never be missing it — directly enforcing
invariant #67 ("processing context is always populated at execution,
never at request") at the schema layer, not merely by application
discipline. What the database **cannot** enforce declaratively — the
five-part Void eligibility gate (invariant #20), the cumulative
refund caps (invariants #27/#28), and the `refund_settlement` sum
reconciliation (invariant #70) — are named explicitly in
[constraint-register.md](constraint-register.md) as TRANSACTION-level
obligations for Stage 6, not silently left unenforced or faked with a
constraint PostgreSQL cannot actually check.

`refund_settlements` has no `terminal_id`/`shift_id`/`processed_by` of
its own — it inherits processing context from its parent `refund` row,
per erd.md's explicit note that all of a refund's settlement rows are
created atomically in the same completion transaction.

---

## 14. Idempotency persistence — in-flight vs. completed (Stage 5 instruction §39, owner's flagged scrutiny area #1)

`idempotency_records` (`id` bigint, `terminal_id`, `idempotency_key`
(uuid), `operation_type`, `request_hash` (char(64), SHA-256 hex),
`status`, `result_type`, `result_resource_id`, `created_at`,
`completed_at`) implements the distinction the owner specifically asked
for: **an in-flight reservation is structurally different from a
completed result, and a crash or a failed attempt never permanently
consumes the key.**

**Design: the reservation row is inserted as the first statement of the
same database transaction as the business mutation it protects — never
in a separate, earlier transaction.**

| Scenario | What happens |
|---|---|
| Request begins | `INSERT ... status='IN_PROGRESS'` as step 1 of the operation's own transaction |
| Server crashes before commit | The entire transaction — including the `INSERT` — is never committed; PostgreSQL's crash recovery discards it completely. The `(terminal_id, idempotency_key)` pair is exactly as if the request had never happened. |
| Operation succeeds | The same transaction `UPDATE`s the row to `status='COMPLETED'` with `result_type`/`result_resource_id`/`completed_at`, atomically with the business mutation, then commits. |
| Operation fails (business-rule rejection, no authoritative mutation) | The entire transaction rolls back, `INSERT` included. This delivers Stage 4 pass 5's rule — "failed approval is not automatic rejection" / a transient failure doesn't consume the key — for free, via ordinary transaction atomicity. No cleanup step, no separate "release the reservation" call, and no window where a crashed process leaves a permanently-stuck `IN_PROGRESS` row (a row only exists at all once its transaction has *committed*, at which point it is by definition `COMPLETED`). |
| Response lost after commit | The row is `COMPLETED` and durable; a retry with the same key+hash finds it and returns the stored result (ADR-010's replay behavior). |
| Retry, same key+hash, after a failed attempt | No row exists (the prior attempt's `INSERT` was rolled back) — the retry proceeds as a genuinely fresh attempt, and its `INSERT` succeeds normally. |
| Retry, same key, different hash | Conflict only if a `COMPLETED` row already exists for that key (`IDEMPOTENCY_KEY_REUSED`, per ADR-010). If no row exists (prior attempt failed), this is simply a fresh use of the key — not a conflict. |
| Concurrent duplicate submissions (double-click) | Caught by PostgreSQL's own cross-transaction unique-index insert blocking on `UNIQUE(terminal_id, idempotency_key)` — two simultaneous `INSERT`s for the same pair can never both succeed, matching architecture.md §24's existing Checkout description, generalized here to all 14 operations. |

The schema encodes only two states (`IN_PROGRESS`/`COMPLETED`) rather
than a richer state machine, because **the transaction boundary itself
is what does the work** — there is no schema-visible "FAILED" state to
model, since a failed attempt leaves no row at all. The
`idempotency_records_completed_check` CHECK
(`(status='COMPLETED') = (completed_at IS NOT NULL AND result_resource_id IS NOT NULL)`)
is a structural guarantee that a `COMPLETED` row is never missing its
result pointer and a non-`COMPLETED` (i.e., mid-transaction, pre-commit
— never actually observable outside its own transaction) row never has
one.

**Retention:** no automatic deletion is implemented (Stage 5 instruction
§41). A future pruning policy (e.g., archive `idempotency_records` older
than N days once retried requests older than the client's own retry
window are moot) is an explicit future operational decision, not
silently implemented here.

---

## 15. Security posture (Stage 5 instruction §65)

`database/scripts/harden_append_only_privileges.sql` (not wired into
`php artisan migrate` — an explicit, reviewed, separately-applied
operational script) defines a two-role model:

- **`tindaflow_migrator`** — owns all tables, runs migrations, is the
  only role permitted `ALTER TABLE`/`CREATE INDEX`/DDL generally. Not
  the role the running application connects as.
- **`tindaflow_app`** — the role Laravel's runtime connection uses.
  Granted ordinary `SELECT`/`INSERT`/`UPDATE`/`DELETE` on
  configuration/operational tables, but **`UPDATE`/`DELETE` are
  explicitly `REVOKE`d on `sale_items`, `payments`, `invoices`,
  `refund_items`, `refund_settlements`, `cash_movements`, `x_readings`,
  `z_readings`, `stock_movements`, `audit_events`,
  `electronic_journal_entries`** — the append-only/financial-finalized/
  fiscal-journal/audit classes from §5's table matrix. Narrow
  column-level re-grants exist on `voids`/`refunds` for exactly their
  lifecycle-transition columns (`status`, `approved_by`, `resolved_at`,
  `terminal_id`/`fiscal_day_id`/`shift_id`, `refunded_at`), so the
  application can legitimately transition a request through its
  lifecycle without regaining the ability to rewrite a financial
  snapshot field.

Neither role is `SUPERUSER`. Cashier workstations never connect
directly to PostgreSQL — only the Laravel application server does, per
architecture.md's network topology. No secret (database password,
credential hash key material) is seeded into the database itself; all
such values remain environment/runtime configuration, per Stage 5
instruction §65.

---

## 16. Backup compatibility (Stage 5 instruction §66)

No PostgreSQL extension is required anywhere in this schema (see §1 —
UUIDs are application-generated). `pg_dump`/`pg_restore` (ADR-008's
chosen backup mechanism) requires no special handling: every object is
an ordinary table, index, or constraint owned by `tindaflow_migrator`,
with no extension-provided type or function to separately export.

---

## 17. Time types

Every authoritative event timestamp (`sold_at`, `occurred_at`,
`generated_at`, `requested_at`, `resolved_at`, `issued_at`,
`processed_at`, `created_at`, etc.) is `TIMESTAMPTZ`. `business_date`
(`fiscal_days`, `z_readings`) is `DATE`, matching erd.md's explicit
"label only, not a query filter for attribution" note — it is never
used to derive fiscal-day membership (invariant #9). No
`TIMESTAMP WITHOUT TIME ZONE` column exists in the schema. This matches
the API's RFC 3339 contract (every timestamp the API returns carries an
explicit offset, sourced directly from a `TIMESTAMPTZ` column with no
conversion ambiguity).

---

## 18. JSONB usage

JSONB is used in exactly three places, all deliberately chosen and none
serving as the primary reporting source:

1. **`invoices.invoice_snapshot_json`** — the complete immutable
   seller/buyer/item/tax/payment/fiscal snapshot at issuance (invariant
   #16). The authoritative *relational* record for the same data lives
   in `sale_items`/`payments`/flat snapshot columns on `invoices`
   itself — the JSONB blob is a convenience for reprint rendering and
   API response shaping, not a second source of truth queried by
   reports.
2. **`x_readings.totals_snapshot` / `z_readings.totals_snapshot`** —
   structured cash/sales/VAT/void/refund totals as of generation time.
   Chosen because the reading's total *shape* varies slightly between
   X and Z (and could evolve without a migration), while every
   individual figure inside it is, per invariant #40, always
   independently recomputable from the relational ledger — the JSONB
   is a cache of a computation, not new information.
3. **`audit_events.before_metadata`/`after_metadata`** — supplemental,
   free-form context for an audit entry. The core searchable fields
   (`event_type`, `actor_user_id`, `terminal_id`, `entity_type`,
   `entity_id`, `occurred_at`, `request_id`) are all ordinary indexed
   relational columns; JSONB here is genuinely supplemental, never load-
   bearing for a query.

No report identified in [csv-export-contract.md](../05-api/csv-export-contract.md)
or the 15-report set (§49 below) requires scanning JSON — see
[index-strategy.md](index-strategy.md)'s reporting-index review.

---

## 19. Reference

- Tables: 34 domain tables + 7 Laravel framework tables (`cache`,
  `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
  `migrations`) = 41 total, confirmed live in PostgreSQL 17.
- Migrations: 37 files (35 domain + `cache`/`jobs` framework tables +
  1 cross-cutting reporting/FK index migration) — unchanged in count
  through all three 2026-09-16 owner hardening passes, which edited
  existing migrations in place rather than adding new ones, since
  nothing was yet frozen or tagged.
- CHECK constraints: 59, confirmed live (corrected 2026-09-16 from an
  earlier miscount of 57 — no constraint was added or removed by any
  hardening pass).
- Partial unique indexes: 13, confirmed live, unchanged by every pass.
- Composite unique constraints (added across all three 2026-09-16
  passes, closing DB-INV-063): 7 — `terminals(store_id, id)`,
  `fiscal_installations(store_id, id)`, `fiscal_days(terminal_id, id)`,
  `shifts(terminal_id, id)`, `sales(store_id, id)`,
  `invoice_series(store_id, id)` (Invoice follow-up pass, §6b), and
  `sales(store_id, terminal_id, id)` (Invoice/Sale terminal-identity
  fix, §6b).
- Foreign keys: 92, confirmed live — 1 CASCADE, 2 SET NULL, 74
  single-column RESTRICT, 15 composite NO ACTION (functionally
  equivalent to RESTRICT — see §6a/§6b and
  [context-integrity-matrix.md](context-integrity-matrix.md); the
  terminal-identity fix replaced two composite FKs on `invoices` with
  one stronger one, a net FK count decrease from the intermediate 93).

See [constraint-register.md](constraint-register.md) for the full
invariant-by-invariant enforcement matrix,
[index-strategy.md](index-strategy.md) for the index rationale,
[migration-plan.md](migration-plan.md) for the dependency-ordered
migration DAG and rollback discussion, and
[schema-validation.md](schema-validation.md) for the four-pass
validation record and live PostgreSQL execution results.
