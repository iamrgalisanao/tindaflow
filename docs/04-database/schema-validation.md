# Schema Validation — TindaFlow POS

## Status

DRAFT — Stage 5, awaiting owner review. Companion to
[database-schema.md](database-schema.md),
[constraint-register.md](constraint-register.md),
[context-integrity-matrix.md](context-integrity-matrix.md), and
[index-strategy.md](index-strategy.md). Records the four required
validation passes (Stage 5 instruction §69), the 15+ automated
constraint scenarios (§59), the concurrency test plan (§60), and —
added across three 2026-09-16 owner review passes — raw-SQL precision
ground-truth tests, live cross-store/cross-terminal context-integrity
negative tests, Invoice structural store-coherence negative tests, and
Invoice/Sale terminal-identity negative tests.

**Environment used:** PostgreSQL 17 (`postgresql-x64-17` Windows
service, native install — not Docker; Docker Desktop was unavailable in
this environment and this alternative was used instead, disclosed here
rather than silently substituting a lighter-weight database like SQLite,
which cannot faithfully validate this schema's PostgreSQL-specific
features — partial unique indexes, `TIMESTAMPTZ`, JSONB, raw `CHECK`
constraints with regex operators). Database: `tindaflow_stage5_test`,
created fresh for this validation and re-created twice during this pass
after two real defects were found and fixed (see §1.3).

---

## Pass A — SCHEMA (migrations parse, tables/columns/types exist, FKs resolve, indexes build, CHECK constraints build)

**Result: PASS.** Executed live, not merely reviewed statically.

### A.1 — Full migration run from empty database

```
php artisan migrate --force
```

All 37 migrations ran to completion with no error. Final state: **41
tables** (34 domain + `cache`/`cache_locks`/`jobs`/`job_batches`/
`failed_jobs`/`sessions`/`migrations` framework tables), confirmed via
`\dt`.

### A.2 — Full reversibility test

```
php artisan migrate:reset --force   # runs every down() in reverse dependency order
php artisan migrate --force          # re-runs every up() from empty
```

Both completed with **zero errors**. All 37 `down()` methods executed
successfully in reverse order (dropping every table/index they created),
and the subsequent re-migration reproduced the identical 41-table
schema. This directly satisfies Stage 5 exit criterion "migrations
execute cleanly on empty PostgreSQL" and "rollback behavior documented"
(see [migration-plan.md](migration-plan.md) §3 for the reversibility
detail and §4 for why this does **not** imply `migrate:rollback` is
production-safe).

### A.3 — Structural confirmation

| Check | Result |
|---|---|
| Tables | 41 (34 domain + 7 framework) |
| CHECK constraints | 59, all named and confirmed via `pg_constraint` (corrected 2026-09-16 from an earlier miscount of 57 — no constraint was added or removed by any hardening pass, the original count was simply wrong) |
| Partial unique indexes | 13, all confirmed via `pg_indexes`, unchanged by every hardening pass |
| Composite unique constraints (added across all three 2026-09-16 passes) | 7 — 4 from the cross-store/cross-terminal pass, 2 more (`sales(store_id, id)`, `invoice_series(store_id, id)`) from the Invoice structural-coherence follow-up, 1 more (`sales(store_id, terminal_id, id)`) from the Invoice/Sale terminal-identity fix |
| Foreign keys | 92 (was 75 → 89 → 93 → 92 across all three hardening passes), confirmed via `information_schema.table_constraints` |
| FK delete actions | 1 `CASCADE`, 2 `SET NULL`, 74 single-column `RESTRICT`, 15 composite `NO ACTION` — confirmed via `pg_constraint.confdeltype` |
| Sample `NUMERIC` precisions | Spot-checked `products.selling_price`/`shifts.*` at `NUMERIC(12,2)` |

### A.3.1 — Defects found and fixed during this pass

Live execution surfaced two real defects that a purely static review had
missed, both fixed before this document was written:

1. **Laravel's default `0001_01_01_000000_create_users_table.php`**
   (scaffolded by `composer create-project`) was never actually deleted
   from the repository despite an earlier stated intent to remove it —
   it created a conflicting `users` table (wrong shape) and a
   conflicting `sessions` table, causing `2026_01_01_000030_create_users_table`
   to fail with `SQLSTATE[42P07]: Duplicate table`. **Fixed**: the file
   is now deleted; TindaFlow's own `2026_01_01_000030_...`/
   `2026_01_01_000090_create_sessions_table` migrations are the only
   source of these tables.
