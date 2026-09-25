# Stage 32 — Sales by Hour and Gross Profit reports

## Status

**Done and tested**, backend, contract, and the admin screens (schema-driven, no new frontend code beyond two
registry entries and two small cell formats). No frozen file edited and no `stage-*-baseline` tag moved;
`scripts/validate-baselines.sh` holds. `reportSalesByHour` and `reportGrossProfit` are **wholly new operations,
forward-committed** under the same governance already established in stages 25/26/29: no prior draft existed at any
stage, so there is no already-frozen response to complete a gap in, and the Reports tag's own error surface (401/403
only, "reports never fail on business state") is unchanged.

## 1. Why this was built

Named directly in `docs/PROJECT-MANIFEST.md`'s "Next action" row after the earlier Qtech/UTAK/StoreHub comparison:
hourly sales reporting and gross-profit/margin reporting were identified as named gaps against Qtech's qPOS (which
markets both), and flagged as cheap to build because the data was already there — `sales.sold_at` for the hour bucket,
`sale_items.unit_cost_snapshot` (present since Stage 6A, written by `CheckoutService`, previously read by nothing) for
cost of goods sold.

## 2. Sales by Hour

Transactions and revenue bucketed by **hour of day, 0–23, across the whole `[from, to]` window** — a merchant asking
"what are my busiest hours" wants one row per hour, not one row per hour per calendar day. `EXTRACT(HOUR FROM
sales.sold_at)` is used directly in SQL rather than converted in PHP: the database session's own timezone has been
pinned to the store's configured zone (`Asia/Manila` by default) since Stage 23 (D1), so `EXTRACT` already reads back
the local hour. Voided sales are excluded, matching every other revenue-shaped aggregate in this module.

Columns: `hour, transaction_count, gross_sales, discount_total, grand_total`.

## 3. Gross Profit

Net sales, cost of goods sold, and the resulting gross profit and margin, **per business date** (mirroring
`dailySalesSummary`'s own grouping). Three decisions, each disclosed rather than assumed:

- **`net_sales` is `SUM(sale_items.net_line_amount)`, which is VAT-inclusive** (the amount the customer actually paid
  per line, net of discount) — matching the same simplification precedent already set for discounts
  (`stage-24-owner-decisions.md`, D4: "a deliberate application-level simplification"). This is a *management*
  figure — "did we make money on this line" — not a VAT-exclusive accounting margin, and the report's own description
  says so explicitly so it can never be read as a compliance figure.
- **COGS is computed in raw SQL** (`SUM(ROUND(COALESCE(unit_cost_snapshot, 0) * quantity, 2))`) over already-2dp
  `NUMERIC` columns, **never through `Money`'s own arithmetic**. `app/Domain/Money.php`'s own docblock is explicit
  that it "intentionally exposes no generic multiply... any other monetary arithmetic belongs in
  `FinancialCalculator` and its collaborators" — reserved for the checkout path's own materialization points. This
  report reads an already-committed, already-rounded ledger value; it is not a new materialization point, so it
  correctly stays outside that reservation. `Money::fromApiString` is used only to format the SQL result's string,
  never to perform the multiplication.
- **A line with no cost snapshot (a product created before its cost was ever set) costs `0` in the sum, and is
  separately counted** as `lines_with_unknown_cost` rather than silently treated as a real zero-cost line. This means
  gross profit is *overstated*, never understated, for a day with unknown-cost lines — the report's own description
  and the frontend's `note` field both say this plainly, so an owner never mistakes an incomplete figure for a
  confirmed one.

`gross_margin_percent` (`gross_profit / net_sales * 100`, `null` when net sales is zero rather than a misleading
`0.00`) is computed with `bcmath`, matching this app's no-floating-point-for-money discipline even though a display
ratio is not itself a Money value — a small, named `marginPercent()` helper, rounding half up to 2 decimal places.

Columns: `business_date, transaction_count, net_sales, cost_of_goods_sold, gross_profit, gross_margin_percent,
lines_with_unknown_cost`.

## 4. Contract and frontend

Forward-committed into `openapi.yaml` (Reports tag), `operation-inventory.md` (17 Reports operations, up from 15),
and `docs/05-api/csv-export-contract.md` (both column lists, exact order). Two routes added to the existing
`REPORT_VIEW` middleware group in `routes/web.php`, both on `SalesReportController` alongside the other eight
sale-ledger reports. Frontend: two entries in `reportsRegistry.js` (the same declarative shape every other report
uses — no new viewer code), plus two new cell formats in `formatters.js`: `percent` (`"62.50" → "62.50%"`) and
`hour_of_day` (`10 → "10:00–11:00"`).

## 5. Verification

- New tests (`tests/Database/ReportsHttpTest.php`, +3): sales-by-hour buckets across dates and excludes a voided
  sale; gross-profit computes net sales/COGS/profit/margin from real cost snapshots, excludes a voided sale, and
  discloses one unknown-cost line; margin is `null` (not `0.00`) when net sales is zero. Full regression: Unit+Feature
  156, Database 656 (3 new), Pint clean, frontend builds.
- Verified in a real browser against the real backend and real sales data rung earlier this session: Gross Profit
  showed ₱80.00 net sales, ₱30.00 COGS, ₱50.00 gross profit, 62.50% margin, 0 unknown-cost lines — matching the two
  real sales in the dev database by hand (₱30 Bottled Water at ₱10 cost + ₱50 discounted Rice at ₱10 cost = ₱80 net /
  ₱30 COGS). Sales by Hour showed both sales in the same hour bucket with the correct discount and grand-total
  figures. CSV export checked directly (`Accept: text/csv`) and matches the pinned column order exactly.

## 6. Not built

A fast/slow-moving-item report (a separate, not-yet-built gap; `salesByProduct` sorted by `net_sales` descending
gives a partial substitute today). Gross profit by product or category (this pass is date-bucketed only, matching
`dailySalesSummary`'s own grouping — a per-product breakdown is a natural, separate follow-on). A configurable
timezone-aware hour boundary beyond the store's single configured zone (out of scope — matches the existing
single-zone assumption `BusinessTimezoneTest` already documents).
