# Stage 34 — Product Velocity and Gross Profit by Product

## Status

**Done and tested**, backend, contract, and admin screens (schema-driven, no new frontend code). No frozen file
edited and no `stage-*-baseline` tag moved; `scripts/validate-baselines.sh` holds. `reportGrossProfitByProduct` and
`reportProductVelocity` are **wholly new operations, forward-committed** under the same governance as stages
25/26/29/32: no prior draft existed at any stage, and the Reports tag's own error surface (401/403 only) is
unchanged.

## 1. Why this was built

Named directly in `docs/PROJECT-MANIFEST.md`'s "Next action" row: a fast/slow-moving-item report (the remaining named
gap against Qtech's "identification of fast-moving items") and gross profit broken down by product (Stage 32 shipped
gross profit bucketed by business date only, and flagged the per-product breakdown as its natural follow-on).

## 2. Product Velocity — the actual "fast/slow-moving" answer

Every product ranked by units sold across the date range, **fastest first, including products with zero sales**.
That last part is the point: `salesByProduct` (Stage 10) already ranks products by revenue, but it is an `INNER JOIN`
through `sale_items`, so a product that has not sold at all simply cannot appear in it. A true slow-mover — stock
sitting untouched — is exactly the case a re-sort of `salesByProduct` can never surface. `productVelocity()` is
driven from `products`, with `sale_items`/`sales` **LEFT JOINed** and the date/status filters placed in the join
condition rather than a `WHERE` clause (a `WHERE` would silently drop every zero-sale product, since its now-NULL
`sales` columns fail any `>=`/`<=` comparison).

No fast/slow threshold or label is computed server-side — the standing "do not invent unsupported business rules"
directive applies here exactly as it did to `lowStock`'s own tiering (documented there as "a display convention, not
a business rule"). This report is a ranked list; what counts as "fast" or "slow" is left to the person reading it.

Columns: `product_id, sku, product_name, quantity_sold, transaction_count, net_sales, quantity_on_hand`.
`quantity_on_hand` is **`null` for a product with `track_inventory = false`** (no ledger exists for it), never a
misleading `0` ("confirmed empty") — the same distinction `lowStock`/`inventoryOnHand` already draw. Sorted by
quantity sold descending, with product name as a deterministic tie-break (two products both at zero sales need a
stable order, not whatever a given query plan happens to return).

## 3. Gross Profit by Product

The same net-sales/COGS/gross-profit/margin computation `grossProfit()` (Stage 32) already does, grouped by product
instead of business date. Refactored the shared math (subtract, then compute margin) out of `grossProfit()` into one
private `profitFigures()` helper so both reports compute it identically rather than duplicating the logic. Unlike
Product Velocity, this one stays an `INNER JOIN` (a product with no sale in the window has no profit figure to show,
by construction) — only products with at least one qualifying line appear.

Columns: `product_id, sku, product_name, quantity_sold, net_sales, cost_of_goods_sold, gross_profit,
gross_margin_percent, lines_with_unknown_cost`.

## 4. A real defect found and fixed along the way

Sanity-checking `productVelocity()` in `tinker` against a product with **zero matching sales** threw
`Money amount must be a decimal string with exactly 2 places... got "0"`. The `SUM(CASE WHEN ... ELSE 0 END)` used to
zero out a LEFT-JOINed line's contribution when its `sales` match fails returns a bare integer `0` from PostgreSQL
when the whole group has no qualifying rows at all — not the `"0.00"` shape `Money::fromApiString()` requires. This
was a latent gap in the shared `money()` helper itself, not something specific to this report: any future report
built the same LEFT-JOIN-with-zero-fallback way would have hit it too. Fixed at the shared helper —
`money(mixed $rawSum): string` now normalizes through `bcadd($rawSum, '0', 2)` before constructing `Money`, which is
always a safe reformat here (never a real rounding), because every underlying column this app sums is already exact
to 2 decimal places. Added the quantity equivalent, `quantity()` (3 decimals), used for the same reason on
`quantity_sold`/`quantity_on_hand` so a zero-row group reads `"0.000"` like every real quantity sum does, not a bare
`"0"`.

## 5. Verification

- New tests (`tests/Database/ReportsHttpTest.php`, +3): gross-profit-by-product groups correctly and excludes a
  voided sale; product-velocity lists a never-sold product alongside its real stock, correctly nulls
  `quantity_on_hand` for an untracked product, and sorts deterministically; product-velocity excludes a voided
  sale's quantity. Full regression: Unit+Feature 156, Database 659 (3 new), Pint clean (auto-fixed one quote-style
  nit), frontend builds.
- Verified in a real browser against the real backend and real sales data rung earlier this session: Product
  Velocity correctly ranked Bottled Water (2 sold) above the two single-sale products, with a zero-sale test product
  ("Never Sold Item") at the bottom showing its real on-hand quantity and ₱0.00 net sales. Gross Profit by Product
  showed the same per-product figures the date-bucketed Gross Profit report's total already implied (₱90.00 net /
  ₱40.00 COGS / ₱50.00 profit across the three sold products), correctly omitting the never-sold product. CSV export
  checked directly against the pinned column order for both.

## 6. Not built

Gross profit by category (a natural third report in this family, not built this pass — `salesByCategory` exists as
the non-profit precedent). A fast/slow threshold, label, or alert (deliberately not invented — see §2).
