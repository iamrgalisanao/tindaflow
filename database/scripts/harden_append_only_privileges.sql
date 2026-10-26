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
-- tests/Database/AppendOnlyPrivilegesTest.php applies this script to a scratch role inside a rolled-back transaction and
-- proves that checkout, void and refund still work under it and that the append-only tables refuse UPDATE/DELETE. Keep
-- the two in step: a new UPDATE the application makes on a table revoked below needs a GRANT here AND a test there.
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

-- ...except the few tables the RUNNING application must delete from, none of which holds money or audit data:
--   sessions, cache, cache_locks  the stack's SESSION_DRIVER=database and CACHE_STORE=database (docker/.env.example):
--                                 Laravel deletes a session at logout and sweeps expired ones (a 2% lottery on requests),
--                                 and deletes an expired cache row whenever it reads one, which the API rate limiter
--                                 does on every request once its 60-second window has passed. PostgreSQL checks the
--                                 privilege even when no row matches, so without this the API answers 500 about a
--                                 minute after the first request and logout fails.
--   product_barcodes              removing an alternate barcode (ProductBarcodeService).
--   stock_count_lines             removing a line from an open stock count (StockCountService).
-- Everything else stays undeletable. tindaflow:harden-database reads the result back and refuses to finish if the set of
-- deletable tables is anything other than exactly this one.
GRANT DELETE ON sessions, cache, cache_locks, product_barcodes, stock_count_lines TO tindaflow_app;

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
    electronic_journal_entries,
    menu_prices,    -- ADR-016 slice 1.2, MENU-004: the menu's price history; a price change is a new row
    sale_item_selections, -- ADR-016 slice 1.3, MENU-001: what was chosen on a meal line, snapshotted at the sale; never changed
    sale_slips,     -- ADR-016 slice 2.2a, SLIP-001: the document of a slip-mode sale and its print snapshot; never changed
    slip_counters,  -- ADR-016 slice 2.2a: lifecycle, not append-only -- UPDATE is revoked here and re-granted by column below
    employees,      -- ADR-016 slice 3.1: lifecycle -- UPDATE is revoked here and re-granted by column below (not employee_code / employee_type)
    employee_code_sequences, -- ADR-016 slice 3.1: lifecycle -- the counters; re-granted by column below
    credit_policies, -- ADR-016 slice 3.1: lifecycle -- the current limit; re-granted by column below
    employee_credit_entries, -- ADR-016 slice 3.2, CREDIT-004: the credit ledger (CHARGE / REVERSAL); never changed, the balance is derived
    user_override_codes,   -- ADR-016 slice 3.3: lifecycle -- UPDATE is revoked here and re-granted by column below (not user_id / store_id)
    credit_override_grants, -- ADR-016 slice 3.3, OVR-001: an approval of one over-limit sale; append-only except consumed_at (re-granted below)
    billing_runs,   -- ADR-016 slice 4.1, BILL-004: a saved billing run (a frozen statement); never changed, a re-run is a new row
    billing_run_counters, -- ADR-016 slice 4.1: lifecycle -- the run number counter; UPDATE is revoked here and re-granted by column below
    billing_cheques,      -- ADR-016 slice 4.2, BILL-002: lifecycle -- UPDATE is revoked here and re-granted by column below (the return columns only)
    billing_cheque_allocations, -- ADR-016 slice 4.2, BILL-002: which cut-off periods a cheque pays; append-only
    billing_write_offs,   -- ADR-016 slice 4.3, BILL-008/BILL-009: write-offs and their reversals; append-only (a mistake is cancelled by a REVERSAL row)
    stock_override_grants, -- ADR-017 slice A, STK-005: the approval of one blocked sale; append-only except consumed_at / sale_id (re-granted below)
    stock_entry_grants -- ADR-017 slice B, STK-007: a manager's approval of a cashier's daily stock entry; append-only except consumed_at (re-granted below)
FROM tindaflow_app;

