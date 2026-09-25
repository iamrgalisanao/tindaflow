# Migration Plan — TindaFlow POS

## Status

DRAFT — Stage 5, awaiting owner review. Companion to
[database-schema.md](database-schema.md). Documents the actual
foreign-key dependency order the 37 migrations were written in, the
final migration DAG, reversibility behavior, and production rollback
limitations (Stage 5 instructions §55/§56).

---

## 1. Actual migration order (as implemented)

The suggested conceptual order from Stage 5 instruction §55 was followed
closely, with adjustments only where actual FK dependencies required a
different sequence. Each numbered group below is a migration batch; a
table only appears once every table it foreign-keys to already exists.

| # | File | Table(s) | Depends on |
|---|---|---|---|
| 1 | `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` | — (Laravel framework) |
| 2 | `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` | — (Laravel framework) |
| 3 | `2026_01_01_000010_create_stores_table` | `stores` | — (tenant root) |
| 4 | `2026_01_01_000020_create_store_settings_table` | `store_settings` | `stores` |
| 5 | `2026_01_01_000030_create_users_table` | `users` | `stores` |
| 6 | `2026_01_01_000040_create_terminals_table` | `terminals` | `stores` |
| 7 | `2026_01_01_000050_create_terminal_enrollment_tokens_table` | `terminal_enrollment_tokens` | `stores`, `terminals`, `users` |
| 8 | `2026_01_01_000060_create_tax_registrations_table` | `tax_registrations` | `stores` |
| 9 | `2026_01_01_000070_create_fiscal_installations_table` | `fiscal_installations` | `stores` |
| 10 | `2026_01_01_000071_create_fiscal_installation_accreditations_table` | `fiscal_installation_accreditations` | `fiscal_installations` |
| 11 | `2026_01_01_000072_create_fiscal_installation_permits_to_use_table` | `fiscal_installation_permits_to_use` | `fiscal_installations` |
| 12 | `2026_01_01_000080_create_terminal_fiscal_installations_table` | `terminal_fiscal_installations` | `terminals`, `fiscal_installations` |
| 13 | `2026_01_01_000090_create_sessions_table` | `sessions` | `users` (nullable FK) |
| 14 | `2026_01_01_000100_create_categories_table` | `categories` | `stores` |
| 15 | `2026_01_01_000101_create_brands_table` | `brands` | `stores` |
| 16 | `2026_01_01_000110_create_products_table` | `products` | `stores`, `categories`, `brands` |
| 17 | `2026_01_01_000111_create_product_barcodes_table` | `product_barcodes` | `products` |
| 18 | `2026_01_01_000120_create_inventory_locations_table` | `inventory_locations` | `stores` |
| 19 | `2026_01_01_000130_create_invoice_series_table` | `invoice_series` | `stores` |
| 20 | `2026_01_01_000140_create_fiscal_days_table` | `fiscal_days` | `stores`, `terminals` |
| 21 | `2026_01_01_000150_create_shifts_table` | `shifts` | `terminals`, `fiscal_days`, `users` |
| 22 | `2026_01_01_000160_create_cash_movements_table` | `cash_movements` | `shifts`, `users` |
| 23 | `2026_01_01_000170_create_x_readings_table` | `x_readings` | `terminals`, `shifts`, `users` |
| 24 | `2026_01_01_000180_create_z_readings_table` | `z_readings` | `terminals`, `fiscal_days`, `users` |
| 25 | `2026_01_01_000190_create_idempotency_records_table` | `idempotency_records` | `terminals` |
| 26 | `2026_01_01_000200_create_sales_table` | `sales` | `stores`, `terminals`, `fiscal_days`, `shifts`, `users` |
| 27 | `2026_01_01_000210_create_sale_items_table` | `sale_items` | `sales`, `products` |
| 28 | `2026_01_01_000220_create_payments_table` | `payments` | `sales` |
| 29 | `2026_01_01_000230_create_invoices_table` | `invoices` | `invoice_series`, `terminals`, `fiscal_installations`, `sales` |
| 30 | `2026_01_01_000240_create_voids_table` | `voids` | `sales`, `users`, `terminals`, `fiscal_days`, `shifts` |
| 31 | `2026_01_01_000250_create_refunds_table` | `refunds` | `sales`, `users`, `terminals`, `fiscal_days`, `shifts` |
| 32 | `2026_01_01_000260_create_refund_items_table` | `refund_items` | `refunds`, `sale_items` |
| 33 | `2026_01_01_000270_create_refund_settlements_table` | `refund_settlements` | `refunds` |
| 34 | `2026_01_01_000280_create_stock_movements_table` | `stock_movements` | `products`, `inventory_locations`, `terminals`, `users` |
| 35 | `2026_01_01_000290_create_stock_balances_table` | `stock_balances` | `products`, `inventory_locations` |
| 36 | `2026_01_01_000300_create_audit_events_table` | `audit_events` | `stores`, `users`, `terminals` |
| 37 | `2026_01_01_000310_create_electronic_journal_entries_table` | `electronic_journal_entries` | `stores`, `terminals`, `audit_events` |
| 38 | `2026_01_01_000320_add_reporting_and_foreign_key_indexes` | *(indexes only, no new tables)* | every table above |

