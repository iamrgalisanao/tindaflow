# ADR-006: Versioned Invoice Snapshot, Relational Fields for Query, JSON for Rendering

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2 defined `invoice.invoice_snapshot_json` as the complete, immutable
record of everything relevant at issuance (seller/buyer identity, fiscal
metadata, items, tax, payments), plus three flat columns promoted for
query convenience. Stage 3 must define the snapshot's internal schema
discipline (so it remains renderable as the application evolves) and
confirm it is never used as the reporting/analytics data source.

## Decision
`invoice_snapshot_json` carries an explicit `schema_version` integer field
as its first-class citizen, e.g.:

```json
{
  "schema_version": 1,
  "seller": { "registered_name": "...", "tin": "...", "address": "...", "branch_code": "...", "vat_status": "VAT" },
  "buyer": { "name": null, "address": null, "tin": null, "business_style": null },
  "invoice": { "uuid": "...", "transaction_number": "...", "invoice_number": "...", "series_code": "...", "issued_at": "..." },
  "terminal": { "terminal_code": "...", "fiscal_installation": { "min": null, "ptu_number": null, "accreditation_number": null, "software_version": "..." } },
  "items": [ { "line_number": 1, "sku": "...", "name": "...", "quantity": "1.000", "unit_price": "45.00", "gross_line_amount": "45.00", "line_discount_amount": "0.00", "allocated_order_discount_amount": "0.00", "net_line_amount": "45.00", "tax_classification": "VATABLE", "tax_rate": "0.12", "taxable_base": "40.18", "tax_amount": "4.82" } ],
  "tax_summary": { "taxable_sales": "40.18", "vat_exempt_sales": "0.00", "zero_rated_sales": "0.00", "vat_amount": "4.82" },
  "payments": [ { "method": "CASH", "amount": "50.00", "reference_note": null } ],
  "totals": { "subtotal": "45.00", "discount_total": "0.00", "grand_total": "45.00" }
}
```

**Rendering rule:** the invoice-rendering component (both the original
print and every reprint) dispatches on `schema_version` to select the
matching renderer/template. When a schema change is ever needed (a new
required field, a restructured shape), it ships as `schema_version` `N+1`
with its own renderer; renderer `N` is retained and continues to serve
every historical invoice with `schema_version = N`, unmodified, for as long
as any such invoice exists (i.e., effectively forever, given no automated
deletion — ADR-008/Stage 2 invariant #51). Renderers are pure functions of
`(schema_version, snapshot_json) → HTML`; they never consult the database
for anything beyond the snapshot itself.

**Relational fields are the query surface; the JSON is not.** Every field
needed for search, filtering, or reporting (invoice number, issued date,
seller name, tax registration type at issuance, sale/terminal/cashier
foreign keys) is a real indexed column on `invoice` or reachable via
`sale`/`sale_item` — reports (architecture.md §20) never `->>` into
`invoice_snapshot_json` to answer a query. The JSON's only two consumers
are: (a) rendering a specific invoice (print or reprint) by its own `id`,
and (b) a future structured-e-invoice export (Section 5.10 of the
governing brief) keyed by that same `id`.

## Alternatives Considered
- **No `schema_version`, just evolve the JSON shape in place** — rejected:
  without a version marker, a future code change to the renderer would
  have no way to distinguish an old-shape snapshot from a new one, forcing
  defensive "does this field exist" checks scattered through the renderer
  indefinitely, or worse, silently mis-rendering old invoices.
  `schema_version` makes the renderer's job exact ("if 1, use renderer 1")
  rather than heuristic.
  - Note re: DB migrations — because `invoice_snapshot_json` is JSONB, a
    schema-version bump does **not** require a database migration to add
    columns; it only requires shipping a new PHP renderer class. This is
    specifically why JSONB (semi-structured, self-describing) was chosen
    for this field rather than a further-normalized set of relational
    tables for invoice line detail — the historical rendering shape needs
    to be frozen per-document, which a versioned blob does naturally and a
    fully-normalized schema (shared across all historical invoices) does
    not.
- **Store only relational fields, generate the printable invoice
  on-the-fly from current `sale`/`sale_item`/`store_settings` data** —
  rejected outright: this is precisely the bug Stage 2's invoice-snapshot
  requirement exists to prevent (a reprint must never re-derive from
  today's configuration — Stage 2 invariant #16). A later `store_settings`
  edit or `fiscal_installation` change must never alter what a historical
  invoice displays.
- **Use the JSON snapshot as the reporting data source** (parse it in bulk
  for analytics) — rejected: JSONB querying at scale is slower and harder
  to index well than normalized relational columns for the same data, and
  conflates two different concerns (exact historical rendering vs.
  aggregate analytics). Reports read relational fields; the snapshot is
  reserved for rendering one document at a time.

## Consequences / Trade-offs
- **Positive:** invoice rendering is exact and stable forever, independent
  of future schema evolution, application upgrades, or configuration
  changes.
- **Positive:** reports remain fast and indexable, unaffected by JSON
  payload size or shape.
- **Trade-off:** the same conceptual data (e.g., a line item's tax amount)
  exists in two places — the relational `sale_item.tax_amount` column and
  the JSON snapshot's `items[].tax_amount` — by design, not by accident:
  Stage 2 already established this duplication is intentional ("duplicating
  the fact, not the authority" — the relational row is authoritative for
  queries, the JSON is authoritative for rendering a specific document
  exactly as issued). This must not be "cleaned up" into a single source
  later without reintroducing the original problem.
- **Trade-off:** every renderer version must be kept in the codebase
  indefinitely (dead code from a feature standpoint, but load-bearing for
  historical correctness) — a small, known, accepted cost.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 5 (`invoice_snapshot_json` JSONB column), Stage 6
(`InvoiceSnapshotBuilder` + versioned renderer classes), Stage 7 (print/
reprint UI reads only the snapshot).
