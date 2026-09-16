# Proposed Sitemap (V1)

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved 2026-09-16. No corrections required for this document; proposed for validation in Stage 7
(Frontend). Subject to change once the API contract (Stage 4) and domain
model (Stage 2) are finalized.

This is a preliminary information-architecture sketch, not final routing.
Cashier-facing screens are intentionally minimal; manager/admin screens
follow conventional CRUD patterns per the project brief.

## App shells

TindaFlow V1 is proposed as a single React SPA with two effective "modes"
gated by role and by context, not two separate apps:

- **POS mode** — full-screen, cashier-optimized, minimal chrome.
- **Back-office mode** — conventional admin layout (sidebar nav + content).

A cashier with no elevated role lands directly in POS mode after login. A
manager/admin can switch to Back-office mode.

## Route tree (proposed)

```
/login
/shift/open                      (blocking gate: must open shift before POS)

/pos                              (POS mode — cashier home)
  /pos                             cart + barcode input (default view)
  /pos/checkout                    payment screen (cash/other, change calc)
  /pos/receipt/:saleId              post-sale invoice preview / print
  /pos/lookup                      quick transaction lookup (reprint, view)
  /pos/void-request                request void/refund on a past sale

/shift
  /shift/current                   current shift status, cash in/out
  /shift/close                     declared cash entry, variance display
  /shift/history                   past shifts (manager+)

/back-office                       (Manager/Admin — conventional CRUD shell)
  /back-office/dashboard           at-a-glance sales/stock/shift summary

  /back-office/products
  /back-office/products/new
  /back-office/products/:id
  /back-office/products/import      CSV import
  /back-office/products/categories
  /back-office/products/brands

  /back-office/inventory
  /back-office/inventory/stock-on-hand
  /back-office/inventory/movements
  /back-office/inventory/receive     receive stock
  /back-office/inventory/adjust      stock adjustment (reason required)
  /back-office/inventory/low-stock

  /back-office/sales                 sales history (search/filter)
  /back-office/sales/:id              transaction detail (items, payments, audit, refunds/voids)

  /back-office/void-refund
  /back-office/void-refund/approvals  pending void/refund approvals (manager)
  /back-office/void-refund/:id         refund/void detail

  /back-office/shifts                 all shifts (manager view)
  /back-office/shifts/:id

  /back-office/reports
  /back-office/reports/daily-sales-summary
  /back-office/reports/sales-by-date-range
  /back-office/reports/sales-by-product
  /back-office/reports/sales-by-category
  /back-office/reports/sales-by-cashier
  /back-office/reports/sales-by-payment-method
  /back-office/reports/vat-breakdown
  /back-office/reports/void-report
  /back-office/reports/refund-report
  /back-office/reports/discount-report
  /back-office/reports/inventory-on-hand
  /back-office/reports/low-stock
  /back-office/reports/inventory-movement
  /back-office/reports/shift-report
  /back-office/reports/cash-variance

  /back-office/users                  (ADMIN only)
  /back-office/users/new
  /back-office/users/:id

  /back-office/settings               (ADMIN, limited MANAGER)
  /back-office/settings/store          business identity + BIR-readiness fields (Module B)
  /back-office/settings/terminals
  /back-office/settings/invoice-template
  /back-office/settings/audit-log      read-only view of audit_events / electronic journal
```

## Navigation notes

- **POS mode has no persistent sidebar.** The barcode input retains focus by
  default; navigating away from `/pos` (e.g., to look up a transaction)
  should not lose an in-progress cart without explicit confirmation.
- **Shift gate:** any route under `/pos` requires an open shift for the
  logged-in cashier/terminal; the app redirects to `/shift/open` otherwise.
- **Back-office is role-gated per route**, not just per button — matching
  the project brief's requirement to implement explicit authorization
  policies rather than hiding UI elements as the only control (Module A).
- **Audit log view** is read-only in the UI; there is no route that allows
  editing or deleting audit/electronic-journal entries, by design.
- **No `DELETE`-style destructive UI** exists anywhere for finalized sales;
  the only routes that touch a completed sale are the void/refund workflow
  routes.

## Open questions for Stage 7 validation

- Whether `/back-office/dashboard` is needed in V1 or whether Daily Sales
  Summary report serves as the de facto dashboard (leaning: keep V1 minimal,
  revisit).
- Whether transaction lookup/reprint (`/pos/lookup`) should be reachable
  from POS mode directly (fast, cashier-facing) in addition to
  `/back-office/sales` (manager-facing) — current assumption is yes, per
  Module D/J requirements, with permission-gated reprint.
