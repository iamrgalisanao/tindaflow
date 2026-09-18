# Stage 10 — Reports Module

## Status

**Done and tested.** All 15 `openapi.yaml` Reports operations are now
implemented — the first module in this project that required **zero**
frozen-corpus changes to build.

## 1. Why this was built

`docs/PROJECT-MANIFEST.md`'s own "Next action" line named this as one
of the remaining deferred items: the pure historical-browsing/reporting
endpoints (`shiftGet`/`shiftList`/`fiscalDayGet`/`fiscalDayList`, and
the standalone Reports tag) were explicitly deferred during Stage 9
("a natural fit for a future Reports module"). This pass builds that
module. `database/migrations/2026_01_01_000320_add_reporting_and_
foreign_key_indexes.php`'s own docblock confirms Stage 5 anticipated
this exact shape when it added reporting-specific indexes
(`sales_store_sold_at_completed_idx`, `shifts_terminal_opened_at_idx`,
etc.) — this was always meant to be the last module built, "since every
report reads data every prior module produces"
(`docs/04-database/stage-5-report.md`).

## 2. Why no frozen-corpus reconstruction was needed this time

Every one of the last two modules (store-setup, shift-close) needed at
least one baseline reconstruction because some operation's frozen `404`
response was either genuinely new or already-frozen-but-mislabeled.
Reports is different: all 15 operations declare only `401`/`403`
responses in the frozen contract — no `404`/`409`/`422` surface exists
for any of them at all (confirmed by direct read of every operation
block). `operation-inventory.md` says this explicitly: *"reports never
fail on business state, only on auth."* Both `401`/`403` already map to
long-registered codes (`AUTHENTICATION_REQUIRED`, `AUTHORIZATION_DENIED`).
So this module could be built entirely as a forward addition with no
error-catalog.md change, no openapi.yaml change, and no baseline
touched — confirmed by `scripts/validate-baselines.sh` staying 14/14
throughout.

The one implementation-only decision this left open: what happens with
a malformed or missing `from`/`to` date query param, since the contract
declares no `422` for these operations either? Resolved by failing
open, not closed — an unparsable date is silently ignored (no lower/
upper bound applied) rather than rejected, consistent with "reports
never fail." This is a technical parsing decision, not a frozen-contract
gap.

## 3. Architecture

- `app/Services/Reports/ReportQueryService.php` — all 15 query methods,
  each a plain read-only aggregate over already-committed records via
  the query builder (`DB::table(...)`), returning `{rows, summary}`.
  No write ever happens here.
- `app/Http/Controllers/Reports/Concerns/RendersReportResponse.php` —
  the shared `Accept: text/csv` vs JSON (`ReportResult`) content
  negotiation, RFC 4180 CSV rendering per the exact column order in
  `docs/05-api/csv-export-contract.md`, and lenient `from`/`to` date
  parsing.
- Four thin controllers grouping the 15 operations by data source:
  `SalesReportController` (8: daily summary, by-date-range, by-product,
  by-category, by-cashier, by-payment-method, tax-breakdown, discounts),
  `VoidRefundReportController` (2), `InventoryReportController` (3:
  on-hand, low-stock, movement), `ShiftReportController` (2: shifts,
  cash-variance).
- All 15 routes share one middleware group:
  `['auth', EnsureUserIsActive::class, 'can:REPORT_VIEW']` — session
  only, no terminal credential (matches operation-inventory.md's
  `Term. enrolled? = false` for every row).

## 4. Aggregation conventions

- **A `VOIDED` sale is excluded from every revenue-shaped aggregate**
  (`gross_sales`, payment-method totals, tax breakdown, per-product/
  category sales) — the same convention Stage 9's Z-Reading aggregator
  established. A `REFUNDED`/`PARTIALLY_REFUNDED` sale is **not**
  excluded — the original transaction still happened and is still
  revenue; the refund is tracked as its own separate figure.
  `reportSalesByDateRange`, `reportVoids`, and `reportRefunds` are the
  three exceptions — raw listings that deliberately show every status/
  outcome, since that visibility is each report's own point.
- `reportSalesByProduct`/`reportSalesByCategory` join to the **current**
  `Product`/`Category` name, not `sale_items`' own historical snapshot
  columns — this report answers "how is this product doing," which
  wants today's catalog identity, not a point-in-time snapshot.
- `reportLowStock` sums `quantity_on_hand` **across all locations** per
  product before comparing to `reorder_level` (a single product-level
  threshold, not a per-location one) — `reportInventoryOnHand` shows
  the per-location breakdown instead.
- `reportVoids`/`reportRefunds` filter by `requested_at`, not
  `resolved_at`/`refunded_at` (nullable for pending/rejected outcomes),
  and show every status (`REQUESTED`/`APPROVED`/`REJECTED`/`VOIDED`
  or `COMPLETED`) rather than only completed ones.
- `sumColumns()` (a shared private helper) computes each report's
  `summary` block by re-summing the already-formatted row-level Money
  strings with `App\Domain\Money`, never re-querying — keeps `rows` and
  `summary` mutually consistent by construction.

## 5. Testing

`tests/Database/ReportsHttpTest.php` — 20 tests: capability gate
(`AUTHORIZATION_DENIED` for a cashier, `AUTHENTICATION_REQUIRED`
unauthenticated, a manager succeeding), correctness for all 15 report
types (including the VOIDED-exclusion/REFUNDED-inclusion distinction,
category/product filters, low-stock threshold math, cash-variance
CLOSED-only scoping), and CSV negotiation (exact header row match
against `csv-export-contract.md`, default-to-JSON behavior).

Three new model factories were added along the way for models that
had none yet (`Category`, `StockMovement`, `StockBalance`) — same
"add a factory when tests need one" pattern used for `TaxRegistration`/
`TerminalFiscalInstallation` in Stage 8.

Full regression: Unit 102 + Feature 1 + Database 301 (20 new) = 404
passing, 0 failures. Pint clean. `scripts/validate-baselines.sh` 14/14
(unchanged — no baseline touched).

## 6. Not built in this pass

No frontend — this was requested and scoped explicitly as backend-only.
`productExport` (a `Catalog`-tag operation, not `Reports`) remains
unimplemented, as does the Electronic Journal's own CSV export
(`journalEntryList` with `Accept: text/csv`) — both are pinned in
`csv-export-contract.md` but belong to different tags/modules than the
15 Reports operations this pass covers.
