# Stage 6A — Transaction Foundation

## Status

DRAFT — Stage 6A, awaiting owner review. Implements only the backend
primitives every financially sensitive Stage 6B–6E operation will
depend on: `Money`/`Quantity` value objects, the `FinancialCalculator`
foundation, canonical request hashing, the idempotency execution/replay
service, global lock order enforcement, and a domain exception
hierarchy mapped to the frozen error catalog. **No controllers, no Sale
finalization, no catalog CRUD** — those are Stage 6C and later, per the
owner's explicit scope instruction.

**2026-09-17 — Hardening pass.** The owner conditionally approved the
original submission and identified three items requiring harder proof
before approval: (1) the concurrent-idempotency acceptance criterion was
asserted but not actually exercised with two independent database
connections; (2) one regression fixture is not sufficient proof against
premature intermediate rounding; (3) `NON_VAT` was silently excluded
from `FinancialCalculator`'s tax buckets rather than being escalated as
the frozen-corpus gap it actually is. All three are resolved below —
see §4 (new `IdempotencyConcurrencyTest`, using two real OS processes),
§4a (five new adversarial precision tests, each proving a naive
early-rounding shortcut would produce a different, wrong result), and
the new §7 (`NON_VAT` frozen-corpus contradiction, formally escalated
rather than guessed around). `CanonicalRequestHasher` gained 3 more
tests and `IdempotencyService` now enforces the 64-character SHA-256
hex invariant itself rather than relying on `CHAR(64)`'s padding/trim
behavior.

---

## 1. Implemented components

| Component | File(s) |
|---|---|
| Money value object | `app/Domain/Money.php` |
| Quantity value object | `app/Domain/Quantity.php` |
| FinancialCalculator + collaborators | `app/Domain/Financial/{FinancialCalculator,DiscountAllocator,TaxCalculator}.php` + 5 DTOs (`DiscountAllocationLine`, `SaleLineInput`, `SaleLineResult`, `SaleCalculationResult`, `TaxDecomposition`, `TaxAllocationLine`) |
| Canonical request hashing | `app/Services/Idempotency/CanonicalRequestHasher.php` |
| Idempotency execution/replay service | `app/Services/Idempotency/{IdempotencyService,IdempotencyOperationType,OperationOutcome,IdempotencyResult}.php` |
| Database exception translator | `app/Services/Idempotency/DatabaseExceptionTranslator.php` |
| Global lock order enforcement | `app/Support/{GlobalLockOrder,LockableResource}.php` |
| Domain exception hierarchy | `app/Domain/Exceptions/{DomainException,IdempotencyKeyReusedException,ShiftNotOpenException,FiscalDayClosedException,SaleNotVoidableException,RefundExceedsRemainingQuantityException,RefundExceedsRemainingAmountException,ConcurrencyConflictException}.php` |
| Tests | `tests/Unit/Domain/{MoneyTest,QuantityTest}.php`, `tests/Unit/Domain/Financial/{FinancialCalculatorTest,AdversarialPrecisionTest}.php`, `tests/Unit/Services/CanonicalRequestHasherTest.php`, `tests/Unit/Support/GlobalLockOrderTest.php`, `tests/Database/{IdempotencyServiceTest,IdempotencyConcurrencyTest,DatabaseExceptionTranslatorTest}.php`, `tests/Database/support/idempotency_race_worker.php` (standalone worker process, not a PHPUnit test itself) |

---

## 2. Frozen sources implemented

- **Money/Quantity**: domain-model.md §3.1/§3.2, Stage 5 database-schema.md §2 (NUMERIC(12,2)/NUMERIC(10,3) storage this code must produce values compatible with).
- **FinancialCalculator**: ADR-012 (one authoritative calculator, no duplicated logic), domain-model.md §2.7/§2.7a (gross line, Deterministic Proportional Allocation, sum-then-decompose tax policy), invariants.md DISC-001 through DISC-006.
- **Canonical request hashing**: Stage 5 instruction §40, api-design.md §5 (`request_id` excluded, `idempotency_key` itself excluded).
- **Idempotency service**: ADR-010 (generalized behavior matrix), Stage 5 database-schema.md §14 (the transaction-boundary design this service now actually implements in code, not just schema).
- **Global lock order**: architecture.md's "Global lock order" section (the `shift → fiscal_day → sale → sale_item (ascending) → invoice_series` table).
- **Domain exceptions**: error-catalog.md's domain error codes and their fixed HTTP status mapping.

---

## 3. Important design decisions

