# CSV Export Contract — TindaFlow POS API (Stage 4)

## Status
DRAFT — Stage 4 remediation pass 1 (2026-09-16). Closes the CSV-column-
stability question flagged as an open item in the initial Stage 4 draft.
**The V1 CSV layout defined here is a stable contract within `/api/v1`** —
a breaking column rename or removal requires a versioned export contract
(a new `/api/v2` or an explicit `?csv_schema=` negotiation), never a silent
change to the `/api/v1` output. Adding a new column at the end of a row is
non-breaking and may happen without a version bump; reordering, renaming,
or removing an existing column is breaking.

## Format rules (apply to every CSV export in the API, no exceptions)

- **Encoding:** UTF-8, no byte-order mark.
- **Header row:** always present, exactly one, first line.
- **Column order:** exactly as listed per export below; stable.
- **Timestamps:** RFC 3339 with timezone offset (e.g.
  `2026-09-16T14:22:10+08:00`), matching the JSON API's own timestamp
  format (api-design.md — no locale-formatted dates).
- **Dates (no time component):** `YYYY-MM-DD`.
- **Monetary values:** raw decimal strings, always 2 places, **no
  currency symbol, no thousands separator** — e.g. `1250.50`, never
  `₱1,250.50` (Stage 1 Money principle, restated for CSV specifically).
- **Quantities:** raw decimal strings, up to 3 places, matching the
  `Quantity` schema (e.g. `1.000`, `0.500`).
- **Null/empty values:** an empty field (two adjacent commas / trailing
  comma), never the literal string `null`, `N/A`, or `-`.
- **Quoting:** standard RFC 4180 quoting — a field is quoted only when it
  contains a comma, quote, or newline; embedded quotes are doubled per
  RFC 4180.
- **Delivery:** `GET` with `Accept: text/csv` on the same endpoint that
  serves `application/json` (content negotiation, not a separate URL) —
  consistent across every report and the journal export.

## Per-report column contract

| Report | operationId | Columns (in order) |
|---|---|---|
| Daily Sales Summary | `reportDailySalesSummary` | `business_date, gross_sales, discount_total, taxable_sales, vat_exempt_sales, zero_rated_sales, vat_amount, grand_total, transaction_count, non_vat_sales` |
| Sales by Date Range | `reportSalesByDateRange` | `sold_at, invoice_number, transaction_number, terminal_id, cashier_id, subtotal, discount_total, grand_total, status` |
| Sales by Product | `reportSalesByProduct` | `product_id, sku, product_name, quantity_sold, gross_sales, net_sales, tax_amount` |
| Sales by Category | `reportSalesByCategory` | `category_id, category_name, quantity_sold, gross_sales, net_sales` |
| Sales by Cashier | `reportSalesByCashier` | `cashier_id, cashier_name, transaction_count, gross_sales, void_count, refund_count` |
| Sales by Payment Method | `reportSalesByPaymentMethod` | `payment_method, transaction_count, total_amount` |
| Tax/VAT Breakdown | `reportTaxBreakdown` | `business_date, taxable_sales, vat_exempt_sales, zero_rated_sales, vat_amount, non_vat_sales` |
| Void Report | `reportVoids` | `void_id, sale_id, invoice_number, requested_by, approved_by, reason, terminal_id, fiscal_day_id, resolved_at, sale_grand_total` |
| Refund Report | `reportRefunds` | `refund_id, sale_id, invoice_number, requested_by, approved_by, reason, terminal_id, fiscal_day_id, refunded_at, refund_total` |
| Discount Report | `reportDiscounts` | `sold_at, invoice_number, line_discount_total, order_discount_total, discount_total` |
| Sales by Hour (stage 32, forward-committed) | `reportSalesByHour` | `hour, transaction_count, gross_sales, discount_total, grand_total` |
| Gross Profit (stage 32, forward-committed) | `reportGrossProfit` | `business_date, transaction_count, net_sales, cost_of_goods_sold, gross_profit, gross_margin_percent, lines_with_unknown_cost` |
| Gross Profit by Product (stage 34, forward-committed) | `reportGrossProfitByProduct` | `product_id, sku, product_name, quantity_sold, net_sales, cost_of_goods_sold, gross_profit, gross_margin_percent, lines_with_unknown_cost` |
| Product Velocity (stage 34, forward-committed) | `reportProductVelocity` | `product_id, sku, product_name, quantity_sold, transaction_count, net_sales, quantity_on_hand` |
| Inventory On Hand | `reportInventoryOnHand` | `product_id, sku, product_name, location_id, quantity_on_hand, reorder_level` |
| Low Stock | `reportLowStock` | `product_id, sku, product_name, quantity_on_hand, reorder_level, shortfall` |
| Inventory Movement | `reportInventoryMovement` | `occurred_at, product_id, sku, movement_type, quantity, reference_type, reference_id, reason, created_by` |
| Shift Report | `reportShifts` | `shift_id, terminal_id, cashier_id, opened_at, closed_at, opening_cash, declared_cash, expected_cash, variance` |
| Cash Variance Report | `reportCashVariance` | `shift_id, terminal_id, cashier_id, closed_at, expected_cash, declared_cash, variance` |
| Electronic Journal export | `journalEntryList` (`Accept: text/csv`) | `occurred_at, event_type, source_type, source_id, terminal_id, audit_event_id` |

Every monetary column above (`gross_sales`, `discount_total`,
`taxable_sales`, `vat_amount`, `grand_total`, `opening_cash`,
`declared_cash`, `expected_cash`, `variance`, `refund_total`,
`sale_grand_total`, etc.) follows the monetary formatting rule above; every
`*_at`/timestamp column follows the RFC 3339 rule; `business_date` follows
the plain-date rule.

## What Stage 5/6 may not do

- Invent a different column set/order for a report than the table above
  without an explicit contract-change decision (recorded as an update to
  this file, not silently in implementation).
- Reformat a monetary column with a currency symbol or thousands separator
  "for readability" — a store owner's spreadsheet/accounting workflow
  depends on raw decimals (Stage 1 product-vision.md's CSV export
  requirements).
- Add a column in the *middle* of an existing row (breaking); a genuinely
  new data point is appended at the end.
