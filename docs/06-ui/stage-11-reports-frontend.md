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
Fiscal Configuration Module", 26 screens before the 2026-09-19
additions; design system "TindaFlow Industrial Retail Back Office").
Ten screens plus the spec document are report-relevant:

| Mockup | Used for |
|---|---|
| Reports Hub | `ReportsHub.jsx` — grouped cards, search, category filter |
| Shared Report Viewer (Sales by Date Range) | Layout template for `ReportViewer.jsx`: header strip, presets + date pickers, applied-filter chips, summary cards, sticky-header table, pinned totals, pagination |
| Shared Report Viewer (Inventory On Hand) | Grouped-by-location table layout |
| Shared Report Viewer (Inventory Movement Ledger) | Movement table with type badges |
| Shared Report Viewer (Cash Variance Register) | Shortage/overage styling |
| Daily Sales Summary (SR-001) | `daily-sales-summary` (added to the inventory 2026-09-19; it was missed in the first pass) |
| Shared Report Viewer (Voids Audit Ledger EA-001) / (Refunds Audit Ledger EA-002) | `voids` / `refunds` layout only. Both carry invented BIR/dual-key/hash/print content and show no requested/approved/rejected outcomes, so §6 supersedes them |
| VAT Breakdown (Statutory BIR TR-001) | `tax-breakdown` (retitled — see §3) |
| Reports Access Denied (403 Inline) | `ReportsAccessDenied.jsx`, rendered inside the shell |
| TindaFlow 15-Report Code Schema & Architecture Specification (markdown) | The registry architecture: one declarative config + one shared viewer |

