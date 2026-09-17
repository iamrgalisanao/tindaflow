# Index Strategy — TindaFlow POS

## Status

DRAFT — Stage 5, awaiting owner review. Companion to
[database-schema.md](database-schema.md) and
[constraint-register.md](constraint-register.md). Every index in this
schema traces to either (a) a partial-unique invariant already listed in
constraint-register.md, (b) a query pattern named in
[architecture.md §20](../03-architecture/architecture.md) or the 15
reports in [csv-export-contract.md](../05-api/csv-export-contract.md),
or (c) a foreign key used in a join/lock/high-frequency path per Stage 5
instruction §50. No index in this schema was added speculatively.

---

## 1. Partial unique indexes (invariant-enforcing — see constraint-register.md for the full list)

These are covered exhaustively in
[constraint-register.md](constraint-register.md) (DB-INV-001 through
DB-INV-010, DB-INV-024/031, DB-INV-046-049, DB-INV-054); listed here only
as a cross-reference so this document is a complete index inventory:

`shifts_one_open_per_terminal`, `shifts_one_open_per_cashier`,
`fiscal_days_one_open_per_terminal`, `x_readings_one_closing_per_shift`,
`voids_one_voided_per_sale`, `sales_idempotency_unique`,
`products_barcode_unique_per_store`,
`tax_registrations_one_current_per_store`,
`fiscal_installation_accreditations_one_current`,
`fiscal_installation_ptu_one_current`,
`terminal_fiscal_installations_one_current_per_terminal`. These serve a
dual purpose: they are simultaneously the *invariant enforcement
mechanism* and a highly selective index for exactly the query Stage 6's
"is there already an open X" checks need (e.g., "find the open shift for
this terminal") — no separate index was added for that lookup, since the
partial unique index already is one.

---

## 2. Stage 4 query-pattern review (Stage 5 instruction §48)

Every column-group Stage 5 instruction §48 named was reviewed. Result:

| Query pattern | Index | Notes |
|---|---|---|
| Barcode scan (`GET /catalog/lookup?barcode=`) | `products_barcode_unique_per_store` (partial unique, §1) | Doubles as the lookup index — barcode scan is the highest-frequency read in the whole system (every checkout item) and this index is already maximally selective. |
| SKU lookup | `products` `UNIQUE(store_id, sku)` (implicit index from the unique constraint) | No separate index needed — a unique constraint already creates a btree index. |
| Sales by store + date, completed only | `sales_store_sold_at_completed_idx (store_id, sold_at) WHERE status='COMPLETED'` | Backs sales-history and most reporting date-range queries; partial on `COMPLETED` since voided/refunded sales are excluded from most revenue reports. |
| Sales by cashier | `sales_cashier_sold_at_idx (cashier_id, sold_at)` | Backs per-cashier sales history and shift reconciliation. |
| Sales by terminal | `sales_terminal_sold_at_idx (terminal_id, sold_at)` | Backs per-terminal reporting and the terminal's own sale history. |
| Sale → invoice linkage | `invoices` `UNIQUE(sale_id)` (implicit) + `invoices_invoice_number_idx (invoice_number)` | The 1:1 FK unique constraint covers sale→invoice; the separate index covers invoice-number lookup (e.g., reprint by number) independent of series. |
| Transaction number lookup | `sales_invoice_number_lookup_idx (store_id, transaction_number)` | Named for its origin in the reporting-pattern list; despite the name, it indexes `transaction_number`, not `invoice_number` (see database-schema.md §11 on why the two are kept independent). |
| Sales by cashier/status | `sales_cashier_sold_at_idx` + `sales_store_sold_at_completed_idx` | Combination covers "this cashier's completed sales" without a dedicated third index — status filtering happens via the partial predicate on the store-scoped index when the query is store-scoped, or a sequential filter on the smaller cashier-scoped result set otherwise. |
| Invoice by series | `invoices` `UNIQUE(invoice_series_id, invoice_number)` (implicit, also DB-INV-018) | |
| Refund by original sale, by status | `refunds_sale_idx (sale_id)`, `refunds_status_idx (status, requested_at)`, `refunds_sale_completed_idx (sale_id) WHERE status='COMPLETED'` | Three indexes for three distinct access shapes: "all refund attempts for this sale," "pending refunds needing approval," and "does a completed refund already exist for this sale" (the last one is the hot path for the cumulative-cap recheck under lock — architecture.md §24). |
| Refund_item by original sale item | `refund_items_sale_item_idx (sale_item_id)` | Backs the cumulative-quantity/amount aggregate query (DB-INV-032/033) — without this index, that recheck would require a sequential scan of `refund_items` under an active row lock, which is exactly the kind of lock-duration risk Stage 5 instruction §42 warns against. |
| Void by original sale, by status | `voids_sale_idx (sale_id)`, `voids_status_requested_at_idx (status, requested_at)` | Same shape as Refund's equivalents. |
| Stock movements by product/date | `stock_movements_product_occurred_idx (product_id, occurred_at)` (created inline in the `stock_movements` migration, not the cross-cutting index migration) | Backs inventory history and valuation reports. |
| Stock movements by source | `stock_movements_reference_idx (reference_type, reference_id)` (inline) | Backs "show me every stock movement caused by this sale/refund" traceability queries — see database-schema.md §10's polymorphic-attribution discussion. |
| Shift by terminal/cashier, status | `shifts_terminal_opened_at_idx`, `shifts_cashier_opened_at_idx`, plus the two partial-unique "one open" indexes from §1 | The partial unique indexes already serve "find the open shift" with maximum selectivity; the two ordinary indexes serve historical shift listing/reporting. |
| Fiscal day by terminal, status, business_date | `fiscal_days_terminal_business_date_idx (terminal_id, business_date)` + `fiscal_days_one_open_per_terminal` (partial unique, §1) | Same split as Shift: the partial unique index is the "find the open one" path; the ordinary index is the historical/reporting path. |
| Audit by occurred_at, entity, event | *(not yet a dedicated composite index — see §4 gap note below)* | `audit_events` currently has only its FK-driven indexes (`store_id`, `actor_user_id`, `terminal_id` via the FK review in §3). A dedicated `(entity_type, entity_id, occurred_at)` or `(event_type, occurred_at)` index was not added in this pass because no Stage 4 report or operation was found that queries audit history by entity/event/date range at volume — see §4. |
| Journal by occurred_at, event, source | *(same gap as audit — see §4)* | `electronic_journal_entries` has its `UNIQUE(source_type, source_id, event_type)` (implicit index) and FK-driven indexes on `terminal_id`/`audit_event_id`, but no dedicated `(occurred_at)` range index yet. |

---

## 3. Foreign-key index review (Stage 5 instruction §50)

PostgreSQL does **not** automatically index every foreign-key column —
only the *referenced* side (the primary/unique key being pointed at)
gets an index for free; the *referencing* column on the child table
needs its own explicit index if it will be filtered, joined, or locked
against frequently. Every one of the 75 foreign keys in this schema
(see database-schema.md §6) was reviewed individually against three
criteria: is it used in a join for a report/list endpoint, is it used in
a `RESTRICT`-delete integrity check that runs at meaningful volume, or is
it on a high-frequency transaction path (checkout, approval, closing).

**Indexed explicitly** (in addition to the query-pattern indexes in §2,
which already cover many FK columns as their leading key):
`sale_items.sale_id`, `sale_items.product_id`, `payments.sale_id`,
`products.category_id`, `products.brand_id`, `product_barcodes.product_id`,
`invoices.terminal_id`, `invoices.fiscal_installation_id`,
`refund_settlements.refund_id`, `stock_balances.location_id` (composite
with `product_id`), `shifts.fiscal_day_id`, `cash_movements.shift_id`,
`x_readings.shift_id`, `x_readings.terminal_id`, `z_readings.terminal_id`,
`terminals.store_id` (composite with `status`),
`terminal_enrollment_tokens.terminal_id`, `fiscal_installations.store_id`,
`terminal_fiscal_installations.fiscal_installation_id`,
`tax_registrations.store_id`, `users.store_id`.

**Deliberately not given a dedicated index** (the FK exists for
integrity only, at low join/lock volume in the domain's realistic usage
pattern): `store_settings.store_id` (1:1, looked up by PK-equivalent
access in practice), `categories.store_id`/`brands.store_id` (small
per-store cardinality, sequential scan is cheap and correct),
`inventory_locations.store_id` (typically 1-3 rows per store),
`terminal_enrollment_tokens.store_id`/`created_by` (low volume,
short-lived rows), `sales.store_id` (already covered as a leading
column by `sales_store_sold_at_completed_idx`), `sale_items` and
`refund_items`'s remaining FKs beyond the ones listed above,
`audit_events.store_id`/`actor_user_id`/`terminal_id` (see gap note in
§4), `electronic_journal_entries.store_id`/`audit_event_id` (ditto).
This list should be revisited once Stage 6/7 usage data (or `EXPLAIN
ANALYZE` on real report queries) shows a different picture — none of
these omissions is irreversible; adding an index later is a
non-breaking migration.

---

## 4. Reporting index review — 15 reports (Stage 5 instruction §49)

Every report in [csv-export-contract.md](../05-api/csv-export-contract.md)
was checked against the indexes above to confirm no report requires a
JSON scan of `invoice_snapshot_json`/`totals_snapshot` as its primary
data source (Stage 5 instruction §49's explicit concern):

- **Sales, revenue, and cashier reports** — all resolve against
  `sales`/`sale_items`/`payments` relational columns via
  `sales_store_sold_at_completed_idx`,
  `sales_cashier_sold_at_idx`/`sales_terminal_sold_at_idx`, and
  `sale_items_sale_idx`/`sale_items_product_idx`. No JSON access.
- **Tax/VAT breakdown reports** — resolve against
  `sale_items.tax_classification_snapshot`/`tax_amount`/`taxable_base`
  (flat relational columns, populated once at finalization per DISC-003),
  joined through `sale_items_sale_idx`. No JSON access.
- **Void/Refund reports** — resolve against `voids`/`refunds`/
  `refund_items` relational columns via `voids_status_requested_at_idx`/
  `refunds_status_idx`/`refund_items_refund_idx`. No JSON access.
- **X-Reading/Z-Reading listing reports** — list the reading *rows*
  (via `x_readings_shift_idx`/`z_readings_terminal_idx`), each of which
  legitimately *contains* a JSONB `totals_snapshot` as its payload — this
  is the one place JSON is the actual reported content (by design, per
  database-schema.md §18), not a scan target for filtering/aggregation.
  No report filters or aggregates *across* the JSONB contents of
  multiple readings; each reading's snapshot is read whole, by its own
  row.
- **Inventory reports** — resolve against `stock_movements`/
  `stock_balances` via `stock_movements_product_occurred_idx` and
  `stock_balances_location_product_idx`. No JSON access.
- **Audit/journal exports** — resolve against `audit_events`/
  `electronic_journal_entries` relational columns. **Gap acknowledged**:
  neither table has a dedicated `occurred_at`-range or
  `(entity_type, entity_id)` index yet (see §2's gap note); at current
  expected data volumes (a single convenience store's daily audit/
  journal volume) a sequential scan bounded by a date range is unlikely
  to be a practical problem, but this should be revisited with an
  `EXPLAIN ANALYZE` once realistic data volume exists in Stage 6/8 —
  adding `CREATE INDEX audit_events_occurred_at_idx (occurred_at)` and
  `CREATE INDEX electronic_journal_entries_occurred_at_idx (occurred_at)`
  is a trivial non-breaking follow-up migration if profiling shows it's
  needed, and is the one deliberately-deferred item in this document
  rather than a silently-missed one.

**Conclusion:** no report requires an `invoice_snapshot_json` scan as
its primary source — every tax/discount/financial figure a report needs
already exists as a flat, indexed relational column on `sale_items` or
`sales`, populated once at finalization. The JSONB snapshot remains a
reprint/display convenience only, matching database-schema.md §18's
design intent.

---

## 5. Performance review (Stage 5 instruction §67)

Hot-path query patterns reviewed and their backing index:

| Hot path | Backing index(es) |
|---|---|
| Barcode scan during checkout | `products_barcode_unique_per_store` |
| Sale finalization: locate open shift/fiscal day for terminal | `shifts_one_open_per_terminal`, `fiscal_days_one_open_per_terminal` (both partial unique — O(1) lookup) |
| Sale finalization: lock invoice_series row | `invoice_series` PK (`SELECT ... FOR UPDATE WHERE id = ?`) — no additional index needed, PK lookup is already optimal |
| Shift lookup by cashier at login/handoff | `shifts_one_open_per_cashier` (partial unique) |
| Fiscal day lookup by terminal at shift-open time | `fiscal_days_one_open_per_terminal` (partial unique) |
| Refund eligibility recheck (cumulative cap) | `refund_items_sale_item_idx` |
| Sale history (cashier's own, store-wide) | `sales_cashier_sold_at_idx`, `sales_store_sold_at_completed_idx` |

No column was denormalized in this schema without a corresponding
documented reason (the flat snapshot columns on `sale_items`/`invoices`
are the one deliberate denormalization in the entire schema, and their
justification — historical-snapshot immutability, invariants #7/DISC-003
— is a frozen-corpus requirement, not a performance-driven guess).