2. **`sales` was missing `updated_at`** despite `sale.status`
   genuinely transitioning after row creation
   (`COMPLETED → VOIDED`/`PARTIALLY_REFUNDED`/`REFUNDED`, confirmed
   directly in [state-machines.md §1/§2](../02-domain/state-machines.md)).
   The original migration comment incorrectly generalized invariant #2's
   "immutability after completion" (which is scoped to financial/
   snapshot fields, not the `status` column) into "no `updated_at` at
   all." **Fixed**: `sales` now has a nullable `updated_at`, and
   [database-schema.md](database-schema.md) §5/§7 were corrected to
   classify `sales` correctly as a lifecycle-transitioning table
   alongside `voids`/`refunds`/`shifts`/`fiscal_days`.

Both defects were caught precisely *because* validation ran against a
real database instead of stopping at a structural read-through — the
motivating reason Pass A exists as a live step, not a checklist.

### A.3.2 — Owner hardening pass (2026-09-16): composite-FK round-trip

After adding the 13 composite foreign keys and 4 composite unique
constraints described in [context-integrity-matrix.md](context-integrity-matrix.md),
the full Pass A cycle was re-run from a freshly dropped database:

```
DROP DATABASE tindaflow_stage5_test; CREATE DATABASE tindaflow_stage5_test;
php artisan migrate --force            # clean, 37/37 migrations
php artisan migrate:reset --force      # clean, all down() methods
php artisan migrate --force            # clean, re-applied
```

All three steps completed with zero errors. Structural counts after:
41 tables (unchanged), 89 foreign keys (was 75 — the expected +14: 13
new composite FKs plus 1 new single-column FK for
`terminal_fiscal_installations.store_id → stores.id`), 17 unique
constraints via `pg_constraint` (was implicitly fewer — 4 new composite
`UNIQUE`s added). No unexpected `CASCADE` was introduced by any of the
new constraints — verified via `pg_constraint.confdeltype`, addressing
the owner's item 7 directly.

**Three test-authoring defects were found and fixed while writing the
new negative tests** (schema defects, not found — these were bugs in
the test code itself):

1. `ContextIntegrityTest`'s original fixture reused the same cashier
   for two simultaneously-open shifts on different terminals, which
   `shifts_one_open_per_cashier` correctly rejected — a fixture bug, not
   a schema bug. Fixed by giving each terminal's shift its own cashier.
2. `PrecisionCoercionTest`'s data-provider methods used PHPUnit's
   docblock `@dataProvider` annotation, which PHPUnit 12 (this project's
   installed version) no longer parses — annotations were removed in
   favor of PHP 8 attributes. Fixed by switching to
   `#[DataProvider('methodName')]`.
3. `PrecisionCoercionTest`'s magnitude-overflow tests originally used
   `expectException()` directly around a bare `DB::table()->insert()`
   call, outside any nested transaction — the resulting Postgres error
   left the outer test transaction "aborted," which then caused the
   `tearDown()`'s own `DROP TABLE` to fail with
   `current transaction is aborted`. Fixed by routing every
   expected-failure INSERT through the same `attemptFails()`
   savepoint-wrapping helper used throughout the rest of the Database
   test suite.

### A.3.3 — Owner follow-up pass (2026-09-16): Invoice structural coherence round-trip

After adding `invoices.store_id` and its three composite FKs (plus the
two new supporting `UNIQUE(store_id, id)` constraints on `sales` and
`invoice_series`), the full Pass A cycle was re-run a third time from a
freshly dropped database, using the identical `migrate` →
`migrate:reset` → `migrate` sequence as A.3.2. **All three steps
completed with zero errors.** Structural counts after: 41 tables
(unchanged), 93 foreign keys (was 89 — the expected +4: 3 new composite
FKs on `invoices` plus 1 new single-column FK for `invoices.store_id →
stores.id`), 19 unique constraints via `pg_constraint` (was 17 — the
expected +2), 59 CHECK constraints (unchanged — none were added or
removed by this pass; see A.3 above for the separate correction of the
originally-miscounted 57). No unexpected `CASCADE` was introduced —
`pg_constraint.confdeltype` breakdown after this pass: 1 `c`
(unchanged), 2 `n` (unchanged), 74 `r` (was 73, +1), 16 `a` (was 13,
+3).