- **bcmath, not a new Composer dependency.** domain-model.md §3.1 suggests "PHP bcmath/brick/money"; per the Laravel Boost guideline against changing dependencies without approval, this implementation uses PHP's built-in `bcmath` extension (confirmed present: `php -m` lists it) rather than adding `brick/money`. No new package was added.
- **Money exposes no generic multiply/divide.** Only two multiplication-shaped operations exist, each a single named materialization point from the frozen model: `multiplyByQuantity()` (gross line amount) and `allocate()` (the largest-remainder algorithm, reused verbatim for both discount and tax allocation per domain-model.md §2.7a's own instruction that it's "the same procedure"). This directly satisfies "do not silently round at arbitrary call sites" — every rounding call site is named and traceable to a frozen materialization point.
- **`Money::percentageOf()` was added during the hardening pass** as the third and last named materialization point (point 2: a percentage-based line discount, "computed from a percentage and rounded, at the point it's applied"). Before this pass, `FinancialCalculator`'s public API only accepted an already-computed `Money` line discount, meaning a percentage-discount computation happening *outside* this component would have silently violated ADR-012 ("every write path... calls into this component; none reimplements rounding... locally"). This closes that gap the same way `multiplyByQuantity()` already closed it for gross line calculation: one intermediate `bcmul` at >=6dp, one `roundHalfUp()` call, no other rounding.
- **`Money::allocate()` is the generic largest-remainder primitive; `DiscountAllocator`/`TaxCalculator` are thin business-rule wrappers.** `DiscountAllocator` decides *which* lines are eligible and what basis to use (`gross_line_amount − line_discount_amount`); `TaxCalculator` decides the VATABLE-only scope and the `net_line_amount` basis. Neither reimplements the allocation math itself — this is the concrete form ADR-012's "one place to test, one place to fix" takes in code.
- **Tie-breaking is delivered by PHP's stable sort, not custom comparison logic.** PHP's `usort()` has been guaranteed stable since PHP 8.0. `Money::allocate()` requires callers to supply weights in ascending line_number order; sorting by descending remainder then preserves that order for exact ties, which *is* DISC-005's "ties broken by ascending line_number" rule — no separate tie-break comparator was written or needed.
- **`NON_VAT` is rejected outright, not bucketed.** §7 below escalates this as a formal frozen-corpus contradiction rather than a design decision this component is entitled to make. `FinancialCalculator::calculateSale()` now throws `InvalidArgumentException` immediately if any line is classified `NON_VAT`, so the gap cannot ship into Stage 6C unnoticed by silently picking a bucket.
- **Idempotency's concurrent-duplicate recovery is proven with two real, independent OS processes and PostgreSQL connections** — not simulated inside one connection/transaction. `IdempotencyConcurrencyTest` spawns two genuinely separate PHP processes (via Laravel's `Process` facade), each bootstrapping its own copy of the application and its own database connection, synchronized at a file-based barrier so both call `IdempotencyService::execute()` as close to simultaneously as two OS processes can get. See §4a below for the full design and results — this closes what was originally disclosed as a Stage 8 deferral.
- **`GlobalLockOrder` is an in-memory sequence guard, not a lock manager.** It does not itself call `SELECT ... FOR UPDATE` — Stage 6B/6C's services will do that against real Eloquent/query-builder calls. `GlobalLockOrder::acquire()` is a cheap assertion a service calls immediately alongside each real lock acquisition, so a future coding mistake that reorders two lock acquisitions fails a test immediately instead of risking a production deadlock. This is deliberately lightweight per the instruction not to over-build Stage 6A's primitives into full command implementations.
- **`DatabaseExceptionTranslator` maps by SQLSTATE class first, specific constraint name second.** A `registerConstraint()` extension point lets Stage 6B+ services register precise, service-specific mappings (e.g., "this exact constraint means `SALE_ALREADY_VOIDED`") without this shared class needing to know about every constraint in the schema up front. Unmatched unique/foreign-key/check violations fall back to the honest, generic `ConcurrencyConflictException` rather than inventing a specific meaning this class doesn't have enough context to assign correctly.
- **No `BaseService`/`BaseRepository`.** Every class above is a concrete, single-purpose collaborator; DTOs (`SaleLineInput`, etc.) are plain `readonly` classes, not Eloquent models, so `FinancialCalculator` stays decoupled from persistence exactly as ADR-012 requires ("controllers persist its output, they don't hand it a model to mutate").

---

## 4. Test evidence

**128 tests total, 265 assertions, 0 failures** (was 115/200 before this
hardening pass), split across two runners:

- `php artisan test` (default suite — pure unit tests, no database):
  **72 tests, 144 assertions** (was 63/113 — +9 tests/+31 assertions:
  `AdversarialPrecisionTest` ×5, 3 new `CanonicalRequestHasherTest`
  cases, 1 new `FinancialCalculatorTest` case for the `NON_VAT`
  rejection).
- `php artisan test tests/Database` (PostgreSQL-backed integration
  tests, includes all of Stage 5's suite plus this stage's additions):
  **56 tests, 121 assertions** (was 52/87 — +4 tests/+34 assertions: 3
  new `IdempotencyConcurrencyTest` cases using real multi-process
  concurrency, 1 new hash-format-validation test in
  `IdempotencyServiceTest`).

### 4a. True concurrent idempotency (owner hardening pass)

**Design.** `tests/Database/IdempotencyConcurrencyTest.php` does *not*
extend the shared `PostgresSchemaTestCase` used elsewhere in this
codebase: that base wraps every test in an uncommitted transaction
rolled back in `tearDown()`, which would make its fixtures invisible to
separate worker processes (different connections, different
transactions — ordinary MVCC visibility rules). Instead:

1. Fixtures (`store`, `terminal`) are inserted and **committed**
   normally against a dedicated `tindaflow_concurrency_test` database.
2. Two genuinely separate PHP CLI processes are spawned via Laravel's
   `Process::start()` (Symfony Process under the hood), each running
   `tests/Database/support/idempotency_race_worker.php` — a standalone
   script that boots its own full copy of the Laravel application and
   opens its own independent PDO connection to PostgreSQL.
