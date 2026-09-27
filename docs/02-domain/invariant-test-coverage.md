# Invariant test coverage audit

Audit of every invariant in [invariants.md](invariants.md) (81 of them) against the automated tests, done 2026-09-27.
Documentation only: no code, test or schema was changed.

**Method.** For each invariant the audit read the rule, found where the code or schema enforces it, then opened the
candidate tests and read their assertions (test names were not trusted). The verdict asks one question: *would a test fail
if someone broke this rule?*

| Verdict | Meaning | Count |
|---|---|---|
| COVERED | A test fails if the rule is broken, and it covers the substance | 33 |
| PARTIAL | Some of the rule is proven; the gap is named below | 35 |
| UNCOVERED | Enforced (or claimed to be) but nothing tests it | 3 |
| UNCOVERED-structural | Held only by the absence of code or a route, and no test pins that absence | 9 |
| STRUCTURAL (partly pinned) | Same, but a test pins part of it | 1 |

Where the tests live: `tests/Database` (real PostgreSQL, run explicitly in CI, not in `phpunit.xml`), `tests/Feature`,
`tests/Unit`. There are no frontend tests.

Items marked **verified** were re-checked by hand after the audit; the rest are the auditor's reading of the code.

## Findings that matter

Ranked by consequence. Each is a real difference between what `invariants.md` promises and what the repository does.

> **Update 2026-09-27.** Findings 1, 2, 3, 4 and 5 are fixed (see the notes under each). The verdict tables below show the audit as
> it stood before the fixes.