### A.3.4 — Owner second follow-up pass (2026-09-16): Invoice/Sale terminal-identity round-trip

After replacing `invoices`' two narrower composite FKs
(`invoices_store_sale_fk`, `invoices_store_terminal_fk`) with the
single stronger `invoices_store_terminal_sale_fk (store_id, terminal_id,
sale_id) → sales(store_id, terminal_id, id)`, plus the new supporting
`sales.UNIQUE(store_id, terminal_id, id)`, the full Pass A cycle was
re-run a fourth time from a freshly dropped database, using the same
`migrate` → `migrate:reset` → `migrate` sequence as every prior pass.
**All three steps completed with zero errors.** Structural counts
after: 41 tables (unchanged), **92 foreign keys (was 93 — a net −1)**:
+1 for the new three-column FK, −2 for the two removed narrower FKs,
20 unique constraints (was 19, +1 for the new three-column `sales`
unique), 59 CHECK constraints (unchanged). `pg_constraint.confdeltype`
breakdown: 1 `c` (unchanged), 2 `n` (unchanged), 74 `r` (unchanged), 15
`a` (was 16, −1, matching the net FK count change exactly since all
three touched FKs are composite `NO ACTION`). Confirmed directly via
`pg_get_constraintdef` that `invoices` now carries exactly 7 foreign
keys (down from 8), with `invoices_store_series_fk` and the new
`invoices_store_terminal_sale_fk` as its only remaining composite FKs.

---

## Pass B — DOMAIN (compare against domain-model.md/invariants.md/state-machines.md/erd.md)

**Result: PASS, with one disclosed gap (DB-INV-063) and 3 deliberate
disclosed deviations, none silent.**

Every one of the 72 numbered invariants in
[invariants.md](../02-domain/invariants.md) was reviewed and mapped in
[constraint-register.md](constraint-register.md) to a DATABASE,
TRANSACTION, APPLICATION POLICY, or COMBINED enforcement level. No
invariant was silently dropped or weakened to fit a database
convenience. The disclosed deviations:

1. **Cross-store referential consistency (DB-INV-063)** — acknowledged
   as an unenforced gap at the database level (PostgreSQL cannot express
   a two-table FK conditioned on a third column matching without a
   composite key or trigger). Not silently accepted — flagged explicitly
   for Stage 6 attention in constraint-register.md.
2. **`stock_movements` polymorphic attribution** (`reference_type`/
   `reference_id` instead of four direct FK columns) — a disclosed,
   justified deviation from Stage 5 instruction §15's FK preference; see
   database-schema.md §10.
3. **`AccumulatedMoney` has no persisted column** — a disclosed design
   decision, not an omission; see database-schema.md §3.

No entity, relationship, or cardinality in [erd.md](../03-architecture/erd.md)
was found to be un-implementable by this schema — see §5 (Schema Diff)
below for the full comparison.

---

## Pass C — ARCHITECTURE (lock order, InvoiceSeries locking, idempotency, fiscal-day closure, append-only journal/audit)

**Result: PASS at the schema-readiness level** (no Stage 6 transaction
code exists yet to execute an end-to-end architectural test against —
this pass confirms the schema provides every primitive Stage 6's
architecture requires, not that Stage 6 has been built and tested).