-- billing_cheques (slice 4.2) is a lifecycle table whose ONLY change is RECEIVED -> RETURNED (a bounced cheque), never deleted (the
-- store-wide REVOKE DELETE). The UPDATE is narrowed to exactly the five columns that change stamps, so the application role cannot alter
-- the amount, number, bank, dates, party or receiver of a recorded cheque whatever a bug or a stray query tries. The row lock a return
-- takes (SELECT ... FOR UPDATE) needs UPDATE on at least one column, which this grant provides. The reverse change, RETURNED back to
-- RECEIVED, is refused by the service (a re-presented cheque is a new row); the database role could write it, so that rule is the
-- service's and its test's, as with consumed_at on override grants.
GRANT UPDATE (status, returned_on, returned_at, returned_by, return_reason) ON billing_cheques TO tindaflow_app;

-- billing_run_counters is the last billing run number of each store. The transaction that saves a run bumps `last_number` (and stamps
-- `updated_at`) and inserts a store's first row; nothing else about a counter changes. Never deleted (the store-wide REVOKE DELETE). A run
-- that rolls back rolls its number back, so committed run numbers are gap-free.
GRANT UPDATE (last_number, updated_at) ON billing_run_counters TO tindaflow_app;

-- user_override_codes (ADR-016 slice 3.3) is an approver's override code: a lifecycle row, set, changed, cleared by an administrator and
-- counted for wrong attempts, never deleted (the store-wide REVOKE DELETE). The UPDATE is narrowed to exactly the columns those operations
-- change, so the application role cannot move a code to another user or store whatever a bug or a stray query tries. The history of sets,
-- resets and lockouts is the append-only audit log.
GRANT UPDATE (code_hash, failed_attempts, locked_until, set_at, set_by, updated_at) ON user_override_codes TO tindaflow_app;