Not report-related, unused: *Operational Dashboard*, *POS Terminal Login
& Shift Initialization* (both need endpoints that don't exist).

**Coverage of the 15 reports.** Eight have a dedicated mockup
(`daily-sales-summary`, `sales-by-date-range`, `inventory-on-hand`,
`inventory-movement`, `cash-variance`, `tax-breakdown`, `voids`,
`refunds`). The other seven (`sales-by-product`, `sales-by-category`,
`sales-by-cashier`, `sales-by-payment-method`, `discounts`, `low-stock`,
`shifts`) have none and are rendered from the shared template.
(An earlier revision of this document listed ten, missing the Daily Sales
Summary, Voids and Refunds mockups; corrected 2026-09-19.)

**Mockups that did not exist at build time** (none blocked the build; each
was built from the design system's tokens). All six were generated in the
same Stitch project on 2026-09-19 — see §6:

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

`AdminLayout.jsx` was generalized (title / required capability / inline
`deniedView`) so store-setup and reports share one dark shell; it was
later given the sidebar/rail/drawer navigation (§7).

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

## 6. Generated mockups for the missing states (2026-09-19)

Generated in Stitch project `13465419248509126107` with the "TindaFlow
Industrial Retail Back Office" design system, then reviewed against the
real API. Stitch invented copy on several screens; the ones below were
corrected in Stitch, and the rest are listed as **ignore** so they are not
implemented.

| # | Screen (Stitch id) | Implementation delta |
|---|---|---|
| 1 | Report Viewer - Load Error States (`c1725058…`) | Distinct panels for network / 5xx / 401 / invalid range, each with **Retry**. Today only a generic alert exists (the network catch was added 2026-09-19). Ignore the request-reference id unless the server starts returning one. |
| 2 | Report Viewer - CSV Export States (`76860a49…`) | Inline dismissible export-failure banner (separate from load errors), success toast with the filename, Export disabled when there are no rows. |
| 3 | Report Viewer - Single-Select Entity Filter (`d77d3979…`, corrected copy of `a9cfd2f8…`) | Searchable combobox replacing the native select, a "no matches" state, and a stale-options hint after the date range changes. Options stay derived from the unfiltered result. |
| 4a | Voids Ledger with Outcomes (`40808743…`) | Client-side status chips with counts, outcome summary cards, neutral status legend. Statuses are `REQUESTED / APPROVED / REJECTED / VOIDED`. |
| 4b | Refunds Ledger with Outcomes (`5bb760d4…`) | Same, with `COMPLETED` in place of `VOIDED`. |
| 5 | Low Stock Triage (`7364d334…`) | Shortfall-sorted, tier badges (out of stock = 0, critical < 50% of reorder level, low = rest), stock-level bar, healthy empty state. Tiers are a display convention computed in the browser. |
| 6a | Daily Sales Summary (Tablet 768px) (`8fe8ed38…`) | Icon rail nav, 40px tap targets, **frozen first column**, scroll affordance. |
| 6b | Mobile 375px: `0788bdc9…` (key-value cards, the main deliverable) and `e3201297…` (nav drawer) | Cards per business date with an expandable VAT breakdown, sticky totals, drawer nav. |

Decisions taken (design-system rules, not new business rules): tablet
uses a frozen first column and mobile uses key-value cards, exactly as
the design system's responsive section prescribes; the voids/refunds
status filter is client-side over the loaded rows, so the frozen
contract is untouched.

**Ignore in the mockups** (no backing data or not real): the report codes
(SR-003, IV-002 …), "Filter spec / Evaluation testbed / Diagnostic board"
annotations, "Supervisor" wording, the "Direct Synchronous" badge, the
mobile board's residual strikethrough on the totals bar in
`e3201297…`, and the sample figures (they do not match dev data). The
original entity-filter (`a9cfd2f8…`) and mobile (`21bc6ba3…`) screens are
superseded duplicates left behind because Stitch's edit tool creates a
new screen.

## 7. Implementation of the mockups (2026-09-19)

Everything in §6 is implemented in `resources/js/pages/admin/reports/`
(`ReportViewer.jsx`, `ReportParts.jsx`, `EntityCombobox.jsx`,
`ReportCards.jsx`, per-report config in `reportsRegistry.js`). Points
worth knowing:

- **Filters live in the URL** (`?from=&to=&<entity>_id=`), replaced on each
  load. That is what makes "You will return to this report with your
  filters kept" true: on a 401 the panel's **Sign in** stores the current
  URL in `sessionStorage` (`tindaflow.returnTo`) and `Login.jsx` returns
  there after sign-in — only same-app `/admin/` paths are honoured.
- **Failure classification**: `apiFetch` throws `TypeError` when the
  request never completes (network) and `SyntaxError` on a non-JSON body
  such as a gateway page (treated as a server error); 401 → session,
  403 → the existing Access Denied view, 5xx → server, other → the
  server's message. The "Reference: …" line appears only if the error body
  carries `request_id`, which the API does not currently send.
- **Export** always uses the last *applied* filters, not unapplied edits
  in the date inputs, and its failure/success states are separate from
  load errors. CSV export is unaffected by the client-side status chips
  (the chip note says so).
- **Voids/Refunds** use a dedicated `outcome_badge` format (VOIDED and
  COMPLETED emerald) because `VOIDED` on a *sale* in the journal stays rose.
- **Low Stock** tiers are computed in the browser from `quantity_on_hand`
  and `reorder_level` (display convention only, noted on screen).
- **Admin shell**: the top-header layout was replaced by the sidebar the
  mockups show — persistent sidebar from `lg`, icon rail on tablets (its
  "TF" button opens the full navigation), drawer on phones (focus trap,
  Escape, scroll lock). Sections are listed only when the user holds the
  capability. `AdminLayout` no longer takes `navItems`; store setup and
  reports both use it, with sub-links for the active section.
- **Not built** from the mockups: the mockup's request-reference id and
  timestamps/annotations (see the ignore list above), and a
  "Refresh options" auto-refresh — the stale-options hint is a manual
  button by design.

Verified in a real browser on 2026-09-19 (desktop 1280, tablet 768,
phone 375): every error state (network, 5xx, non-JSON 502, 401, 403,
recovery via Retry), invalid range (no request sent), export success and
failure banners, combobox (search, no matches, keyboard, applied chip,
reset, stale hint, URL restore), voids status chips/cards/legend
(mocked rows — the dev DB has no voids), low-stock tiers/sort/bar
(mocked rows), tablet frozen first column and scroll hint, phone cards
and expander, drawer open/close, and every store-setup and report page
through the new sidebar. Not exercised: the post-sign-in return to the
report (needs a password entry) and pagination beyond one page.