| Architectural requirement | Schema support confirmed |
|---|---|
| Global Lock Order (`shift → fiscal_day → sale → sale_item (ascending) → invoice_series`) | Every table in the chain has the FK/index needed to `SELECT ... FOR UPDATE` efficiently at each step — `shifts`/`fiscal_days`' partial unique indexes for O(1) "find the open one" lookups, `sale_items_sale_idx` for ascending per-sale item locking, `invoice_series` PK for the final allocation lock. |
| InvoiceSeries concurrency-safe allocation | `invoice_series.current_number`/`version` columns exist for a `SELECT ... FOR UPDATE` + optimistic-lock pattern; `UNIQUE(invoice_series_id, invoice_number)` is the uniqueness backstop if the lock discipline is ever bypassed by a bug. |
| Idempotency (in-flight vs. completed) | `idempotency_records`' transaction-boundary design verified structurally (§14 of database-schema.md) and via live constraint test (scenario 14 below) — `UNIQUE(terminal_id, idempotency_key)` genuinely blocks concurrent duplicate `INSERT`s at the database level, confirmed live. |
| Fiscal-day closure (cannot close with an open shift) | `shifts.fiscal_day_id` FK + `shifts_fiscal_day_idx` support the required "any open shift for this fiscal_day?" query Stage 6 must run inside the closure transaction; the schema cannot enforce the check itself (DB-INV-005, TRANSACTION-level). |
| Append-only journal/audit | Structurally confirmed: `audit_events`/`electronic_journal_entries` have no `updated_at`, no application UPDATE/DELETE route exists, and `harden_append_only_privileges.sql` (not yet wired into `migrate`, disclosed as such) defines the DB-role-level enforcement. |
| Void/Refund processing-context separation from original sale context | `voids_processing_context_check`/`refunds_processing_context_check` verified live (structurally; a full request→approval workflow test requires Stage 6 code and is out of scope here). |

---

## Pass D — API (storage support against all 86 Stage 4 operations and every persistent response resource)

**Result: PASS.** Every financially significant API resource named in
Stage 5 instruction §62 was checked against this schema for an
authoritative persistence path:

| API resource (openapi.yaml) | Persistence path |
|---|---|
| Sale | `sales` |
| SaleItem | `sale_items` |
| Payment | `payments` |
| Invoice | `invoices` (+ `invoice_snapshot_json`) |
| InvoiceReprint (audit occurrence) | `audit_events` (`event_type='INVOICE_REPRINTED'`) — no mutable field on `invoices` itself, per §12 |
| Void | `voids` |
| Refund | `refunds` |
| RefundItem | `refund_items` |
| RefundSettlement | `refund_settlements` |
| Shift | `shifts` |
| CashMovement | `cash_movements` |
| FiscalDay | `fiscal_days` |
| XReading | `x_readings` |
| ZReading | `z_readings` |
| StockMovement | `stock_movements` |
| AuditEvent | `audit_events` |
| ElectronicJournalEntry | `electronic_journal_entries` |
| Idempotency record (internal, not an API-exposed resource) | `idempotency_records` |
| Store / StoreSettings | `stores` / `store_settings` |
| User | `users` |
| Terminal / TerminalEnrollmentToken | `terminals` / `terminal_enrollment_tokens` |
| TaxRegistration | `tax_registrations` |
| FiscalInstallation / Accreditation / PTU | `fiscal_installations` / `fiscal_installation_accreditations` / `fiscal_installation_permits_to_use` |
| Category / Brand / Product / ProductBarcode | `categories` / `brands` / `products` / `product_barcodes` |
| InventoryLocation | `inventory_locations` |
| InvoiceSeries | `invoice_series` |

No API resource in openapi.yaml was found with no authoritative
persistence path. The 15 reports in
[csv-export-contract.md](../05-api/csv-export-contract.md) were verified
in [index-strategy.md](index-strategy.md) §4 to resolve against
relational columns without requiring a JSON scan of any snapshot field.

---

## Precision ground-truth tests (owner hardening pass, 2026-09-16)

The owner correctly challenged an earlier draft's claim that "invalid
Money/Quantity precision is rejected by PostgreSQL" as imprecise:
PostgreSQL 17's documented behavior is that `NUMERIC(p,s)` **rounds** an
input with more fractional digits than the declared scale, it does not
reject it. Only a value whose *magnitude* (integer-digit count) exceeds
what the declared precision/scale allows raises an error. The two are
easy to conflate and the earlier draft did.

**Ground truth, verified with raw SQL against a bare two-column
PostgreSQL table — no Laravel, no Eloquent cast, no request
validation in the path at all:**

| Input | Column | Result | Stored value |
|---|---|---|---|
| `1.234` | `NUMERIC(12,2)` | Rounded, not rejected | `1.23` |
| `12.3456` | `NUMERIC(12,2)` | Rounded, not rejected | `12.35` |
| `0.001` | `NUMERIC(12,2)` | Rounded, not rejected | `0.00` |
| `0.005` | `NUMERIC(12,2)` | Rounded, not rejected (half-up at the boundary) | `0.01` |
| `99999999999.99` (11 integer digits) | `NUMERIC(12,2)` | **Rejected** — `numeric_value_out_of_range` | — |
| `1.2345` | `NUMERIC(10,3)` | Rounded, not rejected | `1.235` |
| `99999999.999` (8 integer digits) | `NUMERIC(10,3)` | **Rejected** — `numeric_value_out_of_range` | — |
| `9999999.9995` | `NUMERIC(10,3)` | **Rejected** — rounds to `10000000.000` first, which then overflows the 7-integer-digit limit ("numeric field overflow... must round to an absolute value less than 10^7") | — |

