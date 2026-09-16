# V1 Scope

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved 2026-09-16 with corrections applied (tax model, terminal/multi-terminal, invoice numbering & fiscal identity, CSV export format — see Revision log below).

This document defines what TindaFlow POS V1 **is** and **is not**. It is the
authoritative scope reference for Stage 2 onward. Anything not listed as
in-scope below requires an explicit scope-change decision, not silent
addition during implementation.

## In scope — V1 modules

These map directly to the modules specified in the project brief:

| Module | Summary |
|---|---|
| A — Authentication & Users | Admin / Manager / Cashier roles, explicit authorization policies |
| B — Store Settings | Business identity fields + optional/inactive BIR-readiness fields |
| C — Product Catalog | SKU/barcode-based catalog, categories, CSV import/export |
| D — POS Cashier Screen | Barcode-first cart building, keyboard-first operation |
| E — Payment | Cash (authoritative), GCash/Maya/Card/Other (manually recorded), multi-payment-row schema |
| F — Sales Transaction | Immutable completed-sale aggregate with historical snapshots |
| G — Invoice | 80mm-printable invoice, canonical JSON representation, reprint-safe |
| H — Cashier Shift | Open/close shift, cash in/out, declared-vs-expected variance |
| I — Inventory | Stock ledger (movements), stock balances, adjustments with required reason |
| J — Sales History | Search/filter completed transactions, full transaction detail view |
| K — Void and Refund | Explicit reversal transactions; original sale never mutated |
| L — Reports | 15 reports listed in the brief, CSV export (format locked below), generated from ledger data |

## Explicitly in scope — architectural (not user-facing) requirements

- Modular monolith: Laravel (PHP) REST API + PostgreSQL + React/TypeScript/Vite SPA.
- Decimal-safe money handling end-to-end (PostgreSQL `NUMERIC`, PHP decimal
  math, decimal-string JSON serialization). One canonical Money value object.
- Server-authoritative recalculation of every monetary total at checkout.
- Append-only audit event log for all sensitive actions.
- Inventory as a movement ledger, with stock-on-hand as a derived/materialized value.
- Atomic, collision-safe sequential invoice numbering — see **Invoice
  numbering & fiscal identity** below; this is locked in as a Stage 2
  requirement, not left open.
- Idempotent sale finalization (idempotency key + DB uniqueness constraints).
- Docker Compose deployment (Nginx + Laravel app + PostgreSQL), for on-premise
  mini-PC, VPS, or store LAN server.
- LAN-first operation: checkout must not depend on internet connectivity.
- Architectural hooks for future BIR accreditation (see below) — present but inert.
- Multi-terminal LAN support as a V1 architectural requirement — see
  **Terminal & multi-terminal decision** below.
- Explicit, non-default tax registration configuration — see **Tax model
  decision** below.

### Tax model decision (locked before Stage 2)

TindaFlow V1 is **not** inherently a VAT or a Non-VAT product. Store tax
registration is an explicit configuration, never a silent default:

```
TaxRegistrationType
- VAT
- NON_VAT
```

- Production onboarding **requires** the owner to choose one; there is no
  default value.
- The domain must support per-line tax classification independent of the
  store-level registration type:
  ```
  VATABLE
  VAT_EXEMPT
  ZERO_RATED
  NON_VAT
  ```
- Tax registration/configuration is **effective-dated** — a business can
  change registration status over time, and that change must not rewrite or
  reinterpret historical transactions. Every completed sale stores an
  immutable **tax snapshot** (rate, classification, registration type in
  effect at the time of sale), per the project's Section 2.F
  historical-snapshot principle.
- **Demo/seed data** may use a single Non-VAT convenience store as the
  default sample dataset for developer convenience. This is a *fixture*
  choice, not a product default.
- The **automated test suite must cover both VAT and Non-VAT store
  scenarios** — a passing suite that only exercises one registration type is
  not acceptable coverage for the checkout/tax/invoice code paths.