**Deviations from Stage 5 instruction §55's suggested order:**
- `terminal_enrollment_tokens` was placed immediately after `terminals`
  (not deferred to a later "enrollment/security support" group as the
  suggestion implied), since it depends on nothing besides
  `stores`/`terminals`/`users` and ADR-011's enrollment flow is a
  Stage 1-adjacent concern with no forward dependencies.
- `fiscal_installation_accreditations`/`permits_to_use` were inserted
  immediately after `fiscal_installations` (not deferred), since they
  are pure children of it with no other dependency — see
  database-schema.md §8.
- `idempotency_records` was placed just before `sales` rather than at
  the very end of the suggested order, since it depends only on
  `terminals` and Stage 5 instruction §42/§55's own reasoning ("avoid
  scans during approval execution") is best served by having it exist
  before any of the 14 operations it protects are even modeled.
- The cross-cutting reporting/FK index migration was placed last,
  intentionally, so every table and column it indexes already exists —
  this groups "why does this index exist" in one file (see
  [index-strategy.md](index-strategy.md)) rather than scattering
  duplicate-looking `CREATE INDEX` statements across 15+ per-table
  files.

No suggested-order table was skipped or reordered for a reason other
than an actual FK dependency or a co-location judgment call; the DAG
below is the authoritative dependency graph this order satisfies.

---

## 2. Migration DAG (dependency graph)

```
stores
 ├── store_settings
 ├── users ──────────────────────────────┐
 ├── terminals ──┬── terminal_enrollment_tokens (+ users)
 │               ├── fiscal_days (+ stores)
 │               ├── terminal_fiscal_installations (+ fiscal_installations)
 │               ├── idempotency_records
 │               ├── x_readings (+ shifts, users)
 │               ├── z_readings (+ fiscal_days, users)
 │               ├── stock_movements (+ products, inventory_locations, users)
 │               ├── audit_events (+ stores, users)
 │               └── electronic_journal_entries (+ stores, audit_events)
 ├── tax_registrations
 ├── fiscal_installations ──┬── fiscal_installation_accreditations
 │                          ├── fiscal_installation_permits_to_use
 │                          └── terminal_fiscal_installations (+ terminals)
 ├── categories ──┐
 ├── brands ───────┴── products ──┬── product_barcodes
 │                                 ├── sale_items (+ sales)
 │                                 └── stock_movements / stock_balances (+ inventory_locations)
 ├── inventory_locations ── stock_movements / stock_balances (+ products)
 └── invoice_series ── invoices (+ terminals, fiscal_installations, sales)

fiscal_days (+ stores, terminals) ── shifts (+ terminals, users) ──┬── cash_movements
                                                                     ├── x_readings
                                                                     └── sales

sales (+ stores, terminals, fiscal_days, shifts, users) ──┬── sale_items ── refund_items (+ refunds)
                                                            ├── payments
                                                            ├── invoices (+ invoice_series, terminals, fiscal_installations)
                                                            ├── voids (+ users, terminals, fiscal_days, shifts)
                                                            └── refunds (+ users, terminals, fiscal_days, shifts) ──┬── refund_items (+ sale_items)
                                                                                                                     └── refund_settlements
```