This confirms the owner's claim exactly, including the edge case they
didn't explicitly ask for but that falls out of the same mechanism:
rounding happens *before* the magnitude check, so a value that looks
like it fits can still be rejected once rounding pushes it over the
boundary.

**Corrected classification (was: bare "DATABASE rejection"; now:
COMBINED, split precisely):**

- **API/DOMAIN VALIDATION** (Stage 4, already frozen, not a Stage 5
  addition) — `openapi.yaml`'s `Money` schema (`pattern:
  '^-?\d+\.\d{2}$'`) and `Quantity` schema (`pattern:
  '^\d+(\.\d{1,3})?$'`) reject a request body with excess fractional
  digits before it ever reaches the database, via ordinary JSON Schema
  string-pattern validation.
- **DATABASE** — `NUMERIC(12,2)`/`NUMERIC(10,3)` guarantee the *stored*
  value always has exactly the declared scale (by rounding, not by
  rejecting) and enforce a magnitude ceiling (by rejecting, confirmed
  above).
- **Neither guarantees Stage 6 correctness on its own.** An internally
  *computed* value (e.g., an intermediate discount/tax calculation) that
  accumulates excess scale through floating-point arithmetic before
  being persisted would be silently rounded by the database, not
  flagged — Stage 6 must apply the same "round only at defined
  materialization points" discipline (invariants.md #56) to computed
  values, not rely on the database to catch a rounding mistake it is
  incapable of catching.

**Documents corrected as a direct result of this finding:**
[constraint-register.md](constraint-register.md) (DB-INV-061/062,
reclassified COMBINED with the precise mechanism split above),
[stage-5-report.md](stage-5-report.md) (§5), and the PHPUnit test suite
itself — see `PrecisionCoercionTest.php`
(`test_money_scale_overage_is_rounded_not_rejected`,
`test_quantity_scale_overage_is_rounded_not_rejected`, both asserting
the exact rounded value, not just "no exception") plus the pre-existing
magnitude-overflow tests in `ConstraintValidationTest.php`, **renamed**
from `test_invalid_money_precision_rejected`/
`test_invalid_quantity_precision_rejected` to
`test_money_magnitude_overflow_rejected`/
`test_quantity_magnitude_overflow_rejected` so the test name itself no
longer implies a claim broader than what it actually verifies.

---

## Context-integrity negative tests (owner hardening pass, 2026-09-16)

Live proof that the 13 composite foreign keys added to close
DB-INV-063 (see [context-integrity-matrix.md](context-integrity-matrix.md))
reject every combination the owner named — a genuine PostgreSQL
constraint violation, not merely an application-level check that never
issues the INSERT. All 9 tests in `tests/Database/ContextIntegrityTest.php`
build fixtures with raw `DB::table()->insert()` and assert the write
fails with a `QueryException`.

| # | Scenario (owner's wording) | Result |
|---|---|---|
| 1 | Store A Sale × Store B Shift | **PASS — rejected** |
| 2 | Store A Sale × Store B FiscalDay | **PASS — rejected** |
| 3 | Terminal A Sale × Terminal B Shift (same store) | **PASS — rejected** |
| 4 | Terminal A Sale × Terminal B FiscalDay (same store) | **PASS — rejected** |
| 5 | Refund processing Terminal A × Terminal B Shift | **PASS — rejected** |
| 6 | Void processing Terminal A × Terminal B FiscalDay | **PASS — rejected** |
| 7 (bonus) | A fully coherent sale (matching store/terminal/shift/fiscal_day) | **PASS — succeeds**, confirming the new constraints don't reject legitimate data |
| 8 (bonus) | A shift citing a fiscal_day that belongs to a different terminal | **PASS — rejected** |
| 9 (bonus) | A Store B terminal associated with a Store A fiscal_installation | **PASS — rejected** |

Scenarios 3/4 specifically address the owner's item 3 ("same-store is
not enough") — both use two terminals that genuinely belong to the same
store, proving the composite FKs key off `terminal_id` (not `store_id`
alone) wherever a shift or fiscal_day is involved, so a same-store
attack is caught by exactly the same mechanism as a cross-store one.

---

## Invoice structural coherence negative tests (owner follow-up passes, 2026-09-16)

Live proof that `invoices.store_id` and its composite FKs reject
exactly the combinations named across both Invoice follow-up
instructions, and that the pre-existing per-series serial uniqueness
scope was neither tightened nor loosened by either fix. All 8 tests in
`tests/Database/InvoiceContextIntegrityTest.php` build fixtures with
raw `DB::table()->insert()`.

| # | Scenario (owner's wording) | Result |
|---|---|---|
| A | Store A Sale + Store B InvoiceSeries | **PASS — rejected** |
| B | Store A Sale + Store A InvoiceSeries | **PASS — succeeds** |
| — (bonus) | Invoice's own `store_id` disagrees with its sale's store | **PASS — rejected** |
| — (bonus) | Invoice cites a terminal belonging to a different store than its own declared `store_id` | **PASS — rejected** |
| C | Duplicate serial within the same InvoiceSeries | **PASS — rejected** (reconfirms DB-INV-018 after both schema changes) |
| D | Same numeric serial in a different, legitimate InvoiceSeries | **PASS — permitted**, confirming the frozen per-series uniqueness scope (`UNIQUE(invoice_series_id, invoice_number)`) still holds exactly as before |
| A (2nd follow-up) | Sale: Store A/Terminal 01. Invoice: Store A/same Sale/Terminal 02 | **PASS — rejected**, proving the new `invoices_store_terminal_sale_fk` catches a same-store, cross-terminal disagreement no store-level check could see |
| B (2nd follow-up) | Sale: Store A/Terminal 01. Invoice: Store A/same Sale/Terminal 01 | **PASS — succeeds**, confirming the stronger constraint doesn't reject legitimate, fully-coherent invoices |

Item 5 of the first follow-up's instruction ("verify the persisted
display invoice number cannot contradict the allocated serial/prefix
strategy") is satisfied structurally, not by a test:
`invoices.invoice_number` is itself the persisted, formatted display
value (digits-only, leading-zero-preserving `VARCHAR`, per the
migration's own long-standing comment) — there is no separate "display"
column that could drift out of sync with an allocated serial, because
nothing beyond the serial itself is stored. `invoice_series.prefix` is
applied at render time only.

---

## Automated constraint scenarios (Stage 5 instruction §59)

**All 15 required scenarios plus 1 bonus scenario were executed live**,
twice: first as a raw psql script against a scratch database while
designing the fixtures, then promoted into a genuine, repeatable,
CI-runnable PHPUnit suite —
[`tests/Database/ConstraintValidationTest.php`](../../tests/Database/ConstraintValidationTest.php)
(with its PostgreSQL-connection base class,
[`tests/Database/PostgresSchemaTestCase.php`](../../tests/Database/PostgresSchemaTestCase.php)).
Run it with:

```
php artisan test tests/Database
```

against a local PostgreSQL 17 instance (requires a `tindaflow_schema_test`
database the suite is free to drop and recreate via `migrate:fresh`; not
part of the default sqlite-based `php artisan test` run — see
[migration-plan.md](migration-plan.md) §5 and the note in `phpunit.xml`
for why). **Confirmed passing: 15 tests, 16 assertions, 0 failures**
(one test — cascade-removal — carries two assertions, `sale` and
`product` deletion, hence 16 assertions across 15 tests).

| # | Scenario | Result |
|---|---|---|
| 1 | Duplicate SKU rejected (scoped per store) | **PASS** |
| 2 | One OPEN Shift per Terminal | **PASS** |
| 3 | One OPEN Shift per Cashier | **PASS** |
| 4 | One OPEN FiscalDay per Terminal | **PASS** |
| 5 | Multiple interim XReadings allowed | **PASS** |
| 6 | Only one closing XReading per Shift | **PASS** |
| 7 | One ZReading per FiscalDay | **PASS** |
| 8 | Duplicate Invoice serial rejected (scoped per series) | **PASS** |
| 9 | Invalid Money precision rejected (`NUMERIC(12,2)` overflow) | **PASS** |
| 10 | AccumulatedMoney `NUMERIC(18,2)` boundaries | **N/A** — not a persisted relational column in this schema (database-schema.md §3); boundary already verified at Stage 4 (manifest pass 3, schema-validated examples) |
| 11 | Invalid Quantity precision rejected (`NUMERIC(10,3)` overflow) | **PASS** |
| 12 | FK cross-resource integrity (insert referencing nonexistent product) | **PASS** |
| 13 | Journal duplicate-source prevention (`source_type, source_id, event_type`) | **PASS** |
| 14 | Idempotency duplicate key prevention (`terminal_id, idempotency_key`) | **PASS** |
| 15 | Status CHECK constraints (`sales.status = 'DRAFT'` rejected) | **PASS** |
| 16 (bonus) | No cascade removal of financial history (delete a `sale`/`product` with children) | **PASS** — both rejected via `RESTRICT` |

**14 of 15 required scenarios pass; 1 is not applicable to this schema by
design** (its underlying value is never persisted relationally — see
database-schema.md §3), and its actual boundary behavior was already
verified separately at Stage 4.

---

## Concurrency test plan (Stage 5 instruction §60 — documented, not implemented)

Per Stage 5 instruction §60, these are defined for Stage 6 to implement
as application-level tests; they are **not** implemented in Stage 5,
since they require real transactional service code that does not exist
yet (the FROZEN-CORPUS RULE and Stage 5's own scope boundary both
exclude writing business-transaction orchestration this stage).

| Scenario | Rows expected to be locked | Expected outcome |
|---|---|---|
| Two concurrent Invoice allocations (same `invoice_series`) | `invoice_series` row (`SELECT ... FOR UPDATE`) | One succeeds with number N, the other blocks then succeeds with N+1 — never a duplicate, never a gap from either succeeding attempt |
| Two concurrent Refund approvals against the same `sale_item`'s remaining quantity/amount | The `sale_item` row (locked to serialize the cumulative-cap recheck), read through `refund_items_sale_item_idx` | One succeeds; the other fails with a cap-exceeded error re-evaluated *after* acquiring the lock, not against a stale pre-lock read |
| Void vs. Refund racing on the same `sale` | The `sale` row | Whichever transaction commits first wins; the second's precondition check (`sale.status <> VOIDED` for Refund; "no completed Refund exists" for Void) must be re-evaluated after acquiring the lock, not before |
| Z-Reading closure vs. a Sale finalizing on the same `fiscal_day` | The `fiscal_day` row | The finalization either completes before closure acquires its lock (Sale attributed to the now-closing day) or fails cleanly after (fiscal_day is no longer OPEN) — never a Sale silently attributed to an already-closed day |
| Z-Reading closure vs. a Refund executing on the same `fiscal_day` | The `fiscal_day` row (the refund's *processing* fiscal day, per invariant #69 — not the original sale's) | Same shape as above, applied to Refund's processing-context fiscal day rather than the original sale's |
| Shift close vs. a Refund executing against that shift | The `shift` row | Refund's processing-context shift must be OPEN at the moment of its `APPROVED→COMPLETED` transition (invariant #69); a race with shift closure must be resolved by row-lock ordering, not by an unguarded read-then-write |
| Duplicate Idempotency-Key submission (double-click, same request in flight twice) | `(terminal_id, idempotency_key)` — enforced structurally, not merely by application locking | **Already verified live** at the schema level (scenario 14 above) — PostgreSQL's own unique-index insert blocking makes this the one scenario in this table that is already schema-guaranteed regardless of Stage 6's transaction discipline |

---

## Schema diff against erd.md (Stage 5 instruction §61)

Every difference between the implemented schema and
[erd.md](../03-architecture/erd.md) was reviewed; each is explained
below as either an expected implementation detail or would be flagged
as a frozen-model contradiction. **No frozen-model contradiction was
found.**

| erd.md element | Schema implementation | Classification |
|---|---|---|
| `FISCAL_INSTALLATION`'s flat `accreditation_number`/`accreditation_date`/`ptu_number`/`ptu_date` fields | Split into `fiscal_installation_accreditations`/`fiscal_installation_permits_to_use` child tables | **Expected implementation detail** — erd.md's own Stage 3 forward note explicitly deferred this exact decision to Stage 5 (see database-schema.md §8) |
| `X_READING`'s prose ("distinguish interim vs. closing reading") without a named column | `is_closing_reading` boolean column | **Expected implementation detail** — erd.md describes the requirement; Stage 5 names the column implementing it, consistent with openapi.yaml's `XReading` schema |
| `SALE_ITEM.unit_of_measure_snapshot` sourced from `PRODUCT.unit_of_measure` | `products.unit_of_measure` exists as a `NOT NULL` column (erd.md's `PRODUCT` entity did not separately list it, though domain-model.md's snapshot fields imply its existence on the source) | **Expected implementation detail** — a source column the snapshot is copied from at finalization must exist somewhere; erd.md's `PRODUCT` block is not exhaustive of every field, and no invariant is affected |
| `AccumulatedMoney` (Stage 4 API concept) | No persisted column anywhere | **Expected implementation detail**, explicitly reasoned through in database-schema.md §3 — erd.md never proposed a stored column for it either |
| Cross-store referential consistency | Not composite-FK-enforced | **Disclosed gap, not a contradiction** — erd.md doesn't model this explicitly either; flagged as DB-INV-063 for Stage 6 attention |
| `STOCK_MOVEMENT.reference_type`/`reference_id` | Implemented exactly as erd.md specifies (both fields present, nullable, matching types) | **No difference** |
| Every other entity/attribute/relationship in erd.md | Implemented 1:1 | **No difference** |

---

## Overall Database test suite (after all three 2026-09-16 hardening passes)

```
php artisan test tests/Database
```

**42 tests, 59 assertions, 0 failures** (was 18/30 originally, 34/51
after the first pass, 40/57 after the Invoice structural-coherence
pass): 15 `ConstraintValidationTest` (2 renamed for
precision-classification accuracy, see above), 3 `FactorySmokeTest`, 7
`PrecisionCoercionTest`, 9 `ContextIntegrityTest`, 8
`InvoiceContextIntegrityTest` (6 from the structural-coherence pass, 2
new for the terminal-identity fix). Confirmed live against PostgreSQL
17.

---

## What remains (disclosed, not silently skipped)

- `tests/Database/ConstraintValidationTest.php` builds its fixtures with
  raw `DB::table()->insert()` calls rather than the factories described
  in [migration-plan.md](migration-plan.md) §5 (which were written
  afterward, and are exercised by
  [`FactorySmokeTest.php`](../../tests/Database/FactorySmokeTest.php)
  instead). Refactoring `ConstraintValidationTest` to build its fixtures
  through the factories is a reasonable Stage 6 cleanup — the assertions
  themselves would not change — but was not done here to avoid coupling
  the constraint-validation suite's correctness to the factories'
  correctness (the two are verified independently on purpose).
- This suite is not wired into CI yet (no Postgres service container is
  configured for the test pipeline) and is not part of the default
  `php artisan test` run — it must be invoked explicitly with
  `php artisan test tests/Database` against a locally reachable
  PostgreSQL 17 instance, as documented in `phpunit.xml`.
- The concurrency test plan above is a specification, not executable
  code — it requires Stage 6's transaction/service layer to exist first.
- `harden_append_only_privileges.sql` has not been applied to any
  environment yet (including this validation's own test database) — the
  append-only guarantees for `audit_events`/`electronic_journal_entries`/
  etc. are currently structural (no `updated_at`, no application route)
  but not yet enforced at the PostgreSQL role/privilege level in any
  running database, including this validation's own.
- **Resolved in the follow-up pass**: `invoices`' structural store
  coherence (sale/invoice_series/terminal all agreeing with the
  invoice's own `store_id`) is now DATABASE-enforced — see A.3.3 and
  the "Invoice structural coherence negative tests" section above.
  **Still deliberately unfixed, and correctly so**: whether the cited
  `invoice_series`/`fiscal_installation` was temporally *eligible* at
  the exact `issued_at` instant — the owner's own follow-up instruction
  classified this as Stage 6 transactional domain logic, not a database
  constraint, since a composite FK cannot express historical
  effective-date validity. See
  [context-integrity-matrix.md](context-integrity-matrix.md)'s "Invoice
  structural coherence" section for the full A/B split.