- **Product policy vs. legal minimum:** TindaFlow generates an Invoice
  record for every completed POS sale, for both VAT and Non-VAT stores,
  regardless of sale amount. This is stricter than the legal minimum for
  Non-VAT sellers (see [BIR-002](../01-research/bir-reference-register.md))
  and is a deliberate application-level simplification of the checkout/audit
  model — it must never be described as "the BIR threshold" in documentation
  or UI.

### Terminal & multi-terminal decision (locked before Stage 2)

Multi-terminal LAN support is a **V1 architectural requirement**, even
though the first deployment/demo may run a single configured terminal. The
domain model, database schema, and concurrency strategy must support
multiple terminals sharing one store's data from day one:

```
Store
 ├── Terminal 01
 ├── Terminal 02
 └── Terminal 03
        |
        └── Shared PostgreSQL
```

This constrains Stage 2 design in at least these areas — none of them may
assume a single terminal:

- invoice number allocation (see **Invoice numbering & fiscal identity** below)
- cashier shift lifecycle (a shift belongs to a cashier+terminal pair, not just a cashier)
- terminal identity (a first-class concept, not implied by session/login state)
- idempotency (sale-finalization idempotency keys must be safe across concurrent terminals)
- concurrent sale finalization (two terminals checking out simultaneously must never collide)
- inventory movement writes (concurrent stock deductions from multiple terminals)
- cash drawer / cash movement events (attributed to a specific terminal)
- audit logs (terminal is a required dimension on audit/journal entries)
- future X/Z-style reporting (per-terminal and per-store rollups)

Retrofitting concurrency and per-terminal identity after building
single-terminal assumptions into Stage 2 is explicitly rejected as an
approach — invoice sequencing in particular is one of the riskiest areas to
fix after the fact, so it is designed for concurrency from the start.

### Invoice numbering & fiscal identity (locked before Stage 2)

Stage 2 must model **Terminal**, **SoftwareInstallation** (or an equivalent
fiscal-installation-identity concept), and **InvoiceSeries** as explicit,
separate domain concepts — none of them may be collapsed into `Shift` or
`Store`.

A `Terminal` must eventually be capable of carrying (not all fields
mandatory in V1, but the concept must exist so BIR-readiness fields have
somewhere to live per-terminal rather than only per-store):

```
terminal_id
terminal_code
store_id
machine_serial_number
software_version
MIN
PTU metadata
accreditation metadata
status
activated_at
```

**Prohibited design:** a naive `stores.next_invoice_number` column (or
equivalent single-row counter) as the sole authoritative numbering
mechanism. This does not survive concurrent checkout from multiple
terminals and cannot express multiple concurrent series.

**Required instead:** an explicit `InvoiceSeries` aggregate, e.g.:

```
InvoiceSeries
--------------
id
store_id
series_code
prefix
current_number
starting_number
ending_number
status
version
```

capable of safely serving concurrent allocation requests from multiple
terminals (optimistic/pessimistic locking or an atomic DB sequence — the
specific mechanism is a Stage 2/5 decision, but the aggregate boundary is
locked in now).

**Non-negotiable invariant:** once an invoice number has been allocated to a
finalized transaction, it can never be reused, reassigned, renumbered, or
silently removed. This is the same invariant already stated in Section 2.F
of the governing brief; this section exists to make sure Stage 2 designs
`InvoiceSeries` as the mechanism that enforces it, rather than an ad hoc
counter.

## Explicitly out of scope — V1 (Non-Goals)

Per the project brief, none of the following will be built in V1:

- Full accounting / general ledger
- Payroll / HRIS
- Restaurant table management / kitchen printing
- Online ordering / e-commerce storefront
- Complex loyalty or CRM programs
- Advanced/complex promotions engine
- Franchise / multi-tenant management
- Direct payment gateway integration (GCash/Maya/card processing APIs)
- Direct BIR EIS (sales data transmission) integration
- AI assistant features
- Multi-country taxation
- Native mobile app
- Full browser-offline transaction sync (PWA offline-first is a later phase; V1 is LAN-online/cloud-optional, not offline-capable in the browser)

