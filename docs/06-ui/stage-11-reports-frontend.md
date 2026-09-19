# Stage 11 — Reports Frontend (admin)

## Status

**Done and verified in a real browser.** `/admin/reports` (hub) and
`/admin/reports/:slug` (one schema-driven viewer for all 15 reports),
gated by `REPORT_VIEW` (ADMIN/MANAGER). This is a back-office screen
set, not part of POS checkout — cashiers never hold `REPORT_VIEW`, and
their own end-of-shift view is the close-shift summary already on the
POS screen.

## 1. Stitch mockup inventory

Source: Stitch project `13465419248509126107` ("TindaFlow Store Setup &
Fiscal Configuration Module", 24 screens; design system "TindaFlow
Industrial Retail Back Office"). Eight screens are report-relevant:

| Mockup | Used for |
|---|---|
| Reports Hub | `ReportsHub.jsx` — grouped cards, search, category filter |
| Shared Report Viewer (Sales by Date Range) | Layout template for `ReportViewer.jsx`: header strip, presets + date pickers, applied-filter chips, summary cards, sticky-header table, pinned totals, pagination |
| Shared Report Viewer (Inventory On Hand) | Grouped-by-location table layout |
| Shared Report Viewer (Inventory Movement Ledger) | Movement table with type badges |
| Shared Report Viewer (Cash Variance Register) | Shortage/overage styling |
| VAT Breakdown (Statutory BIR TR-001) | `tax-breakdown` (retitled — see §3) |
| Reports Access Denied (403 Inline) | `ReportsAccessDenied.jsx`, rendered inside the shell |
| TindaFlow 15-Report Code Schema & Architecture Specification (markdown) | The registry architecture: one declarative config + one shared viewer |

Not report-related, unused: *Operational Dashboard*, *POS Terminal Login
& Shift Initialization* (both need endpoints that don't exist).

**Coverage of the 15 reports.** Five have a dedicated mockup
(`sales-by-date-range`, `inventory-on-hand`, `inventory-movement`,
`cash-variance`, `tax-breakdown`). The other ten (`daily-sales-summary`,
`sales-by-product`, `sales-by-category`, `sales-by-cashier`,
`sales-by-payment-method`, `discounts`, `voids`, `refunds`, `low-stock`,
`shifts`) have none and are rendered from the shared template.

**Mockups that do not exist yet** (none blocked the build; each was
built from the design system's tokens and can be mocked in Stitch if
wanted):

1. Report **load-error** state (network/server failure) — built as an inline alert.
2. **CSV export** failure/progress state — built as a disabled "Exporting…" button plus the same alert.
3. **Single-select entity filter** (product / category / cashier) — the mockup only shows a multi-select cashier scope.
4. **Voids / Refunds ledgers** showing requested / approved / rejected outcomes.
5. **Low-stock** triage view (only the shortfall cell style was specified).
6. **Tablet/mobile** layout of the viewer.

## 2. Architecture

`reportsRegistry.js` declares each report once (slug, title, category,
filters, columns with formats, headline-summary keys, export prefix);
`ReportViewer.jsx` renders any of them. `formatters.js` does string- and
BigInt-cents money formatting/summing — never floating point. CSV export
sends the same query with `Accept: text/csv` (the backend's real
content-negotiation contract), and downloads the blob.

`AdminLayout.jsx` was generalized (title / nav / required capability /
inline `deniedView`) so store-setup and reports share one dark shell;
store-setup behavior is unchanged.

## 3. Deliberate deviations from the mockups/spec

The Stitch spec was written without the real API and diverges from it;
the frontend follows the API and the frozen CSV contract:

- **Stack/routes**: React + plain JS (not Vue/TS); `/admin/reports/*`
  (matches the existing `/admin/*` convention, not `/back-office/*`);
  URL slugs are the real API slugs (`tax-breakdown`, `shifts`), not
  `vat-breakdown`/`shift-report`. `sitemap.md` is left as written.
- **Endpoints/export**: `/api/v1/reports/<slug>`, not
  `/api/v1/back-office/reports/<slug>/export?format=raw_iso`. The CSV is
  already raw decimals per `csv-export-contract.md`.
- **Real column keys** only. Not built because no such field exists:
  `void_number`, `refund_number`, `shift_code`, `location_name`,
  restitution method, and a signed `qty_delta` —
  `stock_movements.quantity` is always positive (DB check); direction
  comes from `movement_type`, so the viewer derives the sign from it.
  Locations show as a short id (a name would need a contract addition;
  a client-side join needs `FISCAL_CONFIGURATION_MANAGE`, which
  MANAGER lacks). Terminals/cashiers show as short ids with the full id
  on hover.
- **Entity dropdowns** (product/category/cashier) are populated from the
  unfiltered report result — no category or user list endpoints exist.
- **Footer totals** only where a sum is truthful. Not totaled:
  `sales-by-date-range`, `voids`, `refunds` (raw listings that include
  voided/rejected rows), payment-method transaction counts (a split-tender
  sale counts under each method), quantities across differently-measured
  products. The headline cards come from the server's own `summary`.
- **"Statutory BIR" framing dropped.** TindaFlow V1 is not BIR-accredited
  (architecture.md §12 / ADR-009); the report is titled "Tax / VAT
  Breakdown" and says so.
- **Not built** (no backing mechanism): Print Summary, terminal/lane
  filter, cashier multi-select, supervisor override, X-Read shortcuts,
  "cash drawer reconciled" badges.
- Client-side pagination (50 rows/page) because the API returns the
  full result set.

## 4. One backend addition

Voids/Refunds report rows had no `status`, so a REJECTED void looked
identical to an executed one. `status` and `requested_at` were added to
the **JSON rows only**, appended after the pinned columns; the CSV
columns are unchanged (asserted by a new test). Both are additive to
`ReportResult.rows`, which the frozen contract leaves open
(`additionalProperties: true`).

## 5. Verified in a real browser

Signed in as the seeded admin: hub renders all five groups; Daily Sales
Summary shows 4 transactions / ₱220.00 gross / ₱23.56 VAT — identical
to the Stage 9 Z-Reading, an independent cross-check of two separately
built aggregators; Cash Variance shows expected ₱1,465.00 / counted
₱1,230.00 / -₱235.00 short, matching the Stage 9 close; Inventory On
Hand (location group header), Low Stock (7 short) and Inventory Movement
(real checkout SALE rows as −1, a seeded receipt as +13) render
correctly; Voids shows the empty state; Sales by Cashier's dropdown
populates from the result and the applied chip comes from the server's
`filters_applied`; **Export CSV** produced a `text/csv` blob with no
error; signed in as a cashier, `/admin/reports` and a viewer URL both
show the inline 403 (principal + missing `REPORT_VIEW`), the Dashboard
hides the Reports link, and the viewer makes no report request. The
seeded cashier account was deleted afterward.

Not exercised in the browser: the remaining nine reports' rendering
(same viewer, config-only differences; backend behavior covered by
`ReportsHttpTest`), and pagination (no dev report exceeds 50 rows).

Full regression: Unit 102 + Feature 1 + Database 302 = 405 passing.
