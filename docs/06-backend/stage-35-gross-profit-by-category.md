# Stage 35 — Gross Profit by Category

## Status

**Done and tested**, backend, contract, and the admin screen (schema-driven, one registry entry). No frozen file
edited and no `stage-*-baseline` tag moved; `scripts/validate-baselines.sh` holds. `reportGrossProfitByCategory` is a
**wholly new operation, forward-committed** under the same governance as stages 25/26/29/32/34.

## 1. Why this was built

The one item Stage 34's own "Not built" section named: gross profit by category, the third and last report in the
family Stage 32 started (date, product, category). It closes out the Qtech-comparison report gaps entirely.

## 2. What it does

The same `net_sales`/`cost_of_goods_sold`/`gross_profit`/`gross_margin_percent`/`lines_with_unknown_cost` computation
`grossProfit()` and `grossProfitByProduct()` already share (via the `profitFigures()` helper Stage 34 factored out),
grouped by merchandise category instead. The join shape is lifted directly from `salesByCategory` (Stage 10):
`LEFT JOIN categories`, since a product may have none — a product with no category groups under a `null`
`category_id`/`category_name`, exactly as `salesByCategory` already does, never silently dropped from the report or
folded into a fabricated "Uncategorized" category_id. (The frontend labels that row "Uncategorized" for display only;
the API value stays `null`.)

Columns: `category_id, category_name, quantity_sold, net_sales, cost_of_goods_sold, gross_profit,
gross_margin_percent, lines_with_unknown_cost`.

## 3. Verification

- New test (`tests/Database/ReportsHttpTest.php`, +1): two products in one category and one uncategorized product
  group correctly (category totals sum both products; the uncategorized product gets its own `null`-keyed row); a
  voided sale is excluded. Full regression: Unit+Feature 156, Database 660 (1 new), Pint clean, frontend builds.
- Verified in a real browser against real sales data rung earlier this session: the single "Grocery" category row
  summed to ₱90.00 net sales / ₱40.00 COGS / ₱50.00 gross profit — matching the date-bucketed Gross Profit and the
  per-product Gross Profit by Product totals exactly (all three reports read the same underlying sales, so they
  agree by construction). CSV export checked directly against the pinned column order.

## 4. Not built

Nothing further flagged in this family; the three-way gross-profit breakdown (date, product, category) is now
complete.