1. **The append-only hardening script would break void and refund (#48, #45, #23, #60, #76, #77). Verified.**
   `database/scripts/harden_append_only_privileges.sql` revokes UPDATE on `sales`, and never re-grants it, yet
   `VoidService.php:234` and `RefundService.php:360` update `sales.status`. Those are the only application writes to any
   revoked table. Nothing applies the script (it is not a migration and no deploy file runs it), so nothing is broken today.
   The catch is that invariant #48 says append-only is "enforced at the database layer", and that enforcement is this
   script alone. Two further problems: it names the role `tindaflow_app` while `.env.example` connects as `tindaflow`, and
   a comment in the audit-events migration cites a `..._restrict_application_role_privileges.php` migration that does not
   exist. So today the database enforces none of the append-only rules, and applying the script as written breaks reversals.
   **Fixed 2026-09-27** in the script and one service, and now proven by `tests/Database/AppendOnlyPrivilegesTest.php`
   (6 tests), which applies the script to a scratch role inside a rolled-back transaction and runs checkout, void
   (immediate, approve, reject) and refund (immediate, approve, reject) under it. Building that test found more than
   the audit did:
   - `sales` needed `GRANT UPDATE (status)`. That grant is also what lets void and refund take `SELECT … FOR UPDATE` on
     the sale row, because PostgreSQL requires UPDATE on at least one column for any row lock.
   - `voids` and `refunds` needed `updated_at` in their column grants (Eloquent writes it on every update); the
     approve and reject paths would have failed.
   - `RefundService` also locked every `sale_items` row `FOR UPDATE`, which the same rule forbids under the hardening.
     The lock was redundant (the sale row is already locked, which serializes every void and refund of that sale, and
     lines are immutable), so it was dropped; the reversal concurrency tests still pass.
   **Deployment fixed 2026-09-27.** The Docker stack ran one role (a superuser inside its container) for migrations and
   the running app, and a superuser ignores privileges, so nothing enforced the rules. It now runs two: a one-shot
   `migrate` service, as the owner, applies migrations and `php artisan tindaflow:harden-database`; the app then connects
   as `tindaflow_app` and never holds the owner's credentials. Verified on a real built stack. Details, choices and the
   upgrade path for an existing single-role stack: [stage-23-production-readiness.md](../06-backend/stage-23-production-readiness.md),
   addendum, and `docker/README.md`. **Not done:** switching the real store server over (an owner action). The
   audit-events migration's comment still cites a `..._restrict_application_role_privileges.php` migration that does
   not exist; it lives in the frozen Stage 5 corpus and was left alone.
2. **Invoice numbers can be reissued (#11, #18). Verified at the request layer.** Series creation checks only
   `starting_number >= 1`. Closing a series and creating a new one for the same installation with the same prefix and a
   low starting number appears to issue numbers that were already used. Uniqueness is per series row only. I did not run
   this end to end. If it holds, it also defeats #18 ("no administrative rewind").
   **Fixed 2026-09-27, and confirmed real:** on the old code a replacement series starting at 1 was accepted (HTTP 201)
   after 250 numbers had been issued. `InvoiceSeriesService::create` now refuses a start at or below the highest number
   an earlier series of the same fiscal installation reached (422 on `starting_number`). See
   [stage-6b-invoice-series-allocation.md](../06-backend/stage-6b-invoice-series-allocation.md), addendum.
3. **Z-reading reads a prior stored total as an input (#40). Verified.** `FiscalDayReadingAggregator.php:51` takes
   `accumulated_grand_total_sales_after` from the previous Z-reading's snapshot. The invariant says readings are never
   inputs. Also, a void executed after its shift closed is not reflected in that shift's stored totals, so a closing
   X-reading stops being reproducible.
   **Fixed 2026-09-27** for the accumulated total: it is derived from the ledger and a closed day reproduces its stored
   reading (tested). The shift X-reading question ("as of close" or recomputed now) is recorded as open in
   [stage-9-shift-close-fiscal-day-close.md](../06-backend/stage-9-shift-close-fiscal-day-close.md), addendum.
4. **Reports select by `sold_at`, not by fiscal day (#9). Verified.** `ReportQueryService` filters on
   `sales.sold_at` while grouping by `fiscal_days.business_date`, so a sale made after midnight in fiscal day D is missed by a
   report run for day D. The invariant says the foreign key is the only source of truth.
   **Fixed 2026-09-27**: the sales reports now window by `fiscal_days.business_date`, and the till and Fiscal Days screen warn
   about a day left open past midnight. See [stage-10-reports.md](../06-backend/stage-10-reports.md), addendum.
5. **`SHIFT_OPENED` is never journaled (#49). Verified.** It is in the journal's event-type list but no service writes
   it. The invariant lists shift open as journalable and calls an omission "a bug". Five other event types
   (`X_READING`, `Z_READING`, `SHIFT_CLOSED`, `CASH_IN`, `CASH_OUT`) are written but have no test.
   **Fixed 2026-09-27**: shift open writes one audit event and one journal entry (no backfill for shifts opened before the
   change). The five other types still have no journal test.
6. **Discount eligibility is entirely untested (#66).** No test anywhere sets `order_discount_eligible` to false, so
   ignoring the flag would fail nothing.
7. **Cash-out threshold is `>=`, the text says "above" (#39). Verified.** `CashMovementService.php:70` uses
   `greaterThanOrEqual`. The boundary is also untested.
   **Pinned 2026-09-27, not changed.** `CashMovementHttpTest` now asserts the boundary as implemented (a cashier may take out
   499.99 but not 500.00 at a 500.00 threshold). Every frozen text says "above", but the default threshold is documented as a
   placeholder and `>=` is the stricter control; loosening a cash control is the owner's call, so the code stays and the test
   fails loudly if it ever changes unnoticed.
8. **A missing tax registration may surface as a 500 (#54). Unverified.** `TaxRegistrationResolutionException` extends
   `RuntimeException`; I found no mapping to an API error code.
9. **A frontend float on money (#55).** `Pos.jsx:429` and `:480` send `Number(x).toFixed(2)` for the cash-movement
   amount and declared cash. The server re-validates the format, so this is low risk, but it is the one place a float
   touches a monetary input.
   **Fixed 2026-09-27**: the two sends now use string-based `apiMoney`, and the frontend has 54 automated tests (Vitest); see stage-23 addendum 8.
10. **Smaller drift.** #67 says `APPROVED` is persisted; code goes straight from `REQUESTED` to `VOIDED`/`COMPLETED`.
    #43 says X-readings while the shift is open; the code also allows them on a closed shift. #37 does not mention that
    cash sales are now net of change. #52 does not mention that statutory discounts bypass `DISCOUNT_OVERRIDE`. #2's
    model docblock says no `updated_at`, but the migration adds one.

### Test defects found

- `ConstraintValidationTest::test_one_open_shift_per_cashier` (#34). **Verified.** It inserts terminal 2's shift on
  terminal 1's fiscal day, so the composite foreign key can reject it and the cashier index is never proven.
- `SaleRefundHttpTest` step for `EXPIRED` disposition (#30) returns 422 only because of a duplicate `sale_item_id`, so it
  proves nothing about `EXPIRED` skipping stock.
- `ConstraintValidationTest::attemptFails` accepts any `QueryException`, not SQLSTATE `23505`, so several constraint
  tests could pass for the wrong reason.

**All three fixed 2026-09-27.** `attemptFails` now accepts only data or integrity violations (SQLSTATE class 22 or 23, or a
trigger's P0001), rethrows anything else, and can require a named constraint; the cashier-index test gives the second
terminal its own fiscal day and has a control insert proving the fixture is valid; the `EXPIRED` disposition is refunded in
a request of its own and asserts no stock returns, with the duplicate-line 422 kept as a separate test.

## Per-invariant results

Format: `# title — verdict — the gap`.

### Sale and invoice numbering (1–19)

| # | Invariant | Verdict | Gap |
|---|---|---|---|
| 1 | No persisted intermediate status | COVERED | — |
| 2 | Immutability after completion | PARTIAL | Nothing asserts `sale_items`/`payments` unchanged after void or refund |
| 3 | No direct DELETE route | PARTIAL | No test for sale, sale_item, payment, invoice routes; audit/journal pinned on collection URL only |
| 4 | Server-authoritative totals | COVERED | Only the grand-total hint is tested |
| 5 | Idempotent finalization | COVERED | The unique index itself has no direct test |
| 6 | Atomicity of finalization | PARTIAL | Only a failure at invoice allocation is injected |
| 7 | Historical snapshot integrity | PARTIAL | No test of a tax-registration change after issue, or of reports recomputing |
| 8 | Payment sufficiency, server-side change | COVERED | No just-under boundary case |
| 9 | Explicit fiscal attribution | PARTIAL | "Never infer from `sold_at`" untested; reports do exactly that (finding 4) |
| 10 | One invoice per sale | PARTIAL | The at-most-one half is not pinned |
| 11 | No reuse or renumbering | PARTIAL | Reissue via a new series is possible (finding 2) |
| 12 | Allocation only at finalization | UNCOVERED-structural | Nothing pins that void/refund/admin never allocate |
| 13 | Concurrency-safe allocation | COVERED | — |
| 14 | No gaps from failed attempts | COVERED | — |
| 15 | Reprint never allocates | COVERED | — |
| 16 | Reprint renders only from snapshot | COVERED | — |
| 17 | Void/refund never allocate a number | UNCOVERED-structural | No assertion that invoice count and counter are unchanged |
| 18 | No administrative rewind | UNCOVERED-structural | Close-and-recreate works around it (finding 2) |
| 19 | Series exhaustion fails safely | COVERED | The 409 `INVOICE_SERIES_EXHAUSTED` mapping is untested |

### Void, refund, fiscal day, shift, readings (20–43)

| # | Invariant | Verdict | Gap |
|---|---|---|---|
| 20 | Void five-part gate | COVERED | No test for a requester lacking `SALE_VOID` |
| 21 | One VOIDED void per sale | PARTIAL | The unique index is never exercised |
| 22 | Full reversal | PARTIAL | Only a one-line sale is tested |
| 23 | Originals untouched | PARTIAL | `sale_items`/`payments` never asserted unchanged |
| 24 | Void audit trail | PARTIAL | Requester vs approver identity and request metadata unchecked |
| 25 | Voided sale is a refund dead end | COVERED | — |
| 26 | Refund never mutates the original | UNCOVERED-structural | Nothing asserts originals unchanged after a refund |
| 27 | Cumulative quantity cap | COVERED | — |
| 28 | Cumulative monetary cap | COVERED | The amount-cap error is reachable only in a unit test |
| 29 | Refund unavailable on voided sale | COVERED | — |
| 30 | Disposition drives stock effect | PARTIAL | `EXPIRED` never proven to skip stock (test defect) |
| 31 | Sale status derived from refunds | COVERED | — |
| 32 | One open fiscal day per terminal | COVERED | Weak `attemptFails` |
| 33 | One open shift per terminal | COVERED | — |
| 34 | One open shift per cashier | COVERED | Constraint test is a false positive; HTTP and race tests carry the proof |
| 35 | Sale needs open shift and fiscal day | COVERED | — |
| 36 | Day cannot close with an open shift | COVERED | Does not assert the day stays OPEN |
| 37 | Shift totals computed | PARTIAL | `refunds_total`, void exclusion and non-cash refunds untested |
| 38 | Variance recorded, never corrected | PARTIAL | Post-close immutability unpinned |
| 39 | Cash-out authorization | PARTIAL | Boundary untested; code is `>=` (finding 7) |
| 40 | Readings reproducible | UNCOVERED | No reproducibility test; drift (finding 3), since fixed and tested |
| 41 | Readings append-only | UNCOVERED-structural | No test pins the absence of update/delete |
| 42 | One Z-reading per fiscal day | PARTIAL | Atomicity of close unproven |
| 43 | X-reading without closing the shift | PARTIAL | "Resets no total" unproven; allowed on closed shifts |

### Inventory, audit, authorization, tax, money, discounts (44–66)

| # | Invariant | Verdict | Gap |
|---|---|---|---|
| 44 | Stock balance derived from ledger | COVERED | "No other writer" unpinned |
| 45 | Movements append-only, attributed | PARTIAL | Only happy-path attribution; no update/delete test |
| 46 | Adjustments require a reason | PARTIAL | Only one adjustment type tested for a missing reason |
| 47 | Sale deducts, void/refund restore | COVERED | Void restore tested on a one-line sale only |
| 48 | Audit/journal append-only at DB layer | UNCOVERED | Script never applied (finding 1) |
| 49 | One journal entry per fiscal event | PARTIAL | `SHIFT_OPENED` never written (finding 5), since fixed |
| 50 | Journal references its source | PARTIAL | Shift, cash and reading events untested; some sources differ from the text |
| 51 | No edit or purge via the app | STRUCTURAL (partly pinned) | `/{id}` routes and Artisan commands not pinned |
| 52 | Capability-gated endpoints | PARTIAL | Adjustment denial and request gates untested |
| 53 | Tax registration change never rewrites history | PARTIAL | No VAT→NON_VAT switch after a sale |
| 54 | Registration never silently defaulted | PARTIAL | Resolver only; no checkout or HTTP test (finding 8) |
| 55 | No floating point in money | PARTIAL | No static check; frontend `toFixed` (finding 9) |
| 56 | Rounding only at materialization points | COVERED | Nothing stops new rounding elsewhere |
| 57 | Line totals reconcile to grand total | COVERED | Fixed cases, no property test |
| 58 | `Quantity` and `Money` distinct | UNCOVERED-structural | Nothing refuses one where the other is expected |
| 59 | DISC-001 exact allocation sum | COVERED | No DB-level sum assertion |
| 60 | DISC-002 allocation immutability | UNCOVERED-structural | Nothing snapshots `sale_items` across a refund |
| 61 | DISC-003 no recompute from current config | PARTIAL | No test changes price or tax class then refunds |
| 62 | DISC-004 refund cap from `net_line_amount` | PARTIAL | No refund test uses an order-level discount |
| 63 | DISC-005 deterministic residual | PARTIAL | Per-line VAT tie-break untested |
| 64 | DISC-006 grand-total reconciliation | COVERED | — |
| 65 | Mixed-basket tax scope | COVERED | No multi-VATABLE-line basket |
| 66 | Discount eligibility explicit | UNCOVERED | Ineligible-line path entirely untested (finding 6) |

### Processing context, NON_VAT, invoice series (67–81)

| # | Invariant | Verdict | Gap |
|---|---|---|---|
| 67 | Processing context set at execution | COVERED | The CHECK has no direct test; `APPROVED` is never persisted |
| 68 | Context independent of the sale's | COVERED | — |
| 69 | Open processing day and shift required | COVERED | No race against Z-reading or shift close |
| 70 | Settlements sum to refund total | PARTIAL | The approve-time re-check is untested |
| 71 | Cash-drawer effect per method | PARTIAL | Non-cash and split refunds leaving the drawer unchanged untested |
| 72 | Settlements created atomically | PARTIAL | No fault-injection test |
| 73 | TAX-NV-001 NON_VAT is not VAT_EXEMPT | PARTIAL | Reports never run over a real NON_VAT sale |
| 74 | TAX-NV-002 VAT sale has no NON_VAT sales | PARTIAL | No checkout-level refusal test |
| 75 | TAX-NV-003 NON_VAT zeroes VAT buckets | COVERED | Persisted columns not asserted end to end |
| 76 | TAX-NV-004 summaries never reinterpreted | UNCOVERED-structural | No finalize, switch registration, re-read test |
| 77 | TAX-NV-005 classification immutable | UNCOVERED-structural | No test across void, refund or product change |
| 78 | INVSERIES-001 start never skipped | COVERED | — |
| 79 | INVSERIES-002 one fiscal installation | PARTIAL | NOT NULL and composite FK untested |
| 80 | INVSERIES-003 one ACTIVE series | PARTIAL | Exhausted-stays-active and the HTTP error code untested |
| 81 | INVSERIES-004 range never backwards | COVERED | HTTP validation untested; DB layer proven |

## Progress on the per-invariant table (2026-09-27, "money-reversal proof")

The verdict tables above still show the audit as it first stood. Since then these rows have gained tests (the new tests
are `ReversalMatrixTest`, `JournalEventsHttpTest`, plus additions to `SaleRefundHttpTest`, `ConstraintValidationTest` and
`CashMovementHttpTest`):

- **#66 discount eligibility** is now covered: an ineligible line takes no share and keeps its full price, and an order
  discount with no eligible line is refused. Writing it found a real defect, fixed: that request returned an HTTP 500
  (a bare `InvalidArgumentException` from `DiscountAllocator`); it is now a 422 `VALIDATION_FAILED` with a field error on
  `order_level_discount_amount` (or `statutory_discount`), with nothing recorded.
- **#62, #63, #61, #77, #23, #26, #60, #17** are exercised on a three-line basket of mixed VAT classes (VATABLE, VAT_EXEMPT,
  ZERO_RATED) with an order discount that leaves a rounding residual, paid by two methods: every line is refunded one unit at
  a time to exactly its `net_line_amount` (the expected amounts are computed independently in the test), a price and tax-class
  change part-way through has no effect, and `sales`, `sale_items`, `payments`, the invoice, the invoice counters and the
  original stock movements are byte-identical afterwards. A void of the same basket reverses all three lines in full (#22).
- **#71, #37** for a cash-plus-GCash refund: expected cash falls by the cash settlements only, `refunds_total` counts every
  method.
- **#6, #72** atomicity: a failure injected at the payment rows, stock ledger, invoice, audit event or journal leaves no trace
  and no consumed invoice number, and the same key then succeeds once; the same for a refund failing at its settlements, stock
  return or journal.
- **#49, #50**: `CASH_IN`, `CASH_OUT`, `X_READING`, `SHIFT_CLOSED` and `Z_READING` each write exactly one journal entry
  pointing at their record and their audit event.
- **#39** boundary pinned (see finding 7). **#30** `EXPIRED` now proven. **#34** cashier index now proven.

**Closing pass (2026-09-27, `CloseAtomicityTest`):** #42 is now proven (a failure injected at the Z-reading, the fiscal-day
update, the audit event or the journal leaves the day OPEN with nothing behind it and the same key then closes it exactly
once; a closed day can never get a second Z-reading, and the stored reading is untouched); the shift close is proven the same
way; #38 and #41 are proven for a closed shift (a second close with a "better" count is refused, a later refund of the same sale
lands in the refunding shift and leaves the closed shift's stored figures and closing X-reading unchanged, and no route edits or
deletes a closed shift or a reading).

Still PARTIAL or open after this pass: #21 (the unique index is never exercised directly), #24 (audit metadata detail),
#43 (interim reading resets no total), #45/#41/#51 (structural
absence not pinned), #53/#54/#76 (a registration change after a sale; the tax-registration 500), #58 (Money vs Quantity), #73
to #75 (NON_VAT through reports), #79/#80 (series installation checks), and the HTTP error-code mappings for series exhaustion.

## What this audit did not do

- It did not run the tests. Verdicts come from reading them, so a test that reads well but is broken would be missed.
- It did not measure line or branch coverage.
- It did not audit the frontend. (It had no automated tests then; since 2026-09-27 it has 54 Vitest tests on the money arithmetic, retry keys, API wrapper and session dialog.)
- Nothing here changes the frozen Stage 2 baseline. Suggested wording changes to `invariants.md` (findings 7 and 10)
  need the owner's approval before the corpus is edited.
