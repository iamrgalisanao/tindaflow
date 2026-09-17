-- database/scripts/harden_append_only_privileges.sql
--
-- NOT run by `php artisan migrate`. This is a Stage 9 (deployment)
-- hardening step, applied once per environment by a Postgres superuser
-- (or a role with GRANT/REVOKE privilege) AFTER migrations have run and
-- BEFORE the application role starts serving live traffic.
--
-- Why this is not a migration: `php artisan migrate` (and `migrate:fresh`,
-- `db:seed`, test-suite database resets) all run as the SAME database
-- role Laravel is configured to connect as. If that role's own
-- UPDATE/DELETE privileges were revoked by a migration, every later
-- `migrate:fresh --seed` and every test-suite teardown using that same
-- role would break. The correct shape (Stage 5 instruction SS65, SS35)
-- is two distinct Postgres roles:
--
--   tindaflow_migrator  -- full DDL/DML owner, used ONLY to run
--                          migrations/seeders during deploys. Never used
--                          by the running application.
--   tindaflow_app       -- the role Laravel's runtime connection actually
--                          uses. Ordinary SELECT/INSERT/UPDATE on
--                          lifecycle tables; SELECT/INSERT ONLY (no
--                          UPDATE, no DELETE) on every append-only table
--                          -- invariant #48's "enforced at the database
--                          layer... not merely the app doesn't happen to
--                          do this", architecture.md SS4's authority row
--                          for the same invariant, and SS16's "database
--                          user used by Laravel should not be superuser."
--
-- Run this script (as a superuser, or a role with GRANT OPTION) once
-- `tindaflow_app` exists, after migrations, before the app starts
-- accepting traffic:
--
--   psql -U postgres -d tindaflow -f database/scripts/harden_append_only_privileges.sql
--
-- Idempotent: safe to re-run after every future migration that adds a
-- new append-only table (add it to the list below and re-run).

-- Ordinary lifecycle tables: tindaflow_app gets full CRUD except DELETE,
-- since even "mutable" TindaFlow tables (products, users, terminals,
-- fiscal configuration, shifts, fiscal_days, voids, refunds,
-- invoice_series, idempotency_records) are never hard-deleted through
-- normal application behavior (Stage 5 instruction SS45/SS53 -- active
-- flags and explicit reversal workflows instead of DELETE). DELETE is
-- revoked store-wide, not table-by-table, because no table in this
-- schema is ever the target of an application-issued DELETE statement.
REVOKE DELETE ON ALL TABLES IN SCHEMA public FROM tindaflow_app;

-- Append-only tables: additionally revoke UPDATE. Once a row exists, no
-- application code path may change it (invariants #2/#41/#45/#48; ADR-005;
-- ADR-006 SS"reprint never mutates"). If a legitimate future need to
-- correct one of these tables ever arises, it happens via a superuser
-- connection and a documented, manually-executed procedure -- never a
-- application feature (invariant #51's same principle, extended here to
-- the underlying tables, not just journal/audit specifically).
REVOKE UPDATE ON
    sales,
    sale_items,
    payments,
    invoices,
    voids,          -- see note below: voids/refunds are lifecycle rows,
    refunds,        -- not fully append-only -- handled separately
    refund_items,
    refund_settlements,
    stock_movements,
    x_readings,
    z_readings,
    audit_events,
    electronic_journal_entries
FROM tindaflow_app;

-- voids/refunds are NOT fully append-only (they transition
-- REQUESTED -> APPROVED/REJECTED/VOIDED|COMPLETED, Stage 4 pass 5's
-- explicit approve/reject/re-approve lifecycle) -- the blanket UPDATE
-- revoke above is deliberately re-granted for exactly these two tables,
-- narrowed to the columns Stage 6's application logic is actually
-- allowed to change. Every other column (sale_id, requested_by, reason,
-- requested_at) is immutable once written, exactly like the fully
-- append-only tables above.
GRANT UPDATE (status, approved_by, resolved_at, terminal_id, fiscal_day_id, shift_id)
    ON voids TO tindaflow_app;
GRANT UPDATE (status, approved_by, resolved_at, refunded_at, refund_total, terminal_id, fiscal_day_id, shift_id)
    ON refunds TO tindaflow_app;

-- stock_balances is a derived cache, explicitly NOT covered by the
-- REVOKE UPDATE above -- domain-model.md SS2.4/invariant #44 require it
-- to be updated as an atomic side-effect of every stock_movement insert,
-- in the same transaction. It is deliberately mutable.

-- shifts/fiscal_days/invoice_series/idempotency_records are lifecycle
-- aggregates with frozen-domain-required in-place field changes
-- (shift totals at close, fiscal_day.status, invoice_series.current_number,
-- idempotency_records.status) -- left at the default (UPDATE allowed,
-- DELETE revoked above) rather than narrowed to a column list, since
-- unlike voids/refunds these tables have no separate "immutable core
-- fields" the frozen domain calls out explicitly.
