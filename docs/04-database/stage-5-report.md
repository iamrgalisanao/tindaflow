# Stage 5 Completion Report — Database Schema and Migrations

## Status

**AWAITING OWNER APPROVAL — NOT FROZEN, NOT TAGGED.** Per the owner's
explicit instruction ("STOP. Do not begin Stage 6. Do not tag
stage-5-baseline until explicitly approved"), this report is the
completion checkpoint for review, not a self-declared freeze. No
`stage-5-baseline` tag has been created.

**2026-09-16 — Hardening pass addendum.** The owner conditionally
approved this report's original submission and required two
corrections before tagging: (1) verify, with raw SQL, the claim that
PostgreSQL rejects excessive Money/Quantity fractional precision — it
does not, it rounds; and (2) close DB-INV-063 (cross-store/cross-terminal
context integrity) with real composite database constraints rather than
deferring it entirely to Stage 6. **See "Hardening Pass Addendum" below
for the owner's exact 13-item report request, answered point by point.**
Sections 5, 14, 15, and 20 below were also corrected in place to match.

**2026-09-16 — Second, narrower hardening pass addendum.** The owner
accepted the first addendum's substance in full and withheld the tag
for exactly one remaining, narrowly scoped item: `invoices`' own
structural store coherence (a Store A sale documented by a Store B
invoice_series was still structurally possible, the same defect class
just closed for Sale/Shift/FiscalDay/Void/Refund/etc.). The owner
explicitly separated this from `invoices`' temporal/fiscal-eligibility
question (whether a series/installation was valid *at the issuance
instant*), which they correctly classified as Stage 6 transactional
logic, not a database constraint, and told me not to touch. **See
"Hardening Pass Addendum #2" at the very end of this document for the
owner's exact 10-item follow-up report, answered point by point.**

**2026-09-16 — Third, final hardening pass addendum.** The owner
accepted the second addendum's substance in full and identified one
last database-enforceable gap: the Invoice/Sale terminal-identity
composite FKs from the second pass proved `invoices.terminal_id`
belonged to the right *store*, but not that it matched the terminal
that actually finalized the sale — a Store A invoice could still cite
a Store A sale finalized on a different Store A terminal. Since Sale
finalization and Invoice issuance are one atomic operation in the
frozen checkout architecture (ADR-003), the owner correctly treated
this as a DATABASE invariant, not a Stage 6 deferral. **See "Hardening
Pass Addendum #3" at the very end of this document for the owner's
exact final report, answered point by point — including the git commit
hashes for the completed, tag-ready Stage 5 work.**

---

## 1. Files created/modified

**Created:**
- `docs/04-database/database-schema.md`, `constraint-register.md`,
  `index-strategy.md`, `migration-plan.md`, `schema-validation.md`,
  `stage-5-report.md` (this file)
- 37 migration files in `database/migrations/`
- 34 Eloquent model files in `app/Models/`
- 13 factory files in `database/factories/` (`StoreFactory`,
  `TerminalFactory`, `FiscalDayFactory`, `ShiftFactory`,
  `ProductFactory`, `InvoiceSeriesFactory`, `SaleFactory`,
  `SaleItemFactory`, `PaymentFactory`, `InvoiceFactory`,
  `RefundFactory`, `SaleVoidFactory`, plus the rewritten `UserFactory`)
- `database/seeders/AdminUserSeeder.php`, `DemoDataSeeder.php`
- `database/scripts/harden_append_only_privileges.sql`
- `tests/Database/PostgresSchemaTestCase.php`,
  `ConstraintValidationTest.php`, `FactorySmokeTest.php`

**Modified:**
- `database/seeders/DatabaseSeeder.php` (rewired to call
  `AdminUserSeeder` always, `DemoDataSeeder` outside production only)
- `.env` (pointed at a local PostgreSQL 17 connection instead of the
  Laravel scaffold default sqlite)
- `phpunit.xml` (documented, via comment, why `tests/Database/` is
  deliberately not registered as a default testsuite)

**Deleted:**
- `database/migrations/0001_01_01_000000_create_users_table.php`
  (Laravel's scaffolded default — conflicted with TindaFlow's own
  `users`/`sessions` schema; see schema-validation.md §A.3.1)
- `database/database.sqlite`

---

## 2. Tables created

**41 total**: 34 domain tables + 7 Laravel framework tables (`cache`,
`cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`,
`migrations`). Full list confirmed live via `\dt` — see
database-schema.md §19.

---

## 3. Migration count

**37** migration files, executed successfully against live PostgreSQL
17 from an empty database, and fully reversed (`migrate:reset`) and
re-applied (`migrate`) without error. See migration-plan.md for the
dependency-ordered DAG and schema-validation.md Pass A for the live
execution record.

---

## 4. Database enum/CHECK strategy

VARCHAR + `CHECK (... IN (...))`, not native PostgreSQL `ENUM` types,
applied uniformly across every bounded-value column. **57 CHECK
constraints** confirmed live. Full rationale in database-schema.md §4.

---

## 5. Money/Quantity types

- **Money**: `NUMERIC(12,2)` on every transactional monetary column, no
  exceptions, no `FLOAT`/`REAL`/`DOUBLE PRECISION` anywhere.
- **Quantity**: `NUMERIC(10,3)` on every quantity column.
- **AccumulatedMoney**: deliberately **not** a persisted relational
  column anywhere — lives only inside `z_reading.totals_snapshot` JSONB,
  computed on demand. Full reasoning in database-schema.md §2/§3.
- **Corrected 2026-09-16 (owner review):** the original submission of
  this report claimed the database "rejects" excessive Money/Quantity
  precision. That was imprecise and has been corrected everywhere.
  Verified with raw SQL, bypassing Laravel entirely: PostgreSQL
  `NUMERIC(p,s)` **rounds** an input with excess fractional digits to
  the declared scale (`1.234` → `1.23`, `12.3456` → `12.35`, `0.005`
  rounds up to `0.01`) rather than rejecting it. It **does** reject an
  input whose *magnitude* (integer-digit count) exceeds what the
  declared precision allows (`99999999999.99` into `NUMERIC(12,2)` →
  `numeric_value_out_of_range`), including the edge case where rounding
  itself pushes a value across that boundary. Enforcement is properly
  **COMBINED**: Stage 4's already-frozen OpenAPI `Money`/`Quantity`
  string patterns reject malformed request input before it reaches the
  database; `NUMERIC(12,2)`/`NUMERIC(10,3)` guarantee the *stored*
  value's scale and magnitude. Full ground-truth results:
  schema-validation.md's "Precision ground-truth tests" section.

---

## 6. Indexes

Full inventory and rationale in index-strategy.md. Summary: every index
traces to a partial-unique invariant, a named Stage 4 query pattern, or
a foreign-key join/lock path — none speculative. One disclosed gap:
`audit_events`/`electronic_journal_entries` have no dedicated
`occurred_at`-range index yet (index-strategy.md §4), deferred pending
real query-volume profiling.

---

## 7. Partial unique indexes

**13**, confirmed live: one OPEN Shift per Terminal, one OPEN Shift per
Cashier, one OPEN FiscalDay per Terminal, one closing XReading per
Shift, at most one VOIDED Void per Sale, idempotent Sale finalization
per terminal, barcode uniqueness per store, and four "one current
effective-dated row" constraints (`tax_registrations`,
`fiscal_installation_accreditations`,
`fiscal_installation_permits_to_use`, `terminal_fiscal_installations`).
Full list in database-schema.md §19 and constraint-register.md.