3. Each worker writes a "ready" file, then busy-waits (polling every
   500µs) for a shared "go" file. The main test process waits until
   **both** ready files exist before creating the "go" file — this is
   the barrier: neither worker can start racing until both are already
   alive and waiting, so the two `IdempotencyService::execute()` calls
   happen as close to simultaneously as two OS processes can get.
4. Each worker's protected operation inserts a row into a dedicated
   `idempotency_race_mutations` table (test-only infrastructure, not
   part of the frozen schema) — this is how the test proves "the
   business callback executed exactly once" **from outside either
   process's own memory**, by counting rows in the database afterward,
   not by trusting an in-process counter only one side could see.
5. Each worker writes its own outcome (success + result, or the
   exception class/message) to its own output file; the main process
   reads both after both processes exit.

**PostgreSQL behavior this test relies on and explicitly documents** (in
the test class's own docblock, not just here):
- **UNIQUE constraint**: `(terminal_id, idempotency_key)` cannot be
  satisfied by two committed rows simultaneously.
- **Transaction visibility (READ COMMITTED, Postgres's default)**: a
  concurrent transaction cannot see another's uncommitted `INSERT` at
  all — so both workers' fast-path "is there already a COMPLETED row?"
  checks legitimately see nothing and both proceed to attempt the
  reservation `INSERT`.
- **Blocking/retry behavior**: when two transactions concurrently
  attempt to `INSERT` conflicting rows on the same unique index,
  PostgreSQL blocks the second until the first's transaction resolves —
  it does not immediately error. If the first commits, the second's
  blocked `INSERT` then raises a unique-violation; if the first rolls
  back, the second's blocked `INSERT` succeeds normally, as if no race
  had occurred.
- **Exception translation**: `IdempotencyService::execute()` catches
  exactly that unique-violation `QueryException` and re-queries for the
  now-committed winner's row, rather than surfacing a raw database error
  to the loser.

**Results — three scenarios, all passing, re-run 5 times consecutively
with identical outcomes (no flakiness observed):**

| Scenario | Outcome |
|---|---|
| Same terminal, same key, same hash, simultaneous | Business mutation executed **exactly once** (verified by counting `idempotency_race_mutations` rows); exactly one `COMPLETED` `idempotency_records` row; both workers report the identical `result_resource_id`; exactly one worker's response is `replayed=false` (the real execution) and the other `replayed=true` (the replay) |
| Same terminal, same key, **conflicting** hashes, simultaneous | Business mutation executed **exactly once**; exactly one `COMPLETED` row, carrying the winning hash; the winner receives a fresh (`replayed=false`) result; the loser receives `IdempotencyKeyReusedException` and its business mutation **never runs** — verified by asserting the mutation count stays at 1 regardless of which side won |
| Different terminals, same UUID key, simultaneous | Both workers execute independently — 2 mutations, 2 distinct results, neither replayed — confirming terminal-scoping holds even under genuine concurrency, not just sequentially |

### 4b. Financial tests (§14 of the owner's original instruction)

| Required scenario | Test |
|---|---|
| Simple line calculation | `test_simple_single_line_vatable_sale` |
| Fractional quantity calculation | `test_fractional_quantity_line` |
| Line discount | `test_line_discount_reduces_net_line_amount_independent_of_order_discount` |
| Order discount allocation | `test_order_discount_allocation_residual_of_one_centavo` |
| Allocation residual of one centavo | same test — 3-way split of ₱1.00 |
| Allocation tie broken by line_number | `test_order_discount_tie_broken_by_ascending_line_number` + the frozen fixture regression below |
| VATable calculation | `test_simple_single_line_vatable_sale` |
| VAT-exempt line | `test_vat_exempt_line_has_no_tax` |
| Zero-rated line | `test_zero_rated_line_has_no_tax` |
| Mixed-tax basket | `test_frozen_mixed_tax_basket_regression_fixture` |
| Grand total exact reconciliation | `test_grand_total_reconciles_exactly_disc006` + `reconcileGrandTotal()` asserted in every test above |
| No binary floating-point artifacts | `MoneyTest::test_no_floating_point_artifacts_on_repeated_addition` (10 × ₱0.10 = exactly ₱1.00, not IEEE-754's 0.9999999999999999) |

**The frozen Stage 4 regression fixture** —
`docs/05-api/examples/checkout-mixed-tax-and-payment.json` — is
reproduced exactly by `test_frozen_mixed_tax_basket_regression_fixture()`,
including its documented tie: two lines' exact discount shares tie at
the largest-remainder step (both `5.41666...`/`10.41666...` truncate to
the same fractional remainder pattern as the third line), and the
residual 2 centavos land on lines 1 and 2 (ascending `line_number`), not
line 3 — matching the fixture's own inline comment about this exact
tie, verbatim.

### 4c. Adversarial intermediate-precision tests (owner hardening pass)

One fixture reconciling correctly cannot rule out an implementation
that rounds early somewhere the fixture's own numbers don't happen to
expose. `tests/Unit/Domain/Financial/AdversarialPrecisionTest.php`
adds five cases, each constructed so that computing at only 2-decimal
intermediate precision (rather than carrying >=6dp through to the
frozen materialization point) produces a **different, provably wrong**
final centavo — and each test asserts both the correct result *and*,
as a sanity check, what the naive early-rounding shortcut would have
produced, proving they really do differ:

| # | Case | Exact intermediate value | Materialization point | Rounding mode | Authoritative result | Naive early-rounding shortcut (proven wrong) |
|---|---|---|---|---|---|---|
| 1 | Fractional Quantity × unit price (33.33 × 3.003) | 100.08999 | 1 (gross_line_amount) | round-half-up, once, after ≥6dp | **100.09** | 100.08 (`bcmul` at 2dp scale truncates) |
| 2 | Percentage discount (133.33 × 7.5%) | 9.99975 | 2 (line_discount_amount) | round-half-up, once, after ≥6dp | **10.00** | 9.99 (truncates across the peso boundary) |
| 3 | 7-way equal-weight allocation of ₱10.00 | 1.4285714285714... per line | 3/6 (discount/tax allocation) | floor to 2dp per DISC-005's own algorithm, then largest-remainder residual distribution — **not** round-half-up | **6 lines @ 1.43, 1 line @ 1.42, sum = 10.00 exactly** | rounding the exact share to 2dp *before* flooring gives 1.43 for all 7, summing to **10.01** — a full centavo over the total, violating the frozen "sum of floors never exceeds T" invariant, not merely producing a slightly different number |
| 4 | Tax decomposition of ₱100.00 at sum-then-decompose (÷1.12) | 89.2857142857142857... | 5 (taxable_sales/vat_amount) | round-half-up, once, after ≥6dp | **net = 89.29, vat = 10.71** | 89.28 (`bcdiv` at 2dp scale truncates directly, one centavo short — landing in the VAT remittance figure) |
| 5 | Refund basis from a finalized `net_line_amount` (124.58 − 50.00 − 30.00), DISC-004 | exact — no intermediate rounding needed at all | 7 (refund_item.unit_refund_amount) | none — subtracting already-2dp values is exact by construction | **44.58** | n/a — this case proves the *absence* of any rounding artifact when a future refund service is built on top of already-materialized values, not a rounding-mode contrast |

All five pass. Case 3 is the most structurally important: it is the
only one where the naive shortcut doesn't just differ, it actively
breaks a stated frozen invariant (domain-model.md §2.7a step 3's "sum of
floors never exceeds T"), which is exactly the failure mode a
one-fixture regression test cannot be relied on to catch.

### Idempotency tests (§13 of the owner's original instruction)

| Required scenario | Test | Result |
|---|---|---|
| Same key/same payload replay | `test_same_key_same_hash_replays_without_reexecuting` (sequential) + `test_simultaneous_same_key_same_hash_requests_produce_exactly_one_mutation` (true concurrent) | PASS both ways |
| Same key/different payload conflict | `test_same_key_different_hash_throws_idempotency_key_reused` (sequential) + `test_simultaneous_same_key_conflicting_hash_resolves_deterministically` (true concurrent) | PASS both ways |
| Failed operation does not consume key | `test_failed_operation_does_not_consume_the_key` | PASS — zero rows remain after rollback; same key retries fresh |
| Successful commit survives response-loss simulation | `test_successful_commit_survives_simulated_response_loss` | PASS — retry returns the original committed result, operation not re-run |
| **Simultaneous duplicate requests produce one authoritative mutation** | `IdempotencyConcurrencyTest::test_simultaneous_same_key_same_hash_requests_produce_exactly_one_mutation` — **two real OS processes, two real PostgreSQL connections** | **PASS** — see §4a for the full design/results; this is no longer proven only at the sequential/recovery-logic level |
| Different terminals may use the same UUID key independently | `test_different_terminals_may_use_the_same_key_independently` (sequential) + `test_different_terminals_same_uuid_key_execute_independently_even_when_simultaneous` (true concurrent) | PASS both ways |

---

## 5. Known limitations (disclosed, not silently skipped)

- **`GlobalLockOrder` is not yet wired into any real service**, since no
  Stage 6B/6C service exists yet to call it. Its correctness is proven
  in isolation (`GlobalLockOrderTest`); its actual use will be verified
  when Stage 6B's `InvoiceSeries` allocation service and Stage 6C's
  Checkout service are built.
- **`DatabaseExceptionTranslator`'s constraint-name registry is empty
  by default** — Stage 6A proves the mechanism (SQLSTATE-class fallback,
  a registered-constraint override) against a real unique-violation, but
  no service has registered a real production constraint mapping yet,
  since no service exists to need one.

---

## 6. Contradictions discovered

**One — see §7 immediately below.** Every other Stage 6A component
needed no frozen Stage 2–5 document, ADR, schema decision, or API
contract to be questioned or amended. `NON_VAT` is a genuine structural
gap between what domain-model.md's product-level `TaxClassification`
enum allows and what the frozen `TaxSummary`/`ZReadingTotalsSnapshot`
schemas can represent — escalated formally, not resolved by guessing.

---

## 7. `NON_VAT` frozen-corpus contradiction (formal escalation)

**Do not read this section as FinancialCalculator's design decision —
it is a gap in the frozen corpus itself**, surfaced by attempting to
implement `TaxClassification.NON_VAT` faithfully rather than papering
over it.

### The contradiction

`domain-model.md` §4 and `erd.md`'s `PRODUCT` entity both define
`tax_classification_snapshot`/`tax_class` with four values —
`VATABLE`, `VAT_EXEMPT`, `ZERO_RATED`, `NON_VAT` — as a single frozen
enum, with no textual distinction suggesting `NON_VAT` is somehow less
first-class than the other three. But the frozen `Sale`/`TaxSummary`
and `ZReadingTotalsSnapshot` schemas provide exactly **four** aggregate
fields to categorize a sale's revenue: `taxable_sales`,
`vat_exempt_sales`, `zero_rated_sales`, and `vat_amount` — and every one
of those four names is either explicitly VAT-scoped (`vat_amount`) or
describes a *VAT-registered store's* categorization of its VATable
basket (`taxable_sales`/`vat_exempt_sales`/`zero_rated_sales` are the
three categories a VAT-registered seller must break out per current BIR
invoicing guidance, which is exactly `TaxSummary`'s own one-line
description in `openapi.yaml`: "Preserved as separate values per
current BIR invoicing guidance for **mixed-category sales**").

A `NON_VAT`-classified line's `net_line_amount` therefore has
**nowhere to go**:
- It is not `VATABLE` (no VAT was charged).
- It is not `VAT_EXEMPT` in the BIR sense either — `VAT_EXEMPT` and
  `ZERO_RATED` are both statuses a VAT-registered seller assigns to a
  specific line *within* a VAT invoice; a `NON_VAT` seller does not
  issue VAT invoices at all, and the owner's own research (RMC No.
  77-2024) confirms treating a `NON_VAT` sale as if it belonged in a
  VAT-invoice category is not a harmless simplification — a Non-VAT
  seller who issues a VAT-shaped representation of a sale can incur
  real VAT/surcharge consequences. Mapping `NON_VAT` → `VAT_EXEMPT`
  would therefore not just be imprecise, it would misrepresent the
  transaction's actual tax character.
- It cannot be silently omitted either: every peso of `grand_total`
  must be accounted for somewhere for the fiscal reports (X/Z-Reading,
  the 15 Stage 4 reports) to reconcile against actual revenue — an
  omitted bucket means a `NON_VAT` store's entire sales volume would be
  invisible to its own `TaxSummary`, which is a compliance problem in
  the opposite direction.

### Where is the total value of NON_VAT sales represented today?

**Nowhere, cleanly.** The only place a `NON_VAT` line's money is
visible at all is inside `grand_total` (a plain sum of every line
regardless of classification, per DISC-006) and, transitively, in
Z-Reading's `gross_sales` field — both of which mix it back in with
every other category, indistinguishable from a `VATABLE`/`VAT_EXEMPT`/
`ZERO_RATED` line's contribution. There is no field, at the `Sale`,
`TaxSummary`, or `ZReadingTotalsSnapshot` level, that isolates "total
`NON_VAT` sales" as its own reportable figure.

### Affected documents

- **Stage 2**: `docs/02-domain/domain-model.md` §4 (Tax model) — defines
  `TaxClassification` with `NON_VAT` as a value but never describes its
  sale-level aggregation behavior, unlike the other three values which
  are fully specified via §2.7a's Deterministic Proportional Allocation.
- **Stage 4**: `docs/05-api/openapi.yaml`'s `TaxSummary` schema (used by
  the `Sale` response) and `ZReadingTotalsSnapshot` schema (the Z-Reading
  report) — both have exactly the four fields named above and no
  `non_vat_sales` (or equivalent) field.

### Smallest proposed amendment

Add one field, symmetric with the existing three, to both schemas:

- `TaxSummary.non_vat_sales: Money` (openapi.yaml)
- `ZReadingTotalsSnapshot.non_vat_sales: Money` (openapi.yaml)
- A corresponding `sales.non_vat_sales NUMERIC(12,2)` column (Stage 5
  schema amendment, `database/migrations/2026_01_01_000200_create_sales_table.php`)
- `domain-model.md` §4: one paragraph stating `NON_VAT` lines' summed
  `net_line_amount` populates this new field directly (no decomposition,
  since no VAT applies — the same "no decomposition needed" treatment
  `VAT_EXEMPT`/`ZERO_RATED` already receive), and are excluded from
  `taxable_sales`/`vat_exempt_sales`/`zero_rated_sales`/`vat_amount`
  exactly as they already are from the other three today.

This is additive (one new optional-in-practice field per schema, only
ever non-zero for a `NON_VAT`-registered store's sales), does not
change the meaning of any existing field, and does not require
reinterpreting `VAT_EXEMPT` or `ZERO_RATED`. **This amendment is
proposed, not applied** — Stage 6A does not modify any Stage 2/4/5
frozen document or migration. `FinancialCalculator` throws immediately
on a `NON_VAT` line until the owner decides how to proceed.

### Resolution (2026-09-17)

The owner confirmed this is a genuine frozen-corpus contradiction,
authorized exactly the amendment proposed above (plus the mutual-
exclusivity CHECK, which the owner's own domain rule made unambiguous),
and explicitly instructed **not to work around it**. Applied in three
disclosed commits ahead of restoring this Stage 6A work: Stage 2
(`domain-model.md` §4a, `invariants.md` TAX-NV-001..005, `erd.md`),
Stage 4 (`openapi.yaml` `TaxSummary`/`ZReadingTotalsSnapshot`, CSV
export contract, report summary text, examples), and Stage 5 (new
migration `2026_09_16_174605_add_non_vat_sales_to_sales_table.php`
adding `sales.non_vat_sales` with both a nonneg CHECK and the mutual-
exclusivity CHECK, plus `tests/Database/NonVatSalesTest.php`).

**Baseline-governance correction (2026-09-17, same day):** the first
draft of this amendment also modified ADR-006
(`docs/03-architecture/decisions/`), adding a `schema_version: 2`
addendum, while treating `stage-3-baseline` as unmoved — an internally
inconsistent claim the owner caught immediately. More substantively,
`schema_version: 2` was never warranted: Sale finalization isn't
implemented yet, so no `schema_version: 1` `InvoiceSnapshot` has ever
existed in production — there was nothing to version *forward from*.
`non_vat_sales` belongs in `schema_version: 1` as the initial
production shape instead, which is a pre-production Stage 4 contract
correction, not a schema migration. ADR-006 was reverted byte-for-byte
to its pre-amendment state (confirmed via `git diff`), and since the
four amendment commits were still local/unpublished, the sequence was
reset and rebuilt cleanly (Stage 2 → Stage 4, corrected → Stage 5 →
manifest) rather than layering a corrective commit on top. See
`docs/PROJECT-MANIFEST.md`'s revision log for the full account,
including the corrected tag-placement model (`stage-2-baseline`/
`stage-4-baseline` now point directly at their own amendment commits,
not a shared downstream manifest commit).

`FinancialCalculator::calculateSale()` now takes a required
`$taxRegistrationType` parameter (`'VAT'|'NON_VAT'`) rather than
inferring registration from totals, rejects any line whose
classification is incompatible with that registration by throwing
`App\Domain\Exceptions\InvalidTaxConfigurationException`
(unreachable-from-valid-configuration, so deliberately **not** a
`DomainException` subclass — that hierarchy maps 1:1 onto
error-catalog.md's public API codes, and this condition is a
configuration defect upstream of checkout, not a customer-facing
outcome; a command handler that reaches it should log/alert
deliberately rather than translate it into an HTTP error), and for a
`NON_VAT` registration returns `taxableSales = vatExemptSales =
zeroRatedSales = vatAmount = Money::zero()` with `nonVatSales` equal to
the sum of every line's `net_line_amount` (= `grandTotal`, per
TAX-NV-003). See
`tests/Unit/Domain/Financial/FinancialCalculatorTest.php`'s
`test_non_vat_*` and `test_*_registration_with_*_line_is_rejected`
cases. The `NON_VAT`-rejection test that previously asserted the
fail-loud placeholder behavior (`test_non_vat_line_is_rejected_not_
silently_bucketed`) has been replaced by these — `NON_VAT` is no longer
rejected outright, only an incompatible registration/classification
*pairing* is.

---

# Original Stage 6A Report (first submission, 2026-09-16)

Answering the owner's exact 16-item Stage 6A completion report as it
stood before the hardening pass. **Superseded in three places by the
hardening pass below**: item 14 (a contradiction was in fact found,
§7), item 15's NON_VAT/concurrency risk framing, and every test count.
Kept here as the historical record of what was originally submitted.

1. **Files created/modified**: 24 new files — 8 in `app/Domain/`
   (Money, Quantity, FinancialCalculator + 5 DTOs + DiscountAllocator +
   TaxCalculator + TaxDecomposition + TaxAllocationLine + 7 domain
   exceptions), 6 in `app/Services/Idempotency/`, 2 in `app/Support/`,
   8 new test files. No existing Stage 1–5 file was modified.
2. **Money implementation**: `app/Domain/Money.php` — immutable,
   bcmath-only, always 2dp at rest, exposes `add`/`subtract`/`negate`/
   comparison/`equals`, and exactly two named-materialization-point
   multiplication operations (`multiplyByQuantity`, `allocate`). No
   generic multiply/divide. 12 unit tests, including a repeated-0.10-
   addition test proving no float artifacts.
3. **Quantity implementation**: `app/Domain/Quantity.php` — immutable,
   non-negative, up to 3dp, bcmath-only, structurally incapable of being
   confused with Money (distinct class, no shared interface). 12 unit
   tests covering the exact values the owner named (`1`, `1.000`,
   `0.500`, `1.250`) plus invalid-precision/negative/non-numeric
   rejection.
4. **FinancialCalculator implementation**: composes `DiscountAllocator`
   + `TaxCalculator` over `Money`/`Quantity`. Implements gross line
   calculation, Deterministic Proportional Allocation (order discount +
   tax, both via `Money::allocate()`), sum-then-decompose VAT, and
   grand-total reconciliation. No rules engine — the 12% VAT rate is one
   class constant in `TaxCalculator`.
5. **Discount-allocation test evidence**: 12 `FinancialCalculatorTest`
   cases, including the frozen Stage 4 mixed-tax fixture reproduced
   exactly (₱5.42/₱10.42/₱4.16 tie resolution) and an isolated 3-way
   ₱1.00 split proving the one-centavo-residual/ascending-line_number
   tie-break rule independent of tax classification.
6. **Canonical request-hash strategy**: recursive key-sort for
   associative arrays, list-order preserved, native floats rejected
   outright, `request_id`/`idempotency_key` stripped at any depth before
   hashing, SHA-256 hex digest output. 11 unit tests.
7. **Idempotency architecture**: `IdempotencyService::execute()`
   generalizes ADR-010 to all 14 operation types
   (`IdempotencyOperationType` enum). Fast-path replay check outside any
   transaction; on a fresh key, the reservation `INSERT` is the first
   statement of the same transaction as the protected operation: commit
   makes both durable together, any exception rolls back both together.
8. **Transaction/rollback behavior**: proven live against PostgreSQL — a
   thrown exception inside the protected operation leaves *zero* rows in
   `idempotency_records` (not a stuck `IN_PROGRESS` row), and the same
   key is immediately reusable for a fresh attempt.
9. **Global lock-order mechanism**: `GlobalLockOrder`/`LockableResource`
   — an in-memory sequence guard (not a lock manager) matching
   architecture.md's `shift → fiscal_day → sale → sale_item (ascending)
   → invoice_series` table exactly, including the `sale_item`
   ascending-tie-breaker sub-rule. 9 unit tests proving both the
   Checkout and Void/Refund lock sequences are accepted and every
   out-of-order acquisition throws.
10. **Error/exception mapping**: `DomainException` base +7 concrete
    subclasses mapped 1:1 to error-catalog.md codes/HTTP statuses
    (`IDEMPOTENCY_KEY_REUSED`/409, `SHIFT_NOT_OPEN`/409,
    `FISCAL_DAY_CLOSED`/409, `SALE_NOT_VOIDABLE`/409,
    `REFUND_EXCEEDS_REMAINING_QUANTITY`/422,
    `REFUND_EXCEEDS_REMAINING_AMOUNT`/422, `CONCURRENCY_CONFLICT`/409).
    `DatabaseExceptionTranslator` maps PostgreSQL SQLSTATE classes
    (unique/FK/check violation) to `ConcurrencyConflictException` by
    default, with a `registerConstraint()` extension point for
    service-specific mappings later — raw constraint names/SQLSTATE/
    driver messages are logged, never returned in the error envelope
    (verified by an assertion that the envelope JSON contains neither).
11. **PostgreSQL integration tests**: `tests/Database/IdempotencyServiceTest.php`
    (7 tests) and `tests/Database/DatabaseExceptionTranslatorTest.php`
    (3 tests), run against the same live PostgreSQL 17 instance Stage 5
    validated against.
12. **Unit-test count/assertions**: 63 tests, 113 assertions
    (`php artisan test`, no database).
13. **Integration-test count/assertions**: 52 tests, 87 assertions
    (`php artisan test tests/Database`, live PostgreSQL — includes
    Stage 5's 42/59 plus this stage's +10/+28).
14. **Frozen-corpus contradictions**: none (§6 above).
15. **Risks**: (a) NON_VAT tax-bucket placement is an open question, not
    yet answered by the frozen corpus (§3/§5); (b) true multi-connection
    concurrent-INSERT racing remains unexercised until Stage 8;
    (c) `GlobalLockOrder` and `DatabaseExceptionTranslator`'s constraint
    registry are both unused by any real service yet — their contracts
    could still shift slightly once Stage 6B's `InvoiceSeries` service
    is the first real caller.
16. **Proposed Stage 6B plan**: `InvoiceSeries` allocation
    service/repository — `SELECT ... FOR UPDATE` on the series row
    (using `GlobalLockOrder` to assert it's acquired last, per the
    global order), serial formatting against the frozen digits-only
    pattern, concurrent-allocation tests (two terminals, same series,
    real overlapping transactions this time — Stage 6B's first genuine
    multi-connection test), and a rollback/no-number-consumption test
    proving a failed finalization never advances `current_number`. Not
    started; awaiting owner review of this Stage 6A submission first.

---

# Hardening Pass Report (2026-09-17)

Answering the owner's exact 12-item hardening-pass report, point by
point. **Do NOT begin Stage 6B. Do NOT commit/tag a Stage 6A baseline
until explicitly approved** — this is a second submission for review.

### 1. Concurrent idempotency test design

Two independent OS processes (`Process::start()`, Symfony Process under
the hood), each bootstrapping its own full Laravel application instance
and its own PostgreSQL PDO connection, synchronized at a file-based
barrier (both write a "ready" file, the main test process waits for
both before writing a shared "go" file both workers busy-poll for).
Full design in §4a above.

### 2. Exact race outcome

Same-hash race: exactly one worker executes the business mutation
(fresh, `replayed=false`); the other receives the identical committed
result (`replayed=true`) via the unique-violation-then-requery recovery
path. Conflicting-hash race: exactly one worker executes and commits;
the other receives `IdempotencyKeyReusedException` and its business
mutation never runs. Cross-terminal race: both execute independently, 2
distinct results, neither replayed. All three re-run 5 times
consecutively with identical outcomes.

### 3. Number of times the business callback executed

**Exactly once** in the same-hash and conflicting-hash races (verified
by counting rows in a dedicated `idempotency_race_mutations` table from
outside either worker process's own memory — not an in-process
counter), **exactly twice** in the cross-terminal race (by design, since
different terminals are a genuinely separate namespace).

### 4. Conflicting-hash race result

Deterministic: the loser resolves to `IdempotencyKeyReusedException`
regardless of which hash happened to win the underlying database race
(the test doesn't assume which side wins — it asserts the *shape* of
the outcome: exactly one success, exactly one
`IdempotencyKeyReusedException`, exactly one mutation). See §4a's table.

### 5. Financial intermediate precision strategy

Every FinancialCalculator computation that needs more than 2 decimal
places of precision (the exact allocation share `s_k`, the tax
decomposition division, a percentage-discount product) runs through
`bcmath` at a fixed ≥6-decimal-place intermediate scale, and is rounded
to 2dp exactly once, at the specific frozen materialization point that
calls for it (domain-model.md §3.1's seven-point list) — never earlier.
`Money` exposes only three rounding-producing operations
(`multiplyByQuantity`, `percentageOf`, `allocate`), each corresponding
1:1 to a named materialization point; no generic multiply/divide exists
for a call site to misuse. Full account in §3.

### 6. New adversarial rounding tests

Five new tests in `AdversarialPrecisionTest.php`, covering exactly the
six areas requested (fractional Quantity × price, percentage discount
sub-centavo intermediate, three/seven-line proportional allocation with
residual centavos, tax decomposition with a repeating decimal, mixed-tax
basket — already covered by the frozen fixture regression test, and
refund basis from a finalized allocated amount). Each documents its
exact intermediate value, materialization point, rounding mode, and
expected result, and proves a naive early-rounding shortcut would give
a different — in one case, frozen-invariant-violating — result. Full
table in §4c.

### 7. NON_VAT mapping

**Resolved, 2026-09-17 — see "Resolution" under §7 above.** Originally
escalated as a frozen-corpus contradiction with `FinancialCalculator`
throwing `InvalidArgumentException` immediately on any `NON_VAT` line.
The owner confirmed the contradiction and authorized the proposed
amendment; `FinancialCalculator` now takes an explicit
`$taxRegistrationType` and maps `NON_VAT` lines to `sale.non_vat_sales`
for a `NON_VAT`-registered store, while still rejecting (via
`InvalidTaxConfigurationException`, not silent reinterpretation) any
registration/classification pairing the domain rule forbids.

### 8. Whether any frozen-corpus amendment is required

**Yes, and it has been applied** (§7's "Resolution"): `non_vat_sales`
was added to `TaxSummary` and `ZReadingTotalsSnapshot` (openapi.yaml),
the CSV export contract, report/reading documentation, a new disclosed
Stage 5 migration (`sales.non_vat_sales` + nonneg + mutual-exclusivity
CHECK), `domain-model.md` §4a, and `invariants.md` TAX-NV-001..005 —
each in its own Stage-labeled commit. `stage-3-baseline` is genuinely
unchanged (`git diff stage-3-baseline -- docs/03-architecture` shows
only `erd.md`'s Stage-2-owned `SALE.non_vat_sales` line — ADR-006
itself was never touched in the final, corrected amendment; see the
"Baseline-governance correction" note under §7's "Resolution" for the
brief detour where it was, and why that was wrong).

### 9. Canonical hashing verification

All 7 items on the owner's checklist confirmed, 3 newly added: key
order (object and nested) doesn't affect the hash; list order does;
omitted field vs. explicit null hash *differently* (no null-means-absent
equivalence is invented); decimal strings are preserved exactly
character-for-character, never renormalized (`"100.00"` vs `"100.0"`
hash differently); `request_id`/`idempotency_key` are excluded at any
depth; every financially meaningful field tested (`amount`, `method`,
`product_id`, `quantity`) changes the hash when changed. 14
`CanonicalRequestHasherTest` cases total (was 11).

### 10. Final unit/integration test counts and assertions

**128 tests, 265 assertions, 0 failures** (was 115/200 before this
pass): 72 unit tests/144 assertions (`php artisan test`), 56 integration
tests/121 assertions (`php artisan test tests/Database`, live
PostgreSQL, includes the 3 new true-concurrency tests).

### 11. Files changed

**New**: `tests/Unit/Domain/Financial/AdversarialPrecisionTest.php`,
`tests/Database/IdempotencyConcurrencyTest.php`,
`tests/Database/support/idempotency_race_worker.php`.

**Modified**: `app/Domain/Money.php` (added `percentageOf()`),
`app/Domain/Financial/FinancialCalculator.php` (NON_VAT now throws),
`app/Services/Idempotency/IdempotencyService.php` (enforces the
64-character hex `request_hash` invariant at the point of use, removed
the now-unnecessary `rtrim()` workaround),
`tests/Unit/Domain/Financial/FinancialCalculatorTest.php` (+1 test),
`tests/Unit/Services/CanonicalRequestHasherTest.php` (+3 tests),
`tests/Database/IdempotencyServiceTest.php` (+1 test, all fake hashes
replaced with realistic 64-character hex strings),
`docs/06-backend/stage-6a-transaction-foundation.md` (this file).

### 12. Remaining risks

- The proposed `non_vat_sales` amendment (§7/§8) is not yet approved —
  `FinancialCalculator` cannot process any `NON_VAT` line until the
  owner decides how to proceed, which blocks Stage 6C persisting any
  sale for a `NON_VAT`-registered store.
- `GlobalLockOrder` and `DatabaseExceptionTranslator`'s constraint
  registry remain unused by any real service, unchanged from the
  original submission's risk list.
- The concurrency test's barrier (file-existence polling at 500µs
  intervals) is a coarse synchronization primitive — sufficient to prove
  correctness under genuine two-process racing, but it does not
  guarantee true microsecond-level simultaneity. This is judged
  sufficient for what the test needs to prove (the database's own
  locking correctness under real concurrent access), not a gap in the
  proof.

**STOP. Not beginning Stage 6B. Not committing or tagging a Stage 6A
baseline until explicitly approved.**
