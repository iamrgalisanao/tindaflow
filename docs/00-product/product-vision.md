# Product Vision — TindaFlow POS

## Owner
ABBADev IT Solutions

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved 2026-09-16. No corrections required for this document.

## One-line vision
A fast, reliable, self-hosted Point-of-Sale and inventory system built for
Philippine convenience stores, sari-sari-plus shops, and minimarts — designed
so a cashier never has to think about the software while ringing up a
customer, and so the business owner never has to worry about losing a sale
record.

## Problem statement

Philippine convenience-store and minimart operators (single stores up to small
multi-terminal shops) are underserved by existing POS options:

- **Generic cloud POS products** (e.g. Loyverse) are easy to start with but
  assume reliable internet, push data to a vendor's cloud by default, and are
  not built around Philippine invoicing rules or a path to BIR accreditation.
- **Heavyweight ERPs with POS modules** (e.g. Odoo, ERPNext) offer depth but
  require significant setup, hosting expertise, and configuration effort that
  is disproportionate to a single small store's needs.
- **Older self-hosted POS tools** (e.g. uniCenta) are self-hosted and
  offline-capable but are not designed for Philippine tax/invoicing
  requirements and have limited modern web/PWA tooling or active
  development.

Store owners need something in between: **simple enough to run on one PC in
the store, fast enough that a queue of customers never backs up, honest about
what it does and doesn't do for tax compliance today, and architected so it
does not need to be replaced when formal BIR accreditation becomes a
requirement for the business.**

## Vision statement

TindaFlow POS is the point-of-sale system a Philippine convenience store can
install on a single local machine (or small LAN of terminals) today, trust
with every peso of every sale from day one, and grow with — from a single
cashier counter to a BIR-accredited, multi-terminal operation — without ever
needing to migrate transaction history or retrain staff on a new system.

## Product principles

1. **Cashier speed is sacred.** Every design decision on the POS screen is
   evaluated against: does this make scan-scan-scan-pay-print slower? If yes,
   it does not ship as a default behavior.
2. **Money and inventory are ledgers, not fields.** A number on screen
   (today's sales, stock on hand) is always a materialized view derivable
   from an append-only history of transactions and movements — never the
   sole source of truth.
3. **Finalized means finalized.** Once a sale is completed and an invoice is
   issued, the system does not edit or delete it. Every correction is a new,
   audited transaction that references the original.
4. **Self-hosted and LAN-first.** The store's ability to sell must not depend
   on the store's internet connection. Cloud services are optional
   enhancements, never checkout dependencies.
5. **Compliance-honest, not compliance-theater.** TindaFlow does not claim
   BIR accreditation, PTU numbers, or MIN values it does not have. Every
   BIR-related field is present, empty, and clearly labeled as inactive until
   the business actually completes accreditation. See
   [scope.md](scope.md) and the compliance register in
   [bir-reference-register.md](../01-research/bir-reference-register.md).
6. **Boring architecture, not a boring product.** Modular monolith, proven
   stack (Laravel/PostgreSQL/React), no microservices, no speculative
   abstractions ahead of an actual second use case. See
   [scope.md](scope.md) for what is deliberately excluded from V1.

## Target users

See [personas.md](personas.md) for detailed personas: Cashier, Store
Manager/Owner, and (implicitly, for future phases) Accountant/Bookkeeper and
IT Installer.

## Success looks like (V1)

- A cashier can complete a full transaction (login already done, shift
  already open) using only a barcode scanner and keyboard, in well under the
  time it takes on a typical existing till.
- A store owner can close a shift, see a report, and trust that the numbers
  reconcile to the cash in the drawer.
- No transaction is ever silently lost, edited, or duplicated, even under
  double-clicks, network blips on the LAN, or concurrent checkouts at two
  terminals.
- The system runs entirely on a local machine/LAN with zero functional
  dependency on internet access for the core sell-scan-pay-print loop.
- The codebase and data model are structured so that a future BIR
  accreditation effort is a compliance and certification project, not a
  rewrite.

## Non-vision (explicitly out of scope for V1)

Full accounting/GL, payroll, e-commerce, loyalty/CRM, multi-country tax,
direct payment gateway integration, direct BIR EIS integration, AI
assistants, complex promotions engines. See [scope.md](scope.md) for the full
non-goals list and rationale.

## Relationship to BIR accreditation

TindaFlow V1 is **not** BIR-accredited and will not represent itself as such
anywhere in the UI, invoice templates, or documentation. It is built with
architectural hooks (append-only ledgers, immutable finalized sales, audit
trail, sequential invoice numbering, a pluggable sales-transmission
abstraction, configurable-but-empty BIR metadata fields) so that a future,
deliberate accreditation phase (Phase 3, see
[roadmap](../09-delivery/roadmap.md) — to be created in Stage 9) is a matter
of certification, testing, and enabling features that already exist in
skeleton form — not a re-architecture. Every specific legal claim about what
BIR requires is tracked with a source citation in
[bir-reference-register.md](../01-research/bir-reference-register.md) and
marked `BIR-REVIEW-REQUIRED` wherever it has not been confirmed against a
primary source or professional (tax lawyer / accredited CAS consultant)
review.