---

## 8. Invoice numbering schema

`invoice_series` (first-class table: `current_number`/
`starting_number`/`ending_number` as `BIGINT`, `status`, optimistic-lock
`version`) is separate from `invoices.invoice_number` (digits-only,
leading-zero-preserving `VARCHAR`). Uniqueness scoped to
`UNIQUE(invoice_series_id, invoice_number)`. `sales.transaction_number`
is a structurally independent column with no relationship to this
scope. Full detail in database-schema.md §11; duplicate-serial rejection
verified live (§15 below).

---

## 9. Idempotency schema

`idempotency_records` (`terminal_id`, `idempotency_key`,
`operation_type`, `request_hash`, `status` ∈
{`IN_PROGRESS`,`COMPLETED`}, `result_type`, `result_resource_id`).
**The owner's flagged scrutiny area #1** is addressed by a transaction-
boundary design, not merely a schema shape: the reservation row is
inserted as the first statement of the same transaction as the business
mutation, so a crash or a failed attempt leaves no row at all rather
than a stuck `IN_PROGRESS` row — full scenario table in
database-schema.md §14. Duplicate-key rejection verified live (§15).

---

## 10. Void/Refund persistence

Both carry the full request lifecycle plus an independent **processing
context** (`terminal_id`/`fiscal_day_id`/`shift_id`), enforced
all-or-nothing by a CHECK constraint distinct from the original sale's
own context (invariants #67/#68). `RefundSettlement` inherits processing
context from its parent `Refund` rather than duplicating it. Full detail
in database-schema.md §13; constraint-register.md enumerates exactly
which parts of Void/Refund eligibility are DATABASE- vs. TRANSACTION-
enforced (the five-part Void gate and cumulative Refund caps are
TRANSACTION-level, disclosed explicitly, not silently unenforced).

---

## 11. X/Z persistence

`x_readings`: zero-or-more per shift (no `UNIQUE(shift_id)`),
`is_closing_reading` boolean with a partial unique index limiting at
most one closing reading per shift. `z_readings`: `UNIQUE(fiscal_day_id)`
— exactly one per fiscal day — plus a per-terminal `z_counter` with its
own uniqueness backstop. Both are append-only (no `updated_at`, no
application UPDATE/DELETE route). Both `totals_snapshot` JSONB columns
are caches of ledger computations, never independently authoritative
(invariant #40) — see database-schema.md §18.

---

## 12. Audit/Journal persistence

`audit_events` and `electronic_journal_entries` are both structurally
append-only (no `updated_at`, no application mutation route).
`electronic_journal_entries.id` uses `HasUlids` (a ULID stored in a
`uuid` column) to satisfy both erd.md's `uuid` typing and ADR-005's
chronological-ordering suggestion without contradiction — see
database-schema.md §1. Duplicate-journal-entry prevention
(`UNIQUE(source_type, source_id, event_type)`) verified live (§15).
Full DB-role-level enforcement (`REVOKE UPDATE, DELETE`) is defined in
`harden_append_only_privileges.sql` but **not yet applied to any running
environment**, including this validation's own — disclosed explicitly
in schema-validation.md.

---

## 13. FiscalInstallation/PTU/Accreditation persistence decision

**Option A: effective-dated child tables**, not flat column-pairs on
`fiscal_installations` — `fiscal_installation_accreditations` and
`fiscal_installation_permits_to_use` are independent history tables,
each with its own "one current row" partial unique index, per ADR-009's
explicitly-deferred decision and RMC 72-2025's confirmation that the two
lifecycles are genuinely independent. Full reasoning in
database-schema.md §8.

---

## 14. Domain invariant enforcement matrix

**Updated 2026-09-16, three times.** constraint-register.md maps every
one of the 72 numbered invariants (plus several cross-cutting
structural rules) to a DATABASE/TRANSACTION/APPLICATION
POLICY/COMBINED enforcement level. **DB-INV-063 (cross-store/
cross-terminal referential consistency) was retired as a single
catch-all and split into DB-INV-063a through DB-INV-063m** across all
three hardening passes: 10 of the 13 splits are now
**DATABASE**-enforced via composite `UNIQUE`+`FOREIGN KEY` constraints,
verified live by 17 negative tests total (9 from the first pass, 6
from the Invoice structural-coherence follow-up, 2 from the
Invoice/Sale terminal-identity fix); 1 (`cash_movements`/
`idempotency_records`) required no fix (reviewed, single context
column, nothing to disagree with); 1 (DB-INV-063k, `invoices`'
temporal/fiscal eligibility) is correctly classified **TRANSACTION**,
not deferred as a gap — the owner's own instruction confirmed this
belongs in Stage 6, since a composite FK cannot express historical
effective-date validity; 1 (DB-INV-063l, `sale_items.product_id`
coherence) remains an explicitly deferred, disclosed gap, out of every
review pass's named scope. DB-INV-063j/063m specifically: the original
three composite FKs on `invoices` were consolidated into two — a
narrower `(store_id, sale_id) → sales(store_id, id)` and `(store_id,
terminal_id) → terminals(store_id, id)` pair was replaced by one
stronger `(store_id, terminal_id, sale_id) → sales(store_id,
terminal_id, id)`, since the stronger FK subsumes both (see §9 of
Addendum #3 below). Full detail:
[context-integrity-matrix.md](context-integrity-matrix.md). DB-INV-061/
062 (Money/Quantity precision) were also reclassified from bare
DATABASE to COMBINED — see §5 above.

---

## 15. Schema validation test results

**Pass A (SCHEMA): PASS**, live, re-run after every hardening pass. All
37 migrations ran clean from empty; full `migrate:reset`/`migrate`
round-trip succeeded with zero errors across all four checkpoints
(original, cross-store/cross-terminal fix, Invoice structural-coherence
follow-up, Invoice/Sale terminal-identity fix). Net additions across
all three hardening passes: 15 composite foreign keys and 7 composite
unique constraints (the terminal-identity fix's net FK count is −1
relative to the prior checkpoint, since it replaced two narrower
composite FKs with one stronger one). Two real schema defects were
found and fixed in the original pass (a leftover conflicting Laravel
default migration, and a missing `updated_at` on `sales`); three
test-authoring defects (not schema defects) were found and fixed while
writing the first hardening pass's new tests; a stale CHECK-constraint
count (57, corrected to the actual 59) was also caught and fixed while
verifying the Invoice follow-up — all documented in
schema-validation.md §A.3.1/§A.3.2/§A.3.3/§A.3.4.

**Pass B (DOMAIN): PASS**, with 1 disclosed deliberate deviation and
1 disclosed gap remaining (down from 3 deviations/1 gap originally —
DB-INV-063's cross-store/cross-terminal family is now closed for 10 of
its 13 split sub-invariants, 1 is correctly classified TRANSACTION
rather than a gap, and only `sale_items.product_id` coherence remains
an open, disclosed item; see §14).

**Pass C (ARCHITECTURE): PASS at schema-readiness level** — every
primitive Stage 6's transaction/lock-order/idempotency architecture
needs is confirmed present; end-to-end architectural behavior cannot be
tested until Stage 6 code exists.

**Pass D (API): PASS** — every financially significant API resource has
a confirmed persistence path; no resource found without one.

**Automated constraint tests**: **42 PHPUnit tests, 59 assertions, all
passing** (was 18/30 originally, 34/51 after the first hardening pass,
40/57 after the second), live against PostgreSQL 17 —
`tests/Database/ConstraintValidationTest.php` (15 scenarios; 2 renamed
for precision-classification accuracy, see §5),
`tests/Database/FactorySmokeTest.php` (3, factory correctness),
`tests/Database/PrecisionCoercionTest.php` (7 — proves rounding for
scale overage and rejection for magnitude overflow, with exact
stored-value assertions), `tests/Database/ContextIntegrityTest.php` (9
— proves rejection of every named cross-store/cross-terminal
combination), `tests/Database/InvoiceContextIntegrityTest.php` (8 — 6
from the structural-coherence follow-up proving Invoice structural
store coherence and reconfirming per-series serial uniqueness, plus 2
new this pass proving Invoice/Sale terminal identity). Run with `php
artisan test
tests/Database`.

**Concurrency test plan**: documented (schema-validation.md), not
implemented — correctly out of Stage 5's scope per the FROZEN-CORPUS
RULE, since it requires Stage 6 transaction code.

---

## 16. ERD diff

Full table in schema-validation.md. **No frozen-model contradiction
found.** Every difference between the implemented schema and erd.md is
an expected implementation detail explicitly anticipated by erd.md's own
Stage 3 forward note (FiscalInstallation split) or a documented Stage 5
decision (AccumulatedMoney, `is_closing_reading` naming) — except the
cross-store consistency gap, which is disclosed as a gap rather than
classified as either.

---

## 17. OpenAPI/storage traceability findings

Full table in schema-validation.md Pass D. Every financially significant
resource named in Stage 5 instruction §62 has a confirmed relational
persistence path. No orphaned API resource (a resource with no database
backing) was found.

---

## 18. Unresolved questions

- **Updated 2026-09-16, three times**: the core cross-store/cross-terminal
  gap (DB-INV-063) is now closed for Shift/FiscalDay/Sale/Void/Refund/
  XReading/ZReading/FiscalInstallation-Terminal/**Invoice (both
  structural store coherence AND Invoice/Sale terminal identity)** via
  composite database constraints — see §14 and
  context-integrity-matrix.md. What remains open: (a)
  `products.category_id`/`brand_id` against `store_id` (same class of
  gap, not part of any review pass's named list); (b)
  `sale_items.product_id` against the sale's own store (same reasoning).
  `invoices.fiscal_installation_id`'s temporal eligibility against
  `terminal_fiscal_installations`' effective-dating is **not** an open
  question in the same sense — the owner explicitly classified it as
  Stage 6 transactional logic, not a database gap (see
  DB-INV-063k). Items (a)/(b) are recommended for a dedicated future
  pass, not resolved here.
- Whether `audit_events`/`electronic_journal_entries` need a dedicated
  `occurred_at`-range index before Stage 8 testing begins at realistic
  data volume — deferred pending profiling (index-strategy.md §4).
- Whether `harden_append_only_privileges.sql` should be wired into the
  deployment pipeline automatically (e.g., a post-migrate hook) or
  remain a manually-applied operational step — currently the latter,
  by design, since it changes database role privileges rather than
  schema shape.

---

## 19. BIR-REVIEW-REQUIRED items carried forward

**BIR-006, BIR-007, BIR-008, BIR-010** (from
`bir-reference-register.md`) are carried forward unresolved, exactly as
they stood at the end of Stage 4 — no Stage 5 schema decision was made
by guessing at their resolution. None of Stage 5's design decisions
depend materially on any of the four (the FiscalInstallation/
Accreditation/PTU split, for instance, is driven by RMC 72-2025's
already-confirmed independent-lifecycles finding, not by any of the
four open items).

---

## 20. Risks

- **Updated 2026-09-16, three times — essentially resolved**:
  cross-store/cross-terminal referential consistency for
  `Shift`/`FiscalDay`/`Sale`/`Void`/`Refund`/`XReading`/`ZReading`/
  `FiscalInstallation`-`Terminal`/**`Invoice` (both structural store
  coherence and Invoice/Sale terminal identity)** is now
  database-enforced via 15 composite foreign keys, verified live by 17
  negative tests total (see §14, §15,
  [context-integrity-matrix.md](context-integrity-matrix.md)). **Residual
  risk, narrower still**: only `products.category_id`/`brand_id` and
  `sale_items.product_id` remain unenforced against their own store
  context — the same class of gap, on a smaller, explicitly-named set
  of columns, deferred to avoid the "broad schema redesign" the owner
  said no pass should become. `invoices`' temporal/fiscal eligibility
  (was the series/installation valid *at issuance*) is not a residual
  risk in this sense — it is correctly Stage 6 transactional logic, per
  the owner's own classification.
- **`harden_append_only_privileges.sql` has not been applied to any
  running environment** — the append-only guarantee for
  `audit_events`/`electronic_journal_entries`/etc. is currently
  structural (no `updated_at`, no application route) but not yet
  privilege-enforced at the database-role level anywhere, including the
  environment used for this validation.
- **The concurrency test plan is unimplemented** — every lock-order/
  race scenario in schema-validation.md is a specification for Stage 6,
  not yet proven under real concurrent load.
- **`stock_movements`' polymorphic `reference_type`/`reference_id`
  attribution** carries no database-level referential-integrity
  guarantee to its source rows (by disclosed design — see
  database-schema.md §10) — a display/traceability convenience only,
  never load-bearing for a domain invariant.

---

## 21. Proposed Stage 6 backend implementation order

Following ADR-001's module boundaries and this schema's own dependency
order (migration-plan.md §2), recommended Stage 6 sequencing:

1. **Auth & Terminal identity** (Module A) — login, terminal enrollment
   redemption, capability evaluation middleware. Nothing else can be
   meaningfully tested without this.
2. **Store Settings & Product Catalog** (Modules B/C) — configuration
   CRUD, barcode/SKU lookup service.
3. **Shift & Fiscal Day lifecycle** (Module H) — open/close transactions,
   exercising the partial-unique-index guarantees directly.
4. **Checkout / Sale finalization** (Modules D/E/F) — the highest-risk
   transaction (Global Lock Order, InvoiceSeries allocation,
   idempotency), built once Shift/FiscalDay exist to finalize against.
5. **Invoice issuance & reprint** (Module G) — layers directly onto
   Sale finalization.
6. **Void & Refund workflows** (Module K) — the second-highest-risk
   transaction set (approval-execution locking, cumulative caps,
   processing-context population) — deliberately sequenced after
   Checkout is proven stable, since every Void/Refund test fixture
   requires a working Sale first.
7. **Inventory ledger operations** (Module I) — stock receipts/
   adjustments, independent of the sales-transaction chain but easiest
   to validate once `stock_movements`/`stock_balances` consistency
   patterns are proven by Checkout's own stock-deduction path.
8. **X/Z Readings & Cash Movements** — layer onto completed
   Shift/FiscalDay/Sale/Refund data.
9. **Reporting endpoints** (Module L) — last, since every report reads
   data every prior module produces.

This order is a recommendation for the owner's review, not a
self-authorized start of Stage 6 work.

---

# Hardening Pass Addendum (2026-09-16)

Answering the owner's exact 10-item hardening instruction and its
13-item final-report request, point by point. **Do NOT begin Stage 6.
Do NOT tag `stage-5-baseline` until explicitly approved** — this
addendum is a second submission for review, not a self-declared freeze.

### 1. Actual PostgreSQL behavior for excessive Money fractional precision

**Rounds, does not reject.** `NUMERIC(12,2)` receiving `1.234` stores
`1.23`; `12.3456` stores `12.35`; `0.005` stores `0.01` (rounds half-up
at the boundary). Verified with raw SQL, no Laravel/Eloquent/validation
in the path. Full table: schema-validation.md "Precision ground-truth
tests."

### 2. Actual PostgreSQL behavior for excessive Quantity fractional precision

**Same behavior.** `NUMERIC(10,3)` receiving `1.2345` stores `1.235`.
Rejection only occurs when the (post-rounding) magnitude exceeds the
column's integer-digit capacity — verified including the edge case
where rounding itself pushes a value across that boundary
(`9999999.9995` → rounds to `10000000.000` → then overflows).

### 3. Whether documentation/tests were corrected

**Yes, all of them**, not selectively:
- `constraint-register.md` — DB-INV-061/062 reclassified from bare
  DATABASE to COMBINED, with the exact rounding-vs-magnitude split
  stated.
- `schema-validation.md` — new "Precision ground-truth tests" section
  with the full raw-SQL result table and corrected classification.
- `stage-5-report.md` (this document) — §5 corrected in place.
- PHPUnit tests — `test_invalid_money_precision_rejected`/
  `test_invalid_quantity_precision_rejected` **renamed** to
  `test_money_magnitude_overflow_rejected`/
  `test_quantity_magnitude_overflow_rejected` (they were always testing
  magnitude overflow, not scale rejection — the name was the defect, not
  the test), and a new `PrecisionCoercionTest.php` added specifically to
  prove the rounding behavior with exact stored-value assertions.

### 4. DB-INV-063 final disposition

**Retired as a single catch-all, split into DB-INV-063a through
DB-INV-063j.** 8 of the 10 splits are now **DATABASE**-enforced via
composite constraints, verified live by 9 negative tests. 1
(`cash_movements`/`idempotency_records`) required no fix — reviewed and
found structurally sound (single context column, nothing to disagree
with). 1 (`invoices`/`sale_items.product_id` family) remains an
explicitly deferred, disclosed gap, out of the owner's named review
list and requiring a harder effective-dating-aware design for the
`invoices`/`terminal_fiscal_installations` half specifically. Full
detail: [context-integrity-matrix.md](context-integrity-matrix.md).

### 5. Composite constraints added

**4 composite `UNIQUE` constraints**: `terminals(store_id, id)`,
`fiscal_installations(store_id, id)`, `fiscal_days(terminal_id, id)`,
`shifts(terminal_id, id)`.

**13 composite `FOREIGN KEY` constraints**: `fiscal_days` → `terminals`
(1), `shifts` → `fiscal_days` (1), `terminal_fiscal_installations` →
`terminals` and → `fiscal_installations` (2), `sales` → `terminals`/
`shifts`/`fiscal_days` (3), `voids` → `shifts`/`fiscal_days` (2),
`refunds` → `shifts`/`fiscal_days` (2), `x_readings` → `shifts` (1),
`z_readings` → `fiscal_days` (1).

**1 new column**: `terminal_fiscal_installations.store_id` (disclosed
denormalization, needed for its two composite FKs).

No trigger was used anywhere — every rule is expressed as a
declarative composite key.

### 6. Context-integrity matrix

Delivered as its own document:
[context-integrity-matrix.md](context-integrity-matrix.md) — every
table the owner named, its context columns, whether an invalid
combination was possible before this pass, what was added, and why (or,
for `cash_movements`/`idempotency_records`, why nothing was needed).
Includes an explicit "same-store-is-not-enough" verification section
and an "explicitly deferred, not silently ignored" section for
`invoices`/`sale_items.product_id`.

### 7. Cross-store negative-test results

All 4 named scenarios **PASS — rejected**: Store A Sale × Store B Shift;
Store A Sale × Store B FiscalDay; a Store B terminal associated with a
Store A fiscal_installation (bonus, beyond the owner's literal list, per
their "any FiscalInstallation/Terminal relationship" instruction). Full
table: schema-validation.md "Context-integrity negative tests."

### 8. Cross-terminal negative-test results

All 4 named scenarios **PASS — rejected**, including the two
same-store/different-terminal cases the owner specifically flagged as
"not enough" to skip: Terminal A Sale × Terminal B Shift (same store);
Terminal A Sale × Terminal B FiscalDay (same store); Refund processing
Terminal A × Terminal B Shift; Void processing Terminal A × Terminal B
FiscalDay. A 9th bonus test confirms a fully coherent sale still
succeeds (the new constraints don't reject legitimate data), and a
10th confirms a shift itself rejects a fiscal_day from a different
terminal.

### 9. Final FK/delete-rule counts

**89 foreign keys** (was 75): 1 `CASCADE`
(`product_barcodes→products`, unchanged), 2 `SET NULL`
(`products→categories`/`brands`, unchanged), 73 single-column
`RESTRICT` (was 72 — +1 for the new
`terminal_fiscal_installations.store_id` FK), 13 composite `NO ACTION`
(new — functionally equivalent to `RESTRICT` for a non-deferrable
constraint, which none of these are). **No unexpected CASCADE was
introduced** — verified directly via `pg_constraint.confdeltype` after
the composite-FK additions.

### 10. Migration round-trip result

**Clean, both before and after.** Fresh database →
`php artisan migrate` (37/37, zero errors) → `php artisan migrate:reset`
(zero errors) → `php artisan migrate` (zero errors, identical 41-table
schema reproduced). Run twice: once immediately after adding the
composite constraints, once again as the final verification before this
addendum was written.

### 11. PHPUnit test count/assertions

**34 tests, 51 assertions, 0 failures** (was 18/30). Breakdown: 15
`ConstraintValidationTest` (2 renamed), 3 `FactorySmokeTest`, 7
`PrecisionCoercionTest` (new), 9 `ContextIntegrityTest` (new). Run with
`php artisan test tests/Database`.

### 12. Files changed

**New**: `docs/04-database/context-integrity-matrix.md`,
`tests/Database/ContextIntegrityTest.php`,
`tests/Database/PrecisionCoercionTest.php`.

**Modified**: `database/migrations/2026_01_01_000040_create_terminals_table.php`,
`..._000070_create_fiscal_installations_table.php`,
`..._000080_create_terminal_fiscal_installations_table.php`,
`..._000140_create_fiscal_days_table.php`,
`..._000150_create_shifts_table.php`,
`..._000200_create_sales_table.php`,
`..._000240_create_voids_table.php`,
`..._000250_create_refunds_table.php`,
`..._000170_create_x_readings_table.php`,
`..._000180_create_z_readings_table.php`,
`app/Models/TerminalFiscalInstallation.php`,
`tests/Database/ConstraintValidationTest.php` (2 tests renamed),
`docs/04-database/database-schema.md` (§6/§6a/§19),
`docs/04-database/constraint-register.md` (DB-INV-060/061/062/063
family), `docs/04-database/schema-validation.md` (new sections + updated
counts), `docs/04-database/stage-5-report.md` (this file, §5/§14/§15/
§18/§20 + this addendum).

Also installed and configured in this session (per this project's
`CLAUDE.md` Laravel Boost bootstrap instructions, unrelated to the
hardening pass itself): `laravel/boost` (dev dependency), which
regenerated this project's `CLAUDE.md` with tailored Laravel/PHPUnit/Pint
guidance.

### 13. Final Stage 5 commit hash

**None yet — nothing in this Stage has been committed.** Per the
owner's explicit instruction, no commit or tag has been created for
Stage 5 at any point, including this hardening pass. All work described
in this report and its addendum exists only in the working tree,
awaiting the owner's review and explicit approval before any commit or
`stage-5-baseline` tag is created.

---

# Hardening Pass Addendum #2 (2026-09-16) — Invoice Structural Coherence

Answering the owner's second, narrower 10-item follow-up instruction,
point by point. **Do NOT begin Stage 6. Do NOT tag `stage-5-baseline`
until explicitly approved** — this is a third submission for review,
not a self-declared freeze.

### 1. Invoice structural coherence strategy

Split into two invariants exactly as the owner specified: **(A)
structural ownership** (Sale and InvoiceSeries used by an Invoice must
belong to the same Store — closed this pass, DATABASE) and **(B)
temporal/fiscal eligibility** (was the series/installation valid at the
issuance instant — left as Stage 6 TRANSACTION logic, not touched).

### 2. Composite constraints added

`invoices` gained a `store_id` column (disclosed denormalization) and
three composite foreign keys:
- `FOREIGN KEY (store_id, sale_id) REFERENCES sales(store_id, id)`
- `FOREIGN KEY (store_id, invoice_series_id) REFERENCES invoice_series(store_id, id)`
- `FOREIGN KEY (store_id, terminal_id) REFERENCES terminals(store_id, id)`

Two new supporting composite unique constraints were required as the
referenced side: `sales(store_id, id)` and `invoice_series(store_id,
id)`. The third FK (against `terminals`) was not explicitly requested
in the owner's minimal conceptual design but was added because it uses
the exact same new `store_id` column at zero additional schema cost,
and closes the same class of gap for `invoices.terminal_id` that the
owner's design already closed for `sale_id`/`invoice_series_id` — it
would have been inconsistent to add `store_id` for two coherence checks
but leave the invoice's own terminal reference unvalidated against it.
No terminal_id/shift_id/fiscal_day_id was duplicated onto `invoices`
beyond what the frozen ERD already specified (`terminal_id` already
existed) — no new redundant context was added.

### 3. Temporal eligibility classification

**TRANSACTION** (`DB-INV-063k` in constraint-register.md). A composite
FK can prove structural store coherence but cannot express "this series
was ACTIVE, or this fiscal_installation assignment was effective, at
this specific historical instant." This is Stage 6's obligation, inside
the same transaction that locks/resolves the series, verifies
eligibility, allocates the serial, and persists the invoice — exactly
the sequence the owner described. Not weakened into a database
constraint it cannot honestly express.

### 4. Invoice negative-test results

All 4 required scenarios (A–D) plus 2 bonus tests, all **PASS**:

| Scenario | Result |
|---|---|
| A. Store A Sale + Store B InvoiceSeries | Rejected |
| B. Store A Sale + Store A InvoiceSeries | Accepted |
| (bonus) Invoice's own `store_id` disagrees with its sale's store | Rejected |
| (bonus) Invoice cites a terminal from a different store than its own declared `store_id` | Rejected |

### 5. Serial/series uniqueness results

| Scenario | Result |
|---|---|
| C. Duplicate serial within same InvoiceSeries | Rejected (reconfirms the pre-existing `UNIQUE(invoice_series_id, invoice_number)`, unaffected by the schema change) |
| D. Same numeric serial in a different, legitimate series | Permitted (confirms the per-series uniqueness scope was not accidentally tightened) |

The persisted display invoice number cannot contradict the allocated
serial because there is no separate display column: `invoice_number`
**is** the formatted, digits-only, leading-zero-preserving serial
itself (a long-standing Stage 5 design decision, not new to this pass).
`invoice_series.prefix` is applied only at render time. Documented in
constraint-register.md DB-INV-018.

### 6. Final FK/delete-rule counts

**93 foreign keys** (was 89): 1 `CASCADE` (unchanged), 2 `SET NULL`
(unchanged), 74 single-column `RESTRICT` (was 73, +1 for
`invoices.store_id → stores`), 16 composite `NO ACTION` (was 13, +3 for
`invoices`' new coherence FKs). No unexpected `CASCADE` was introduced
— verified directly via `pg_constraint.confdeltype`.

### 7. Final PHPUnit test/assertion count

**40 tests, 57 assertions, 0 failures** (was 34/51 after the first
hardening pass). +6 tests, +6 assertions from the new
`InvoiceContextIntegrityTest.php`.

### 8. Migration round-trip result

**Clean.** Fresh database → `php artisan migrate` (37/37, zero errors)
→ `php artisan migrate:reset` (zero errors) → `php artisan migrate`
(zero errors, identical 41-table schema reproduced) — run a third time,
after this pass's changes, using the same sequence as both prior
hardening-pass verifications.

### 9. Files changed

**New**: `tests/Database/InvoiceContextIntegrityTest.php`.

**Modified**: `database/migrations/2026_01_01_000200_create_sales_table.php`
(added `UNIQUE(store_id, id)`),
`..._000130_create_invoice_series_table.php` (added `UNIQUE(store_id,
id)`), `..._000230_create_invoices_table.php` (added `store_id` column
+ three composite FKs), `app/Models/Invoice.php` (added `store_id` to
`$fillable` + `store()` relationship), `database/factories/InvoiceFactory.php`
(sets `store_id` from the sale it derives from),
`tests/Database/ConstraintValidationTest.php` (added `store_id` to two
existing raw invoice inserts, required by the new NOT NULL column),
`docs/04-database/context-integrity-matrix.md` (Invoice row resolved +
new "Invoice structural coherence" section),
`docs/04-database/constraint-register.md` (DB-INV-063j/063k/063l split,
DB-INV-060/018 updated, CHECK-constraint count corrected 57→59),
`docs/04-database/database-schema.md` (new §6b, §19 counts updated),
`docs/04-database/schema-validation.md` (new §A.3.3, "Invoice
structural coherence negative tests" section, updated counts
throughout), `docs/04-database/stage-5-report.md` (this file, §14/§15/
§18/§20 + this addendum).

Also corrected in passing: a stale CHECK-constraint count (documented
as 57 since the very first Stage 5 submission; the actual, unchanged
live count is 59 — recounted directly via `pg_constraint` while
verifying this pass's structural counts, since neither hardening pass
added or removed any CHECK constraint).

### 10. Final Stage 5 substantive commit hash

**None yet — nothing in this Stage has been committed.** Consistent
with both prior submissions, no commit or tag has been created at any
point. All work described in this addendum exists only in the working
tree, awaiting the owner's review and explicit approval before any
commit or `stage-5-baseline` tag is created.

---

# Hardening Pass Addendum #3 (2026-09-16) — Invoice/Sale Terminal Identity

Answering the owner's third and final 10-item follow-up instruction,
point by point. **Do NOT begin Stage 6.** Per the owner's own framing
("if everything passes... report both hashes" then wait for explicit
tag approval), items 9–10 below describe the commit work performed;
**`stage-5-baseline` itself is still not created** until the owner
explicitly says so in a subsequent message.

### 1. Exact Invoice/Sale terminal constraint

```sql
ALTER TABLE invoices ADD CONSTRAINT invoices_store_terminal_sale_fk
  FOREIGN KEY (store_id, terminal_id, sale_id)
  REFERENCES sales (store_id, terminal_id, id);
```

Requires a new `sales.UNIQUE(store_id, terminal_id, id)` as the
referenced side.

### 2. Weaker FK retained or removed

**Removed, not retained alongside.** Both of the prior pass's composite
FKs — `invoices_store_sale_fk (store_id, sale_id) → sales(store_id,
id)` and `invoices_store_terminal_fk (store_id, terminal_id) →
terminals(store_id, id)` — were dropped. The new three-column FK
strictly subsumes both: it implies the first directly (matching a
superset of columns implies matching the subset), and the second
transitively (via `sales`' own pre-existing `sales_store_terminal_fk`,
which already proves every sale's terminal belongs to its store).
Keeping either narrower FK would have proven nothing the new one
doesn't already prove. `invoices_store_series_fk` (InvoiceSeries store
coherence) was untouched — it protects an independent invariant the
new FK says nothing about.

### 3. Targeted test results

Both required scenarios **PASS**:

| Scenario | Result |
|---|---|
| A. Sale Store A/Terminal 01; Invoice Store A/same Sale/Terminal 02 | **Rejected** |
| B. Sale Store A/Terminal 01; Invoice Store A/same Sale/Terminal 01 | **Accepted** |

All 6 previously-passing Invoice tests (cross-store Sale/InvoiceSeries
rejection and acceptance, duplicate-serial rejection, cross-series
same-serial permission, mismatched-store rejection, cross-store
terminal rejection) were re-run and continue to pass unchanged.

### 4. Full PHPUnit tests/assertions

**42 tests, 59 assertions, 0 failures** (was 40/57 after the second
hardening pass). `tests/Database/InvoiceContextIntegrityTest.php` grew
from 6 to 8 tests.

### 5. Final FK count by delete action

**92 foreign keys** (was 93): 1 `CASCADE` (unchanged), 2 `SET NULL`
(unchanged), 74 single-column `RESTRICT` (unchanged), 15 composite
`NO ACTION` (was 16 — net **−1**: +1 for the new three-column FK, −2
for the two removed narrower FKs). No unexpected `CASCADE` was
introduced.

### 6. Final UNIQUE/CHECK/partial-index counts

- Unique constraints: **20** (was 19, +1 for `sales(store_id,
  terminal_id, id)`).
- CHECK constraints: **59** (unchanged).
- Partial unique indexes: **13** (unchanged).

### 7. Migration/reset/remigration result

**Clean**, all three steps, run a fourth time using the same sequence
as every prior hardening-pass verification: fresh database →
`php artisan migrate` (37/37, zero errors) → `php artisan migrate:reset`
(zero errors) → `php artisan migrate` (zero errors, identical 41-table
schema reproduced).

### 8. Files changed (this pass)

**Modified**: `database/migrations/2026_01_01_000200_create_sales_table.php`
(added `UNIQUE(store_id, terminal_id, id)`),
`..._000230_create_invoices_table.php` (removed two composite FKs,
added one stronger one), `tests/Database/InvoiceContextIntegrityTest.php`
(added `terminalA2` fixture + 2 new tests),
`docs/04-database/context-integrity-matrix.md` (Invoice section
rewritten with the redundancy argument),
`docs/04-database/constraint-register.md` (DB-INV-063j narrowed,
DB-INV-063m added, DB-INV-060 counts updated),
`docs/04-database/database-schema.md` (§6b rewritten, §19 counts
updated), `docs/04-database/schema-validation.md` (new §A.3.4, updated
negative-test table and overall counts), `docs/04-database/stage-5-report.md`
(this file, §14/§15/§18/§20 + this addendum).

### 9. Stage 5 substantive commit hash

### 10. Manifest commit hash

**Both recorded in [PROJECT-MANIFEST.md](../PROJECT-MANIFEST.md)'s
revision log and reported directly to the owner** — following this
project's own established convention (every prior Stage's commit
hashes live in the manifest's Git baseline history table and revision
log, not self-referenced inside the stage's own deliverable documents,
which cannot know their own future commit hash at the time they're
written).
