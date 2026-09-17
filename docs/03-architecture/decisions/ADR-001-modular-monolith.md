# ADR-001: Modular Monolith Application Architecture

## Status
Accepted — Stage 3, 2026-09-16.

## Context
TindaFlow must run reliably on a single on-premise mini-PC, a small VPS, or
a store LAN server, operated (or contracted to be operated) by a local IT
technician, not a dedicated platform team. The governing brief mandates
"modular monolith... do not introduce microservices" (project brief §1, §7)
as a non-negotiable constraint, and Stage 2's domain model already assumes
a single relational database is the authoritative source of truth for
every financial invariant (immutable sales, append-only ledgers, atomic
invoice allocation). A distributed architecture would need to solve
cross-service transactional consistency for exactly the invariants Stage 2
already solved with a single ACID database transaction — solving that
problem twice, for no V1 requirement that needs it, is not justified.

## Decision
TindaFlow V1 is a single Laravel application (one deployable PHP process
group, one PostgreSQL database) organized internally into explicit logical
modules with defined ownership and allowed dependency directions:

```
Identity & Authorization   (owns: user, role/capability checks)
Catalog                    (owns: category, brand, product, product_barcode)
Inventory                  (owns: inventory_location, stock_movement, stock_balance)
Checkout / Sales           (owns: sale, sale_item, invoice, invoice_series)
Payments                   (owns: payment)
Cashier / Shift            (owns: shift, cash_movement, x_reading, fiscal_day, z_reading)
Fiscal                     (owns: tax_registration, fiscal_installation, terminal_fiscal_installation, store_settings' BIR fields)
Reporting                  (owns: no tables — reads other modules' authoritative tables)
Audit                      (owns: audit_event, electronic_journal_entry)
Administration             (owns: store, store_settings, terminal)
```

**Allowed dependency direction:** modules may depend "downward" on
Identity & Authorization, Catalog, and Audit from anywhere; Checkout/Sales
depends on Catalog, Inventory, Cashier/Shift, and Fiscal to finalize a sale,
and Payments and Audit are written to as part of that same operation.
Reporting depends on (reads from) every other module but nothing depends on
Reporting. No module other than Checkout/Sales may create a `sale` row; no
module other than Cashier/Shift may create a `shift`/`fiscal_day` row; no
module other than Audit may write `audit_event`/`electronic_journal_entry`
rows directly (other modules call a shared Audit-module service to record
an event, they never `INSERT` into those tables themselves) — this mirrors
the "electronic journal is a projection of authoritative events, written by
the same transaction, never a second independent ledger" rule from Stage 2.

Module boundaries are enforced by code organization (namespaces/folders)
and code review discipline, not by network calls or separate deployments —
a "module" here is an internal seam for maintainability and reasoning about
dependencies, not a service boundary.

## Alternatives Considered
- **Microservices per module** — rejected outright per the governing brief.
  Would require distributed transactions or eventual consistency for
  exactly the invariants (atomic invoice allocation, immutable sale +
  ledger + journal writes) that a single-database transaction already
  guarantees for free, and would multiply operational complexity for a
  single-store deployment with no current multi-team ownership need.
- **A single undifferentiated Laravel app with no internal module
  boundaries** — rejected because it would make the "no module other than
  X may write table Y" rules unenforceable by convention alone as the
  codebase grows, and would make Stage 4's API boundary design harder to
  reason about.
- **Generic repository/service abstraction layers wrapping every Eloquent
  model** — rejected per the governing brief's anti-over-engineering
  principle. A repository interface that exists only to wrap
  `Product::find()` with no additional domain behavior adds an indirection
  with no value; abstractions are introduced only where they encode real
  domain logic (e.g., a `CheckoutService` that owns the transaction
  boundary in ADR-003, or a `MoneyCalculator` in §11 of architecture.md),
  not as a blanket pattern applied to every table.

## Consequences / Trade-offs
- **Positive:** one deployable unit, one database connection pool, one set
  of migrations, trivial local development setup, no network hop between
  "services" for a checkout that already needs strict transactional
  consistency.
- **Positive:** module boundaries still give a path to extraction later
  (e.g., Reporting could become a read-replica-backed service in a future
  multi-store phase) without a V1 rewrite, because the boundaries already
  exist as code seams.
- **Trade-off:** module boundaries are not compiler/runtime-enforced in
  PHP the way a service boundary would be; discipline (code review,
  architecture tests if introduced later) is required to keep "Checkout/
  Sales is the only writer of `sale`" true in practice, not just on paper.
- **Trade-off:** all modules share one PostgreSQL instance's resources; a
  runaway report query could, in principle, contend with checkout query
  performance. Mitigated by the indexing/read-pattern guidance in
  architecture.md §20 rather than by physical separation, since V1's scale
  (a single store) does not justify a read replica.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 4 (API boundary design should mirror these module
boundaries), Stage 6 (backend implementation folder structure).
