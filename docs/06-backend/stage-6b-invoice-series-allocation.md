# Stage 6B — Invoice Series Allocation and Transactional Numbering

## Status

**FROZEN — Stage 6B is approved and tagged `stage-6b-baseline`.** Implements
the concurrency-safe allocation of accountable invoice serials against
PostgreSQL, per ADR-004 and invariants.md's Invoice numbering section
(#11–19), plus two implementation-discovered frozen-corpus amendments
(§8/§9 below), applied after owner review. **No Sale finalization, no
controllers, no printing, no catalog CRUD, no InvoiceSnapshot
generation** — those remain Stage 6C and later, per the owner's explicit
scope instruction.

**Retagging history (superseded by the final linearization below).** The
"Not yet retagged" / "all five still unmoved" note that originally stood
here described the state after the third review pass, before any baseline
surgery. Two internal reconciliation attempts followed (documented in
§8/§9), the second of which the owner rejected for a real structural
defect: it produced correct file content but incorrect ancestry
(`stage-2-baseline` still had Stage 3/4/5 as git ancestors). A **full
baseline history linearization** was then performed, rebuilding Stages
1–6B as a strictly ordered, mutually isolated chain — see
`docs/PROJECT-MANIFEST.md`'s Git baseline history and Revision log for
the complete record. Current baseline hashes (post-linearization):
`stage-1-baseline` @ `d93a813`, `stage-2-baseline` @ `5a7382a`,
`stage-3-baseline` @ `78a190c`, `stage-4-baseline` @ `2313a16`,
`stage-5-baseline` @ `4c16ee0`, `stage-6a-baseline` @ `47fa84d`,
`stage-6b-baseline` @ `b335a7a`. The hashes named throughout the rest of
this document (`b7ef8fd`, `c8a8bbe`, `515e2db`, `8c8fe32`, `47baddf`) are
preserved as the historical record of what was reviewed and approved at
each pass — they are superseded tags, not current ones; the manifest is
the authoritative source for current baseline hashes.

**Third review pass, 2026-09-17 — migration upgrade safety.** The
domain direction (counter bootstrap model, FiscalInstallation binding)
was approved in the second pass without further change. The owner
correctly identified that the two new Stage 5 migrations had only ever
been proven against a *fresh* database — an empty `invoice_series`
table trivially satisfies any new CHECK/NOT NULL/backfill logic,
proving nothing about upgrade safety against the already-frozen Stage 5
schema if it already contained rows. This pass hardens both migrations
with explicit legacy-row handling (§8/§9) and adds a fourth range
invariant (`ending_number >= starting_number`, INVSERIES-004) the
second pass didn't surface. No allocator/concurrency code changed.

---

## 1. Implemented components

| Component | File(s) |
|---|---|
| `InvoiceSeriesAllocator` | `app/Services/InvoiceNumbering/InvoiceSeriesAllocator.php` |
| `AllocatedInvoiceNumber` (result DTO) | `app/Services/InvoiceNumbering/AllocatedInvoiceNumber.php` |
| `InvoiceSeriesExhaustedException` | `app/Domain/Exceptions/InvoiceSeriesExhaustedException.php` |
| `InvoiceSeriesResolutionException` | `app/Domain/Exceptions/InvoiceSeriesResolutionException.php` |
| Migration: counter bootstrap CHECK amendment | `database/migrations/2026_09_17_004719_amend_invoice_series_bootstrap_constraint.php` |
| Migration: `fiscal_installation_id` link | `database/migrations/2026_09_17_004738_add_fiscal_installation_to_invoice_series_table.php` |
| `FiscalInstallationFactory` (new — none existed) | `database/factories/FiscalInstallationFactory.php` |

## 2. Frozen sources implemented

- **ADR-004** (Invoice Sequence Concurrency via Row-Level Locking) —
  `SELECT ... FOR UPDATE` on the specific `invoice_series` row, exactly
  as its pseudocode specifies, never a Postgres `SEQUENCE`, `MAX()+1`,
  application mutex, or optimistic `version`-column retry loop (ADR-004
  considered and rejected all four). Unchanged across both review passes.
- **invariants.md #11–19** — no reuse/reassignment/renumbering (#11);
  allocation only during authoritative sale finalization (#12, enforced
  by this class having no other entry point); concurrency-safe,
  transaction-scoped allocation (#13); no gaps from failed attempts
  (#14, "Transaction ownership" below); no administrative rewind (#18,
  the allocator only ever increments); series exhaustion fails safely
  (#19, `InvoiceSeriesExhaustedException`).
- **invariants.md INVSERIES-001/002/003 (new, Stage 6B amendment,
  2026-09-17)** — see §8/§9.
- **error-catalog.md `INVOICE_SERIES_EXHAUSTED` (409)** — reused
  verbatim, not reinvented; `InvoiceSeriesExhaustedException` maps to
  exactly this code.
- **architecture.md's Global Lock Order** — `InvoiceSeries` is acquired
  through the shared `GlobalLockOrder` guard the caller already carries
  from `shift`/`fiscal_day`/`sale`/`sale_item`, so a future Sale
  finalization service composes correctly by construction.

## 3. Allocator design

`InvoiceSeriesAllocator::allocateForFiscalInstallation(string $storeId, string $fiscalInstallationId, GlobalLockOrder $lockOrder): AllocatedInvoiceNumber`
is the only public entry point (renamed from `allocateForStore()` in the
second review pass — see §9). It does, in order, inside whatever
transaction is already open on the connection:

1. `$lockOrder->acquire(LockableResource::InvoiceSeries)` — asserts this
   call is not violating the frozen lock order relative to whatever the
   caller already locked earlier in the same transaction.
2. `SELECT id, current_number, ending_number FROM invoice_series WHERE
   store_id = ? AND fiscal_installation_id = ? AND status = 'ACTIVE'
   FOR UPDATE` — ADR-004's query, extended with the `fiscal_installation_id`
   key §9 adds.
3. Resolves to exactly one row or throws (see "Series resolution").
4. Checks `current_number >= ending_number` **before** computing the
   next serial (see "Series exhaustion" in §8).
5. `UPDATE invoice_series SET current_number = nextSerial, version =
   version + 1 WHERE id = ...` — a single, minimal write.
6. Returns `AllocatedInvoiceNumber { invoiceSeriesId, serial, formattedNumber }`.

No naming collision with a generic `Repository`/`CrudService` pattern
was introduced — the class name mirrors the domain's own aggregate
name (`invoice_series`/`InvoiceSeries`, already used by the Eloquent
model and every frozen document), the same convention `IdempotencyService`
and `FinancialCalculator` already follow.

## 4. Transaction ownership

**The allocator never calls `DB::transaction()`, never commits, and
never rolls back on its own initiative.** It is designed to be called
from inside the caller's own already-open transaction — a future Sale
finalization service, or (in this stage's own tests) a test's own
transaction. This is the direct mechanism behind invariant #14 ("no
gaps from failed attempts"): if anything later in the *caller's*
transaction fails and that transaction rolls back, PostgreSQL reverts
this class's `UPDATE` to `current_number` along with everything else —
the serial this call returned was never actually consumed, and the
next successful transaction receives it. Proven in
`tests/Database/InvoiceSeriesAllocatorTest::test_rollback_does_not_consume_serial`
and, under true multi-process contention, in
`InvoiceSeriesAllocatorConcurrencyTest::test_rollback_under_contention_does_not_skip_a_serial`.
**Unchanged across both review passes**, per the owner's explicit
instruction not to touch the concurrency machinery.

## 5. Lock semantics

`SELECT ... FOR UPDATE` is PostgreSQL's native row lock: a second
transaction requesting the same row blocks at that `SELECT` until the
first commits or rolls back — no application-level mutex, distributed
lock service, or in-memory counter. The allocator acquires no other
lock and holds this one for the shortest possible span (one `SELECT`,
one bounds check, one `UPDATE`). **Unchanged across both review passes.**

## 6. Serial formatting

`InvoiceSeriesAllocator::format(int $serial): string` is a pure
function, independent of database state: zero-pads to a minimum of six
digits, never truncates a longer value, never embeds `series_code`/
`prefix` (matching `invoice.invoice_number`'s own contract — digits
only, `^[0-9]{6,}$`). Rejects non-positive input. **Unchanged across
both review passes.**

| Serial | Formatted |
|---|---|
| 1 | `000001` |
| 42 | `000042` |
| 999999 | `999999` |
| 1000000 | `1000000` |
| `PHP_INT_MAX` | itself, digits only, no scientific notation |

## 7. Rollback semantics

See §4. Additionally: the allocator's `ending_number` pre-check happens
*before* the `UPDATE`, so the ordinary exhaustion path never touches the
database at all — no partial state is ever written for a rejected
allocation. The registered `DatabaseExceptionTranslator` mapping for
`invoice_series_current_number_bounds_check` is a defense-in-depth
backstop only (proven directly, bypassing the allocator, in
`InvoiceSeriesAllocatorTest::test_database_check_constraint_rejects_a_raw_bypass_of_ending_number`)
— it should never fire through the allocator's own normal code path.

## 8. Counter semantics — RESOLVED via a disclosed frozen-corpus amendment (owner review, 2026-09-17, second pass)

**History of this section, for the record:** the first submission
bootstrapped a fresh series *at* `starting_number` and called this
"confirmed by ADR-004" — an overstatement, corrected in the first
review pass to "genuinely an implementation-discovered ambiguity" with
a recommendation to keep that model anyway, based on an exhaustion-
boundary argument. **The owner's second review rejected that
recommendation** on the grounds that it silently skips the store's
configured `starting_number` — a real, avoidable product defect once
Sale finalization exists — and proposed a cleaner model that preserves
ADR-004's increment-then-use mechanic while eliminating the skip. That
model is what this stage now implements.

**Resolved model — `invariants.md` INVSERIES-001, `domain-model.md`
§2.6 "Counter bootstrap semantics":**

- `current_number` continues to mean **the last allocated serial**
  (ADR-004's own "increment `current_number`; use the new value"
  mechanic — unchanged).
- `starting_number` means **the first serial actually issuable** from
  the series.
- A newly configured series must therefore bootstrap at
  `current_number = starting_number - 1`, so its first real allocation
  (`current_number + 1`) yields `starting_number` exactly — never
  skipping it.
- `starting_number >= 1` always. `current_number = 0` may exist only as
  internal pre-allocation counter state for a series whose
  `starting_number = 1` — never as an issued `invoice_number`.

**Schema amendment**
(`database/migrations/2026_09_17_004719_amend_invoice_series_bootstrap_constraint.php`,
disclosed, not editing the frozen
`2026_01_01_000130_create_invoice_series_table.php`):

```sql
ALTER TABLE invoice_series DROP CONSTRAINT invoice_series_current_number_bounds_check;
ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_current_number_bounds_check
  CHECK (current_number >= starting_number - 1 AND (ending_number IS NULL OR current_number <= ending_number));
ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_starting_number_positive_check
  CHECK (starting_number >= 1);
ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_ending_number_range_check
  CHECK (ending_number IS NULL OR ending_number >= starting_number);
```

**Final counter CHECK constraints, all four together:**

| Constraint | Rule |
|---|---|
| `invoice_series_starting_number_positive_check` | `starting_number >= 1` |
| `invoice_series_current_number_bounds_check` (lower half) | `current_number >= starting_number - 1` |
| `invoice_series_current_number_bounds_check` (upper half) | `ending_number IS NULL OR current_number <= ending_number` |
| `invoice_series_ending_number_range_check` (new, third pass) | `ending_number IS NULL OR ending_number >= starting_number` |

The `starting_number >= 1` floor is new (second pass) and was not
previously enforced anywhere (verified directly against the original
migration — `starting_number` had no lower-bound CHECK of its own).
Without it, `current_number >= starting_number - 1` would tolerate a
degenerate negative bootstrap value if `starting_number` were ever
configured as `0` or less; `0` is never an issuable invoice serial, so
this closes that gap while making the new bound meaningful.

**`invoice_series_ending_number_range_check` is new in this (third)
pass** — invariants.md INVSERIES-004. Without it, a misconfiguration
such as `starting_number = 100, ending_number = 99` (a backwards,
zero-length range) would only ever be observable by the allocator as
"already exhausted" rather than being rejected outright as invalid
configuration at the point of creation. Proven directly in
`InvoiceSeriesAllocatorTest::test_database_check_constraint_rejects_ending_number_below_starting_number`
(rejected) and
`test_ending_number_equal_to_starting_number_is_a_valid_single_serial_range`
(a single-serial range is legitimate, not backwards).

### Upgrade safety (owner's third review pass)

**A fresh-database `migrate:fresh` round-trip alone does not prove this
migration is safe** — an empty `invoice_series` table trivially
satisfies any new CHECK, since there are no existing rows to violate
it. The real risk is the already-frozen Stage 5 schema **already
containing rows** written under the old convention
(`current_number = starting_number` for an unused series) before this
migration runs. Changing only the CHECK would not reinterpret those
rows — an old unused series would silently keep skipping its
`starting_number` forever, the exact defect this amendment exists to
fix.

The migration (`2026_09_17_004719_amend_invoice_series_bootstrap_constraint.php`)
therefore performs an explicit, bounded data fixup **before** tightening
the schema, for every existing `invoice_series` row:

- **Zero referencing invoices** — the series has never actually issued
  anything, regardless of its stored `current_number`. Unconditionally
  reset to the new bootstrap baseline: `current_number = starting_number - 1`.
- **One or more referencing invoices** — `current_number` already meant
  "last allocated" under the *old* model too for a series that had
  actually issued something (the ambiguity was only ever about the
  *unused* bootstrap value). Verified against
  `MAX(invoice_number::bigint)` for that series: a match means no
  change is needed; a mismatch means the row's history is unexplained,
  and the migration **aborts with a `RuntimeException`** naming the
  series rather than guessing — Laravel's transactional-DDL migration
  wrapper (PostgreSQL supports transactional DDL) rolls the entire
  migration back atomically, schema changes included.

Proven in `tests/Database/InvoiceSeriesCounterUpgradeTest.php` (5
tests) by calling the **real migration's own `up()`/`down()` methods**
directly against a reconstructed pre-amendment table shape — not a
reimplementation of its logic: unused legacy series with
`starting_number = 1` and `starting_number = 100` are both correctly
reinterpreted; a used series consistent with its own invoice history is
left untouched; a used series inconsistent with its invoice history
aborts the migration; a database with no `invoice_series` rows at all
is a no-op.

**Exhaustion, re-derived under this model (owner instruction §3):**
checked *before* computing the next serial, not after:

```
if (ending_number is not null and current_number >= ending_number):
    throw InvoiceSeriesExhaustedException
next_serial = current_number + 1
persist current_number = next_serial
return next_serial
```

`current_number == ending_number` unambiguously means "nothing left" —
the series' last issuable serial (`ending_number` itself) was already
allocated when `current_number` reached that value. This remains fully
representable within the CHECK's bounds (`current_number` never needs
to exceed `ending_number` to signal exhaustion), so the pigeonhole
argument from the first review pass no longer applies: the corrected
bootstrap floor (`starting_number - 1`) absorbs the "zero issued" state
that the original CHECK's tighter lower bound couldn't represent,
without needing to relax the upper bound at all.

**`invoice_series.status` was not touched or overloaded** (owner
instruction §4): confirmed directly against the frozen migration —
`status` is `CHECK (status IN ('ACTIVE','CLOSED'))`, exactly two values,
no `EXHAUSTED` value anywhere in the frozen corpus (searched
exhaustively; `INVOICE_SERIES_EXHAUSTED` exists only as an
error-catalog.md API code, never as a status). Exhaustion remains
represented purely by the `current_number`/`ending_number` comparison;
no automatic status transition was added.

**Regulatory note (owner instruction §18):** RMO 24-2023 requires a
running serial number of at least six digits; it does not define what
TindaFlow's internal `starting_number`/`current_number` columns must
mean. The bootstrap correction here is a TindaFlow design decision, not
a BIR requirement — stated accurately per the owner's explicit
instruction not to attribute it to the regulation.

## 9. Series resolution — RESOLVED via a disclosed frozen-corpus amendment (owner review, 2026-09-17, second pass)

**The first review pass correctly identified this as unresolved and
stopped short of guessing a fix.** The owner's second review confirmed
the missing relationship and specified the resolution: `invoice_series`
now belongs to `fiscal_installation`, not to a bare `store` alone.

**Why `fiscal_installation` (owner instruction §7/§8):** the frozen
model already separates `terminal` from `fiscal_installation`
specifically to support both deployment shapes without a further
schema change (`terminal_fiscal_installation`'s effective-dated join —
domain-model.md §2.1, erd.md's own cardinality note). `invoice.
fiscal_installation_id` already records which installation was in
effect at issuance. `invoice_series` was the one place in this same
identity chain missing a link — `store_id` alone gave the allocator no
way to choose between multiple `invoice_series` rows
`constraint-register.md`'s `DB-INV-018` already confirmed a store may
legitimately have (exact wording: `UNIQUE(invoice_series_id,
invoice_number)` is scoped per-series "not global, not per-store alone,
**since two series can belong to one store**").

**Resolved model — `invariants.md` INVSERIES-002/003, `domain-model.md`
§2.6 "Series-to-installation binding":**

- `invoice_series.fiscal_installation_id` is required (never null).
  `store_id` is **retained** alongside it (not removed — owner
  instruction §7) for coherence/reporting, enforced by a composite FK:
  `(invoice_series.store_id, invoice_series.fiscal_installation_id) →
  fiscal_installations(store_id, id)` — the same disclosed-
  denormalization pattern already used for
  `terminal_fiscal_installation`/`invoices`.
- **At most one `ACTIVE` `invoice_series` per `fiscal_installation`**,
  database-enforced via a new partial unique index
  (`invoice_series_one_active_per_installation`) — mirroring the
  identical "current/active singleton, history preserved" pattern this
  schema already applies to `shift`/`fiscal_day`/
  `terminal_fiscal_installation`. A store may still have multiple
  `invoice_series` rows (`DB-INV-018` is unaffected), through multiple
  or historical `fiscal_installation`s, but never more than one
  simultaneously `ACTIVE` row per installation.
- **No "one ACTIVE series per store" policy was retained or
  reintroduced** (owner instruction §5) — the prior application-level
  guard for that case is gone; resolution is keyed by
  `(store_id, fiscal_installation_id)`, and two simultaneously-`ACTIVE`
  series under one store (through two installations) is now a normal,
  tested, successful case (`InvoiceSeriesAllocatorTest::test_two_active_series_for_different_fiscal_installations_of_the_same_store_are_both_permitted`,
  `InvoiceSeriesAllocatorConcurrencyTest::test_concurrent_different_fiscal_installations_of_the_same_store_allocate_independently`).
- **Resolution chain for a future Sale finalization caller**: `terminal`
  → (via `terminal_fiscal_installation`'s effective-dated mapping,
  resolved at `sold_at`) → the terminal's currently-effective
  `fiscal_installation` → its one `ACTIVE` `invoice_series` → allocate.
  The browser/cashier never selects a `fiscal_installation` or
  `invoice_series` directly (owner instruction §12) — this stage does
  not implement that resolution step itself (it is Stage 6C's job, once
  a terminal/session context exists to resolve from); it establishes
  the allocator boundary (`allocateForFiscalInstallation`) that step
  will call into.

**Schema amendment**
(`database/migrations/2026_09_17_004738_add_fiscal_installation_to_invoice_series_table.php`,
disclosed, additive):

```sql
ALTER TABLE invoice_series ADD COLUMN fiscal_installation_id uuid NOT NULL REFERENCES fiscal_installations(id);
ALTER TABLE invoice_series ADD CONSTRAINT invoice_series_store_installation_fk
  FOREIGN KEY (store_id, fiscal_installation_id) REFERENCES fiscal_installations (store_id, id);
CREATE UNIQUE INDEX invoice_series_one_active_per_installation
  ON invoice_series (fiscal_installation_id) WHERE status = 'ACTIVE';
```

**`InvoiceSeriesResolutionException` retained, re-scoped** (owner
instruction §5's "do not turn multiple rows into a generic
configuration error" is honored: with the partial unique index now
database-enforcing at most one `ACTIVE` row per installation, "more
than one" is no longer reachable through normal operation — the
allocator still asserts it defensively, but it now indicates the
partial unique index was bypassed or is missing (a database invariant
violation to investigate directly), not an ordinary runtime condition).
"Zero `ACTIVE` series for this installation" remains a legitimate
setup-defect case. **Final resolver behavior**: `SELECT ... WHERE
store_id = ? AND fiscal_installation_id = ? AND status = 'ACTIVE' FOR
UPDATE` — one row → allocate; zero rows → `InvoiceSeriesResolutionException`;
more than one row → the same exception, now reporting a database
invariant violation rather than a normal application state. Store-only
resolution is not used anywhere in this class.

### Upgrade safety (owner's third review pass)

**Same concern as §8**: a fresh-database round-trip proves nothing
about a store that already has `invoice_series` rows when this
migration runs. `2026_09_17_004738_add_fiscal_installation_to_invoice_series_table.php`
adds `fiscal_installation_id` as **nullable first**, then performs an
explicit backfill/validation pass before tightening it to `NOT NULL`
and creating the partial unique index:

1. **Backfill, per store with existing `invoice_series` rows**: count
   that store's `fiscal_installation` rows.
   - **Exactly one** — safe, deterministic backfill: every one of that
     store's `invoice_series` rows is assigned that single installation.
   - **Zero, or more than one** — the migration cannot infer which
     installation each series belongs to without guessing. It **aborts
     with a `RuntimeException`** naming the store, rather than picking
     one arbitrarily or defaulting to the first. (Per the owner's own
     stated preference for "deterministic automatic backfill only when
     exactly one valid FiscalInstallation exists for the Store;
     otherwise abort" — the third option, "require a database reset,"
     was not needed since a correct automatic path exists for the
     unambiguous case.)
2. **Multiple-`ACTIVE` conflict check, after backfill, before creating
   the partial unique index**: groups the (now-backfilled)
   `fiscal_installation_id`s and checks whether any would end up with
   more than one simultaneously `ACTIVE` `invoice_series` — a
   legitimate possibility under the *old*, store-only-scoped model this
   amendment replaces. If found, the migration **aborts with a
   `RuntimeException`** naming the conflicting installation(s), rather
   than letting `CREATE UNIQUE INDEX` fail with a raw constraint error
   or silently closing one of the conflicting series to make the index
   creation succeed. Resolving which series stays `ACTIVE` is an
   explicit administrative decision this migration does not make on its
   own.

Proven in `tests/Database/InvoiceSeriesFiscalInstallationUpgradeTest.php`
(6 tests) by calling the **real migration's own `up()`/`down()`
methods** directly against a reconstructed pre-amendment table shape:
single-installation backfill succeeds deterministically; a store with
two installations and an existing series aborts; a store with zero
installations and an existing series aborts; two legacy `ACTIVE` series
under the same installation abort (naming the installation, not
auto-selecting a winner); one `ACTIVE` + one `CLOSED` legacy series
under the same installation backfill successfully (this is the
legitimate, retained-history case INVSERIES-003 explicitly permits); a
database with no `invoice_series` rows at all is a no-op.

**API/OpenAPI (Stage 4) — no change required** (owner instruction §10):
checkout requests never accepted `invoice_series_id`/
`fiscal_installation_id` from the client and still don't; series
selection remains entirely server-authoritative. No Stage 4 fiscal-
configuration resource currently exposes `invoice_series` fields that
would need a `fiscal_installation_id` addition — reviewed, none found.

## 10. Concurrency-test evidence (re-run after all amendments, owner instruction §10/§17)

**Database-only (single connection, `PostgresSchemaTestCase`)** —
`tests/Database/InvoiceSeriesAllocatorTest.php`, **22 tests**, all
against real PostgreSQL 17: fresh series' first allocation is
`starting_number` exactly (both `starting_number = 1` and
`starting_number = 100` proven); sequential allocation from a
partially-used series; rollback does not consume a serial (both the
plain case and the fresh-series `starting_number` case); no-active-
series rejected; `CLOSED` series correctly ignored when an `ACTIVE` one
also exists; **two `ACTIVE` series for the same fiscal installation
rejected by the database** (the new partial unique index); **two
`ACTIVE` series for two different fiscal installations of the same
store both permitted**; allocating the series' exact final valid serial
succeeds; allocation at `current_number == ending_number` is exhausted
without advancing the counter; `starting_number < 1` rejected;
`ending_number < starting_number` rejected (new, INVSERIES-004);
`ending_number == starting_number` accepted as a valid single-serial
range; bootstrap `current_number = starting_number - 1` accepted for a
non-1 `starting_number`; `current_number` below that floor rejected for
a non-1 `starting_number`; the amended CHECK backstop rejects a raw
bypass below the bootstrap floor; the `ending_number` CHECK backstop
still rejects a raw bypass; `UNIQUE(invoice_series_id, invoice_number)`
rejects a duplicate within one series but permits the same formatted
number across two different series; `version` advances on every
allocation.

**Migration upgrade safety (new this pass)** —
`tests/Database/InvoiceSeriesCounterUpgradeTest.php` (5 tests) and
`tests/Database/InvoiceSeriesFiscalInstallationUpgradeTest.php` (6
tests), both calling the real migrations' own `up()`/`down()` methods
against reconstructed pre-amendment table shapes — see §8/§9 for the
full scenario list.

**True multi-process (`Process::start()`, independent connections)** —
`tests/Database/InvoiceSeriesAllocatorConcurrencyTest.php`, 3 tests, run
4 times total during this pass with no flakiness observed:

- `test_ten_workers_against_one_series_produce_unique_contiguous_serials`
  — 10 independent OS processes race the same series (run 3 times
  inside this one test method); every run: 10 committed, 10 distinct
  serials, **exact contiguous range 1..10** (the configured
  `starting_number`, never skipped — corrected from the prior pass's
  2..11), correct final counter.
- `test_concurrent_different_fiscal_installations_of_the_same_store_allocate_independently`
  — 5 workers against fiscal installation A's series interleaved with 5
  workers against installation B's series (both under the SAME store,
  the actual frozen-model shape per `DB-INV-018` — corrected from the
  prior pass's two-separate-stores fixture) in one barrier release; each
  installation's allocated serials are independently correct and
  contiguous, no cross-contamination, no `InvoiceSeriesResolutionException`
  merely because the store has two active series.
- `test_rollback_under_contention_does_not_skip_a_serial` — worker A
  locks, allocates, holds the lock 400ms, then rolls back; worker B
  (released at the same barrier) genuinely blocks on A's row lock and,
  once A's rollback releases it, receives the exact serial A abandoned.

## 11. Known limitations (disclosed, not silently skipped)

- **No commit-aware logging yet.** This class emits no log of its own
  for a successful allocation, since it has no way to know whether the
  caller's transaction will ultimately commit. Real commit-aware
  observability belongs with whichever Stage 6C+ caller can hook a
  post-commit callback (e.g. `DB::afterCommit()`).
- **Deadlock/serialization-failure handling is not specially retried**,
  per the owner's explicit instruction against broad automatic retries
  that hide deadlocks. At V1's expected scale this is not expected to
  occur in practice — ADR-004 itself already accepts this trade-off.
- **Terminal → fiscal installation resolution is not implemented by
  this stage** (owner instruction §12/§13) — `allocateForFiscalInstallation()`
  takes `fiscalInstallationId` as a caller-supplied, already-resolved
  value; resolving it from a terminal's effective
  `terminal_fiscal_installation` mapping at `sold_at` is Stage 6C's
  responsibility once a real checkout/terminal-session context exists
  to resolve from. This stage does not "independently resolve unrelated
  checkout state," per that same instruction.

## 12. Frozen-corpus amendments applied this pass

**All items from the prior passes' "open questions" are now resolved
and hardened** (not merely proposed) at the file/migration level
described in §8/§9, pending the owner's review before any baseline is
retagged:

1. **Counter bootstrap semantics** — `domain-model.md` §2.6,
   `invariants.md` INVSERIES-001/004, one migration (bootstrap CHECK +
   `starting_number >= 1` floor + `ending_number >= starting_number`
   floor + legacy-row data fixup with abort-on-ambiguity).
2. **InvoiceSeries-to-FiscalInstallation binding** — `domain-model.md`
   §2.6, `invariants.md` INVSERIES-002/003, `erd.md` (new relationship +
   column), one migration (`fiscal_installation_id` + composite FK +
   partial unique index + legacy-row backfill with abort-on-ambiguity),
   `InvoiceSeriesFactory`/`FiscalInstallationFactory` (new)
   updated/added accordingly.

**Files changed this (third) pass**: both migrations rewritten with
upgrade-safety logic (no filename change — same two migrations from the
second pass, hardened in place, since neither had been committed yet);
two new test files
(`tests/Database/InvoiceSeriesCounterUpgradeTest.php`,
`tests/Database/InvoiceSeriesFiscalInstallationUpgradeTest.php`); four
new range-boundary tests added to `InvoiceSeriesAllocatorTest.php`;
`invariants.md` (INVSERIES-003 reworded, INVSERIES-004 added);
`InvoiceSeriesResolutionException` (ambiguous-case message strengthened
to "database invariant violation"). No allocator, DTO, or
concurrency-test code changed.

**Stage 3 (architecture.md, ADRs) was not modified** — ADR-004's own
decision (row-level locking) is unchanged; only the schema/domain layer
the allocator resolves against was amended. **Stage 4 (openapi.yaml)
was reviewed and found to need no change** (§9). **Stage 6A code was
not modified.**

## 13. Baseline governance, including Stage 6A reconciliation (owner instruction §11)

**No baseline tag has been moved.** The amendment files described above
remain uncommitted, prepared for review.

**The reconciliation concern, stated precisely:** `stage-6a-baseline`
(`47baddf`) was frozen against the Stage 2/5 corpus as it stood *before*
this Stage 6B amendment. Stage 6A's own code needs no change — nothing
in `FinancialCalculator`, `IdempotencyService`, `GlobalLockOrder`, or
the domain exception hierarchy touches `invoice_series`. But once the
Stage 2/5 amendment commits land and (eventually) `stage-2-baseline`/
`stage-5-baseline` move forward again, `stage-6a-baseline`'s own tree
will still reflect the *pre-amendment* `invoice_series` schema/domain
text — a reader following `stage-6a-baseline` alone would see a
domain-model.md/erd.md that predates the counter-bootstrap and
FiscalInstallation-binding correction, even though nothing about Stage
6A's own deliverables is stale or wrong.

**Two options, as requested — recommendation is (B):**

- **(A) Clean local-history rewrite/replay** — since nothing is
  published, the Stage 2/5 amendment commits could in principle be
  inserted *before* Stage 6A's own commit in history (a rebase/replay),
  so `stage-6a-baseline` would then sit on top of the amended corpus
  from the start, exactly as if the amendment had been discovered
  before Stage 6A was frozen. **Not recommended**: this rewrites an
  already-tagged, already-reported-on commit (`stage-6a-baseline` was
  explicitly approved and frozen by the owner two turns ago) for a
  documentation-accuracy concern, not a content defect — a materially
  different situation from the one prior history rewrite this project
  did perform (the ADR-006/`schema_version:2` mistake, where the
  *content* itself was wrong). Rewriting a correctly-approved baseline
  to relocate it in history risks exactly the kind of "silently modify
  a frozen tag" the owner has consistently guarded against, for a
  problem that has a much smaller fix available.
- **(B) Disclosed amendment commits, in the existing linear order, plus
  one small reconciliation note** — the Stage 2 amendment, Stage 5
  amendment, and Stage 6B substantive commit land as new commits after
  `47baddf`, exactly as the Stage 2/4/5 NON_VAT amendment did after
  `8506fa8`. `stage-6a-baseline` stays exactly where it is (Stage 6A's
  own commit, unchanged, still correctly describing Stage 6A's own
  deliverables). The **only** addition is a short, explicit note in
  `PROJECT-MANIFEST.md` at the point Stage 6B is frozen: `stage-6a-baseline`
  represents Stage 6A's own frozen state *as evaluated against* the
  Stage 2/5 corpus *before* this amendment; the amendment was discovered
  by Stage 6A's own implementation *after* Stage 6A itself was approved,
  which is why it appears downstream in history rather than folded into
  Stage 6A's own commit. This is the same pattern the manifest already
  uses to explain `stage-4-baseline`/`stage-5-baseline`'s own
  non-trivial relationship to each other — no new mechanism, just an
  explicit sentence.

**Recommended: (B).** It requires no destructive git operation, matches
every precedent this project has already established for exactly this
kind of "a later stage's implementation revealed an earlier stage's
gap" situation, and fully addresses the owner's actual concern (a
reader must not be misled about what `stage-6a-baseline` does and
doesn't cover) without the risk profile of rewriting an approved,
already-reported-on tag.