These are candidates for **Phase 2** (supplier management, purchase orders,
goods receiving, stocktake, batch/expiry, barcode label printing,
promotions, loyalty, customer accounts, multi-store, inter-store transfers,
GCash integration, customer display, ESC/POS direct printing, backup
automation, central remote dashboard) or **Phase 3** (BIR accreditation
preparation — see below), not V1.

## BIR posture for V1 (important — read before making claims about compliance)

TindaFlow V1:

- **Is not** BIR-accredited.
- **Does not** claim a Permit to Use (PTU), Machine Identification Number
  (MIN), or accreditation number anywhere in the product.
- **Does** include configurable-but-optional fields for these values in Store
  Settings (Module B) so that when accreditation is actually completed, the
  values can be entered without a schema change.
- **Does** implement the architectural controls (immutability, audit trail,
  sequential invoice numbers, inventory ledger, sales-transmission
  abstraction) that a future accreditation effort will need, because
  retrofitting these into a live transaction system is far riskier than
  building them in from the start.
- **Will not** have specific legal/regulatory claims invented. Every
  compliance-relevant statement is tracked in
  [bir-reference-register.md](../01-research/bir-reference-register.md) with
  a source citation, and anything not verifiable against a primary BIR
  source is marked `BIR-REVIEW-REQUIRED` rather than assumed.

Phase 3 (formal accreditation) is out of scope for V1 delivery and will only
be scoped in detail after a dedicated compliance-gap assessment, per Section
14 of the project brief.

## Definition of Done reference

See Section 19 of the governing project brief for the full Definition of
Done. Summarized: a cashier can complete the full sell → pay → invoice loop
end-to-end; a manager can run the back-office (products, stock, sales,
approvals, reports, shifts); and the system guarantees no duplicate invoice
numbers, immutable finalized transactions, auditable corrections, accurate
stock movements, server-authoritative totals, recoverable PostgreSQL data,
LAN-only operability, and no destructive deletion of accounting/audit
history.

## Report export format (locked before Stage 2)

V1 report export is **flat CSV, one report per file**. No XLSX/multi-sheet
workbooks in V1 (Phase 2 candidate). Every export must follow:

- UTF-8 encoding
- Stable, documented column headers (do not silently rename/reorder columns across releases)
- ISO-8601 dates and timestamps
- Raw decimal values in monetary columns — e.g. `1250.50`, never a formatted
  currency string like `₱1,250.50` — so exports are directly usable by
  Excel, Google Sheets, and accounting/import tooling without a cleanup step
- One report per file (no combined multi-report exports in V1)

## Resolved Stage 1 decisions (see revision log)

The following were tracked as open questions during the initial Stage 1
draft and have since been resolved as locked decisions, incorporated above:

- **Tax model** — resolved as explicit `TaxRegistrationType` configuration, not a VAT/Non-VAT default. See **Tax model decision**.
- **Terminal support** — resolved as a V1 architectural requirement (multi-terminal-capable from day one, even if the first deployment configures only one). See **Terminal & multi-terminal decision**.
- **Report export format** — resolved as flat CSV per report, per the spec directly above.
- **Source control** — resolved as: initialize Git now, at the Stage 1 baseline, before Stage 2 begins (see repository root `README`/commit history for the `stage-1-baseline` tag).

## Revision log

- **2026-09-16 (initial):** Stage 1 scope drafted with open questions on tax model, terminal support, and export format.
- **2026-09-16 (correction pass, per owner review):** Tax model, terminal/multi-terminal, invoice numbering & fiscal identity (Terminal/SoftwareInstallation/InvoiceSeries as explicit Stage 2 domain concepts), and CSV export format locked in as decisions rather than left open. Git initialized and Stage 1 baseline tagged `stage-1-baseline` following this correction pass.