-- credit_override_grants (ADR-016 slice 3.3) is append-only except for ONE column: checkout stamps `consumed_at` (under the employee's row
-- lock, in the sale's transaction) when the approval is used. The terminal, employee, cashier, approver, ceiling, token hash and expiry of a
-- grant can never be changed, so a used or expired approval cannot be revived and an approval cannot be widened. This grant is also what
-- lets checkout take its SELECT ... FOR UPDATE on the grant row (PostgreSQL needs UPDATE on at least one column for a row lock).
GRANT UPDATE (consumed_at) ON credit_override_grants TO tindaflow_app;

-- stock_override_grants (ADR-017 slice A, STK-005) is append-only except for TWO columns: checkout stamps `consumed_at` and `sale_id` together
-- (under the grant's row lock, in the sale's transaction) when the approval is used for a sale that really was short of stock. The terminal,
-- cashier, approver, demand hash, shortfall lines, token hash and expiry of a grant can never be changed, so a used or expired approval cannot
-- be revived, re-pointed at another cart or widened. `sale_id` is UNIQUE and a CHECK ties it to `consumed_at`, so one grant covers one sale. This
-- grant is also what lets checkout take its SELECT ... FOR UPDATE on the grant row (PostgreSQL needs UPDATE on at least one column for a row lock).
-- It is never deleted (the store-wide REVOKE DELETE).
GRANT UPDATE (consumed_at, sale_id) ON stock_override_grants TO tindaflow_app;

-- stock_entry_grants (ADR-017 slice B, STK-007) is append-only except for ONE column: the daily stock entry stamps `consumed_at` (under the grant's row
-- lock, in the transaction that writes the movements) when a cashier's save is approved by a manager's code. The terminal, cashier, approver, store, stock
-- date, token hash and expiry of a grant can never be changed, so a used or expired approval cannot be revived or re-pointed at another day or till. This
-- grant is also what lets the entry take its SELECT ... FOR UPDATE on the grant row (PostgreSQL needs UPDATE on at least one column for a row lock). It is
-- never deleted (the store-wide REVOKE DELETE).
GRANT UPDATE (consumed_at) ON stock_entry_grants TO tindaflow_app;

-- slip_counters is the running slip number of each terminal. The checkout transaction increments `last_number` (and stamps
-- `updated_at`) and inserts a terminal's first row; nothing else about a counter ever changes, so the UPDATE is narrowed to those two
-- columns. Like every table it is never deleted (the store-wide REVOKE DELETE above). A sale that rolls back rolls its increment back.
GRANT UPDATE (last_number, updated_at) ON slip_counters TO tindaflow_app;

-- employees (ADR-016 slice 3.1, EMP-001) is a lifecycle table: a name is corrected and an employee is deactivated and reactivated,
-- never deleted (the store-wide REVOKE DELETE). The UPDATE is narrowed to exactly those columns, so the application role cannot change
-- `employee_code` (the code is also the QR value and is never reissued), `employee_type` (it fixes the code's prefix and the limit),
-- `store_id` or `created_by`, whatever a bug or a stray query tries.
GRANT UPDATE (first_name, middle_name, last_name, billing_party_id, active, updated_at) ON employees TO tindaflow_app;

-- employee_code_sequences is the counter behind the codes: the transaction that creates an employee bumps `last_number` (and stamps
-- `updated_at`) and inserts a store's first row of a type; nothing else about a counter changes.
GRANT UPDATE (last_number, updated_at) ON employee_code_sequences TO tindaflow_app;

-- credit_policies holds the CURRENT limit per cut-off of one employee type of one store. Changing it is an UPDATE of the limit (and who
-- and when); the row's store and type never change. The history of limits is the CREDIT_LIMIT_CHANGED audit event, which is append-only.
GRANT UPDATE (limit_per_cutoff, updated_by, updated_at) ON credit_policies TO tindaflow_app;

-- voids/refunds are NOT fully append-only (they transition
-- REQUESTED -> APPROVED/REJECTED/VOIDED|COMPLETED, Stage 4 pass 5's
-- explicit approve/reject/re-approve lifecycle) -- the blanket UPDATE
-- revoke above is deliberately re-granted for exactly these two tables,
-- narrowed to the columns Stage 6's application logic is actually
-- allowed to change. Every other column (sale_id, requested_by, reason,
-- requested_at) is immutable once written, exactly like the fully
-- append-only tables above.
GRANT UPDATE (status, approved_by, resolved_at, terminal_id, fiscal_day_id, shift_id, updated_at)
    ON voids TO tindaflow_app;
GRANT UPDATE (status, approved_by, resolved_at, refunded_at, refund_total, terminal_id, fiscal_day_id, shift_id, updated_at)
    ON refunds TO tindaflow_app;

-- sales is revoked above, but its status is the one column the application legitimately changes after the row is
-- written: a void sets it to VOIDED and a refund recomputes it (invariants #23/#31). This grant is also what lets
-- the void and refund services take their SELECT ... FOR UPDATE on the sale row -- PostgreSQL requires UPDATE on at
-- least one column for any row lock. Every other sales column stays immutable (invariant #2), and the sale_items,
-- payments and invoices rows a void or refund reads are never locked or updated.
GRANT UPDATE (status) ON sales TO tindaflow_app;

-- stock_balances is a derived cache, explicitly NOT covered by the
-- REVOKE UPDATE above -- domain-model.md SS2.4/invariant #44 require it
-- to be updated as an atomic side-effect of every stock_movement insert,
-- in the same transaction. It is deliberately mutable.

-- menu_groups (ADR-016, invariants.md MENU-005) is a lifecycle/configuration table: the application renames, re-sorts and
-- hides (active = false) its rows, so it keeps INSERT and UPDATE, and it is never deleted. It needs no statement here: it is
-- not in the DELETE allow-list above, so the store-wide REVOKE DELETE already covers it. tindaflow:harden-database reads
-- that back, and AppendOnlyPrivilegesTest proves the menu admin works and a DELETE is refused under the restricted role.

-- The meal menu configuration tables (ADR-016 slice 1.2) -- dish_categories, dishes, meals, meal_option_groups, meal_options --
-- are lifecycle/configuration tables like menu_groups: INSERT and UPDATE (rename, reorder, hide with active = false), never
-- DELETE (covered by the store-wide REVOKE DELETE above). menu_prices is the one append-only table of the menu; it is in the
-- REVOKE UPDATE list above. products.menu_hidden is an ordinary column of a lifecycle table.
-- Slice 1.3 adds no lifecycle table: sale_item_selections is append-only (in the list above) and the four meal snapshot columns of
-- sale_items (menu_group_path_snapshot, meal_id, meal_name_snapshot, parent_line_number) sit under the table-wide UPDATE revoke.
-- Slice 2.2a (slip mode): sale_slips is append-only (in the list above); slip_counters is lifecycle with a column-level UPDATE (above);
-- sales.document_mode sits under the table-wide UPDATE revoke of `sales` (written once at INSERT); store_settings.sale_document_mode and
-- slip_footer are ordinary columns of a lifecycle table (the store settings and the mode switch update them), and idempotency_records
-- gained one allowed operation type, which is a CHECK change only.

-- ADR-017 slice A adds no table beyond stock_override_grants (above): store_settings.block_sale_without_stock, stock_override_enabled and
-- low_stock_default_threshold are ordinary columns of a lifecycle table (the ADMIN-only stock rules screen updates them), and
-- products.reorder_level is an ordinary column of a lifecycle table that became nullable (NULL = the store default). stock_balances stays
-- the derived cache described above: checkout's blocking check may also INSERT a zero row for a product that has never moved (ON CONFLICT
-- DO NOTHING) so it can be locked, which the INSERT and UPDATE the role already holds on it cover.
-- ADR-017 slice B (daily stock) adds two tables and several columns:
--   daily_stock_balances    a DERIVED LIFECYCLE CACHE exactly like stock_balances (above): the signed sum of the dated stock_movements of one (product, location,
--                           stock date), changed only by StockLedger as the atomic side effect of inserting a movement. INSERT and UPDATE for the application
--                           role, no DELETE (the store-wide REVOKE DELETE), NOT in the append-only list. inventory:rebuild-balances recomputes it.
--   stock_entry_grants      append-only except consumed_at (above).
--   stock_movements         stays APPEND-ONLY: stock_date and approved_by are written at INSERT only (they sit under the table-wide UPDATE revoke).
--   sale_item_selections / sale_items   stay APPEND-ONLY: stock_portion_snapshot is written at INSERT only.
--   products (stock_mode, dish_id, stock_portion), dishes (stock_mode, stock_product_id), meal_options (stock_portion), store_settings (stock_day_cutover_hour)
--                           are ordinary columns of LIFECYCLE tables (the menu, catalog and stock-rules screens update them).
-- Slice 3.3: employee_credit_entries gained the nullable override_grant_id (written only by the CHARGE's INSERT, under the table-wide UPDATE
-- revoke); user_override_codes and credit_override_grants are above.
-- Slice 3.2: employee_credit_entries is append-only (in the list above); sales.employee_id sits under the table-wide UPDATE revoke of
-- `sales` (written once at INSERT); the payments / refund_settlements method CHECKs gained CREDIT, which is a constraint change only.

-- billing_parties (ADR-016 slice 3.1) is a plain lifecycle/configuration table: INSERT and UPDATE (rename, deactivate), never DELETE
-- (covered by the store-wide REVOKE DELETE). It needs no statement here.

-- shifts/fiscal_days/invoice_series/idempotency_records are lifecycle
-- aggregates with frozen-domain-required in-place field changes
-- (shift totals at close, fiscal_day.status, invoice_series.current_number,
-- idempotency_records.status) -- left at the default (UPDATE allowed,
-- DELETE revoked above) rather than narrowed to a column list, since
-- unlike voids/refunds these tables have no separate "immutable core
-- fields" the frozen domain calls out explicitly.
