# Proposed Sitemap (V1)

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved 2026-09-16. Stage 7 validation pass performed 2026-09-18 (see **Stage 7 validation** below): both open questions from the original draft are now resolved against competitor reference research; the route tree itself required no structural changes. Subject to further change once the remaining backend controllers (Shift/FiscalDay, Products, Inventory, Reports, Users, Store Settings) exist to build the corresponding screens against — see `docs/06-ui/stage-7-frontend-initialization.md`.

This is a preliminary information-architecture sketch, not final routing.
Cashier-facing screens are intentionally minimal; manager/admin screens
follow conventional CRUD patterns per the project brief.

**Implementation status (2026-09-18):** only `/login` and `/` (authenticated
landing shell) are real, working routes today (Stage 7 pass 1). Every other
route below remains proposed IA, not yet buildable — each is blocked on a
backend controller that does not exist yet (see
`docs/06-ui/stage-7-frontend-initialization.md` §2 for the exact list). This
document describes where those screens will live once their backends exist,
not what currently ships.

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

## Stage 7 validation (2026-09-18)

Per `stage-7-frontend-initialization.md` §5, this sitemap's two open
questions were left unresolved pending a dedicated pass. That pass is this
one: both are resolved below using targeted competitor reference research
(per this project's standing research directive), not by building the
screens themselves (both remain backend-blocked, see Implementation status
above). Reference sources: UTAK help center (utak.io/help) and StoreHub help
center (care.storehub.com/en) — both are customer-support documentation
sites, not primary/authoritative sources, so findings are used only to
validate an IA pattern choice, never as a compliance or feature claim.

### Resolved: `/back-office/dashboard` is kept

**Decision: keep a distinct `/back-office/dashboard` route in V1**, separate
from `/back-office/reports/daily-sales-summary`.

Both reference products treat "dashboard" and "reports" as two different
features, not one collapsed into the other:

- UTAK's help center documents a distinct "Dashboard Computations" section
  (Total Net Sales, Discounts, Transaction Count, COGS, Profit, Refunds) and
  its own FAQ language ("monitor your business... through a... back office
  dashboard") names dashboard and reports separately; its Z-Reading
  (end-of-day report) is documented as a separate artifact.
- StoreHub's "How to Understand Your Dashboard Sales Comparisons" article
  describes a BackOffice dashboard showing Today/This-Week/This-Month sales
  comparisons at a glance, while directing users to a separate **Reports**
  or **Export** area "for custom date ranges or detailed reports."

This matches the original leaning in the V1 brief (an at-a-glance summary
distinct from a report you have to run) and gives a manager/owner persona
(personas.md Persona 2 — wants to "know, at a glance, what sold today and
whether the cash matches" without running a report) a faster path than
navigating into Reports. No route tree change is needed — `/back-office/
dashboard` was already in the proposed tree; this closes it as confirmed
rather than provisional.

### Resolved: `/pos/lookup` stays reachable from POS mode directly

**Decision: confirmed — transaction lookup/reprint and the void/refund
*request* path remain reachable directly from POS mode**, in addition to
`/back-office/sales` (manager-facing, full history/detail/approval).

Both reference products default cashier-initiated reprint, void, and refund
to the POS/register screen itself, not the back-office:

- StoreHub's receipt-management and cancel/refund articles show these
  actions performed from the POS screen's own Transactions list, with
  BackOffice-side cancellation documented explicitly as a **fallback that
  StoreHub itself warns against** ("is not recommended because it may cause
  Sales Over Time and Shift Reports to not tally").
- UTAK's tablet-side void/refund guide shows the same action performed
  directly on the register, gated by a refund passcode rather than requiring
  a back-office trip.

This confirms the original assumption (Module D/J: fast, cashier-facing,
permission-gated) and adds one concrete architectural lesson worth recording
for the eventual void/refund implementation: **StoreHub's own documented
caution about back-office-initiated corrections causing report
reconciliation drift is exactly the class of bug TindaFlow's Module K design
already structurally avoids** — every void/refund is an explicit new
reversal transaction referencing the original (never a mutation, never a
different code path depending on where it was initiated), so there is no
"BackOffice path skips the ledger" failure mode to inherit. No route tree or
domain-model change follows from this finding; it is recorded here as
corroborating evidence for an already-locked invariant (see
`docs/02-domain/invariants.md`), not a new requirement.

### Not otherwise changed

General back-office menu structure in both reference products (Products,
Inventory, Reports, Settings, Employees/Users, plus a POS-vs-BackOffice
shell split) maps onto categories already present in this sitemap's route
tree; no missing top-level category was identified. The route tree above is
unchanged by this validation pass.

### Confidence and sourcing note

UTAK and StoreHub help-center content is customer-support documentation, not
an API/engineering reference, and was read at the article level (no
authenticated access to either product's actual back office). Findings above
are used only to validate an information-architecture pattern (dashboard vs.
reports; POS-side vs. back-office-side correction workflows) and should not
be read as claims about either competitor's full feature set, pricing, or
compliance status — for that, see the existing
[market-comparison.md](../01-research/market-comparison.md), which covers a
different set of reference products for product-positioning purposes and
carries its own sourcing caveats.

## Revision log

- **2026-09-16 (initial):** Stage 1 sitemap drafted as a preliminary
  information-architecture sketch, with two open questions flagged for
  Stage 7 validation.
- **2026-09-18 (Stage 7 validation pass):** Both open questions resolved
  (dashboard kept as a distinct route; POS-mode lookup/void/refund
  reachability confirmed) using UTAK/StoreHub help-center reference
  research. Route tree unchanged. Implementation-status note added
  reflecting Stage 7 pass 1's actual shipped routes (`/login`, `/`) versus
  this document's proposed, still-backend-blocked routes.