The graph is acyclic. `voids`/`refunds` each carry **two independent
sets** of foreign keys into `terminals`/`fiscal_days`/`shifts` — one
implicit (via `sales`, the original transaction context) and one direct
(the processing context columns, per invariants.md #67/#68) — both
resolve to the same already-created tables, so no new dependency edge is
introduced beyond what `sales`, `terminals`, `fiscal_days`, and `shifts`
already require.

---

## 3. Migration reversibility (Stage 5 instruction §56)

Every migration's `down()` was written intentionally, not left as
Laravel's scaffold default:

- **34 of 37 migrations** have a `down()` that calls
  `Schema::dropIfExists()` on the table(s) that migration's `up()`
  created — safe in development because it removes the objects the same
  file added and nothing else.
- **The reporting/FK index migration** (`..._000320_...`) has a `down()`
  that individually `DROP INDEX IF EXISTS`es each of the ~38 indexes it
  created by name, rather than being left empty — dropping an index,
  unlike dropping a column or table, never loses data, so this is safe
  to automate even though most other `down()` methods in this schema are
  not (§4 below).
- **Verified live** (see [schema-validation.md](schema-validation.md)
  Pass A): a full `php artisan migrate:reset` (running every `down()` in
  reverse order) and a subsequent `php artisan migrate` both completed
  without error, and the resulting schema was identical (41 tables) to
  the original migration run.

---

## 4. Production rollback limitations — explicit, not implied

**A clean `down()` in development does not mean `php artisan
migrate:rollback` is safe to run in production once real financial data
exists.** This is stated explicitly per Stage 5 instruction §56, which
warns against implying otherwise:

- Every `down()` that drops a table (34 of 37 migrations) is
  **destructive of any data that table holds** — running
  `migrate:rollback` against a production database with real `sales`,
  `invoices`, `voids`, `refunds`, or ledger rows would permanently
  destroy fiscal history. This is true of essentially every Laravel
  schema's `down()` methods by convention, not a defect specific to this
  schema, but the consequence is more severe here than in a typical CRUD
  app because BIR compliance requires this history to never be lost
  (invariants.md #51).
- **Production schema changes to this system should never be performed
  via `migrate:rollback`.** The correct production procedure for a
  schema correction once real data exists is: (1) a new forward
  migration that alters the schema in place (e.g., `ALTER TABLE ADD
  COLUMN`, a new constraint added as `NOT VALID` then `VALIDATE
  CONSTRAINT` to avoid a blocking table scan), preceded by (2) a full
  `pg_dump` backup per ADR-008, and (3) a maintenance window if the
  change is not provably non-blocking. This procedure is a Stage 6/9
  operational runbook concern, not something this migration set can
  enforce structurally — the schema can only ensure its own `down()`
  methods are honest about what they do, which they are.
- **The reporting/FK index migration is the one safe exception** — its
  `down()` can be run in production without data loss, since it only
  removes indexes (query-performance only, not correctness).

This document does not claim `migrate:rollback` is production-safe
anywhere in this schema, and neither should any Stage 6/9 deployment
runbook that references it.

---

## 5. Seeders and factories (Stage 5 instructions §57/§58)

**Seeders** (`database/seeders/`, implemented and verified live):
- [`AdminUserSeeder`](../../database/seeders/AdminUserSeeder.php) —
  idempotent (`firstOrCreate`/existence check), safe to run in every
  environment including production's first deploy. Creates one `Store`
  (name from `TINDAFLOW_INITIAL_STORE_NAME`, defaulting to a clearly
  placeholder value) and one `ADMIN` `User` (email from
  `TINDAFLOW_INITIAL_ADMIN_EMAIL`) with a freshly generated 20-character
  password (`Str::password(20)`, or `TINDAFLOW_INITIAL_ADMIN_PASSWORD`
  if explicitly supplied) — never a fixed, source-committed credential.
  The password is printed once to console output and never persisted in
  plaintext anywhere.
- [`DemoDataSeeder`](../../database/seeders/DemoDataSeeder.php) — one
  terminal, one cashier, and five representative products, seeded into
  the same store `AdminUserSeeder` resolves (V1 is single-store, and a
  separate demo store left its cashier unable to use any terminal the
  admin enrolled), gated by `app()->environment('production')` (refuses to run
  in production even if invoked directly) and only invoked from
  `DatabaseSeeder` outside production in the first place — two
  independent layers of guard, not one.
- **Default `categories` were deliberately not seeded** — neither
  `scope.md` nor `product-vision.md` mandates a fixed starter category
  list, and inventing one would be an undocumented product decision
  disguised as a database seed.

**Explicitly not seeded, ever, including in demo data:** a fake
`accreditation_number`, a fake `ptu_number`, or any fake production tax
identity (TIN, registered name) — `DemoDataSeeder` creates no
`fiscal_installations`/`tax_registrations` row at all, per Stage 5
instruction §57's concern that such values carry real regulatory meaning
and a seeded fake could leak into a real deployment's configuration by
copy-paste. A demo store is deliberately left in the "no active tax
registration" state invariant #54 already requires a real setup step
for.

**Verified live**: `php artisan db:seed` run twice in sequence confirmed
`AdminUserSeeder`'s idempotency (second run: "already exists — skipping"),
and re-run with `APP_ENV=production` confirmed `DemoDataSeeder` is never
even invoked.

**Factories** (`database/factories/`, implemented and verified live):
one factory per core model named in Stage 5 instruction §58 (`Store`,
`Terminal`, `Shift`, `FiscalDay`, `Product`, `Sale`, `SaleItem`,
`Payment`, `Invoice`, `Refund`, `SaleVoid`), plus a supporting
`InvoiceSeries` factory `Invoice` depends on. Each factory's default
state resolves a *coherent* graph rather than independently-random
relations — e.g., `ShiftFactory` creates one `Terminal` and threads it
into both the `Shift` and its `FiscalDay`, and `SaleFactory` resolves a
`Shift` first and derives `store_id`/`terminal_id`/`fiscal_day_id`/
`cashier_id` from it — so the default output never bypasses invariant
#35's "sale requires a matching open shift and fiscal day" in ordinary
use. Each factory also exposes explicit invalid-state methods for
constraint testing (e.g., `SaleItemFactory::withOverflowQuantity()`,
`PaymentFactory::withOverflowAmount()`,
`InvoiceFactory::withSerial($series, $number)` for duplicate-serial
tests).

**Verified live** via
[`tests/Database/FactorySmokeTest.php`](../../tests/Database/FactorySmokeTest.php)
— every factory creates a real, constraint-satisfying row against
PostgreSQL, and the `Shift`/`Sale` coherent-graph guarantees are
asserted directly (18 tests, 30 assertions, all passing — see
[schema-validation.md](schema-validation.md)).
