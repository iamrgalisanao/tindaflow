# Stage 14 — Stock receipts, adjustments and stock views

## Status

**Done and tested**, backend and admin screens. **No change to the frozen contract**:
all five `openapi.yaml` Inventory operations already existed, and their failures map
onto codes that were already registered. No baseline tag moved and
`scripts/validate-baselines.sh` stayed green.

## 1. Scope

| Operation | Route | Notes |
|---|---|---|
| `inventoryStockList` | `GET /inventory/stock` | `product_id` filter; tracked products only |
| `inventoryLowStockList` | `GET /inventory/low-stock` | on hand `<=` the product's `reorder_level` |
| `inventoryMovementList` | `GET /inventory/movements` | filters `product_id`, `movement_type`, `from`, `to`; newest first |
| `inventoryReceiptCreate` | `POST /inventory/receipts` | `OPENING_STOCK` or `PURCHASE_RECEIPT`; optional `unit_cost`, `note` |
| `inventoryAdjustmentCreate` | `POST /inventory/adjustments` | `STOCK_ADJUSTMENT_IN/OUT`, `DAMAGE`, `EXPIRED`; `reason` required |

The three reads need only a session and are scoped to the actor's store through the
product. The two writes need `STOCK_ADJUST` (ADMIN and MANAGER) **and** an enrolled
terminal, require an `Idempotency-Key`, and take identity from the terminal context,
never the body. Unknown or foreign products are `404 PRODUCT_NOT_FOUND`; a blank
adjustment reason is `422 STOCK_ADJUSTMENT_REASON_REQUIRED` (invariant #46); everything
else malformed is `VALIDATION_FAILED`. Unknown filter values match nothing rather than
being ignored, and a non-UUID id filter returns an empty page instead of a database error.

## 2. A latent defect this stage fixed

Checkout wrote a `SALE` movement but **never updated `stock_balances`**, so invariant #44
("on hand equals the signed sum of the movements") was already broken by the first sale.
Nothing read balances until now, so it had not surfaced.

`Services/Inventory/StockLedger` is now the **only** writer of `stock_movements` and
`stock_balances`. `record()` inserts the movement and applies its signed delta to the
balance with a single `INSERT ... ON CONFLICT DO UPDATE` (atomic under concurrency), and
refuses to run outside a transaction. `CheckoutService` and the new `StockService` both
go through it. Regression tests prove a sale lowers the balance and a replayed checkout
lowers it once.

For data written before the fix, `php artisan inventory:rebuild-balances [--dry-run]`
recomputes every balance from the ledger and prints what it changed (the ledger is
authoritative; it never edits a movement). It is also the repair path if a balance ever
drifts. It was run on the local development database (2 balances corrected).

## 3. Decisions

- **Negative stock is allowed.** Removing or selling more than the recorded quantity is
  recorded and the balance goes below zero. No frozen code exists for "insufficient stock",
  sales must never be blocked by an inaccurate count (the same rule checkout already
  follows), and a shop often sells before it has entered a delivery. The screen warns
  before an oversized removal and marks negative rows.
- **Writes go to the store's default location.** The request has no location field
  (Stage 6C ruling: V1 always uses the single default location). The screen therefore
  offers Receive/Adjust only on default-location rows and says where changes are added.
- **Only tracked products have stock.** Because sales now move balances for every product,
  products with `track_inventory = false` would show negative balances; the stock list,
  the low-stock list and the `inventory-on-hand` report all exclude them. Receiving stock
  for an untracked product is not blocked by the API (no contract code for it); the screen
  only offers tracked, active products.
- **Every write is audited**: an `AuditEvent` (`STOCK_ADJUSTED`, entity = the product,
  with the type, quantity and reason) and an `ElectronicJournalEntry` referencing it; the
  movement's `reference_type/reference_id` point at the audit event. A receipt's `note` is
  stored as the movement's `reason`.
- **Idempotency is terminal-scoped** like every other terminal write: same key + same body
  replays the original movement (counted once), same key + different body is
  `409 IDEMPOTENCY_KEY_REUSED`. The screen keeps one key while the user retries the same
  request after a dropped connection and issues a new key as soon as any field changes.
- Quantities are strings with up to 3 decimals and must be above zero; movements are
  always stored positive and the type gives the direction (`StockLedger::INFLOW/OUTFLOW`).

## 4. Admin screens

New **Inventory** sidebar section (gated by `STOCK_ADJUST`, Dashboard link):

- `/admin/inventory/stock` — All stock / Low stock, product filter, on-hand with LOW and
  NEGATIVE markers, reorder level, last update, table on `md+` and cards on phones.
  **Receive stock / Adjust stock** open a slide-over: searchable product picker, type
  cards, quantity, on-hand and after-this preview, cost and note (receipt) or required
  reason (adjustment). A banner and disabled buttons appear when the browser is not an
  enrolled terminal.
- `/admin/inventory/movements` — the read-only ledger with product, type and date filters;
  quantities are shown signed (`+20`, `-2`).

Deliberate deviation from `docs/06-ui/sitemap.md`: the sitemap lists separate `receive`,
`adjust` and `low-stock` pages. They are a slide-over (matching catalog and users) and a
tab instead; no route was lost that anything links to. No Stitch mockup was generated —
the screens reuse the established admin patterns.

## 5. Verification

- `InventoryHttpTest` (16), `StockLedgerTest` (6), two checkout tests, one report test and
  one product-list test — 26 new database tests: receipt/adjustment happy paths and
  balance math, audit and journal rows, reason rules, negative balance, idempotency (missing,
  replay, conflict), validation, foreign/unknown product, 401/403 and terminal gating,
  reads (scoping, filters, tracked-only, low-stock boundary, ordering, `per_page` clamp),
  balance equals ledger, and the rebuild command.
- Full regression: Unit 102 + Feature 1 = 103, Database 372 (346 before), Pint clean,
  baseline invariants hold.
- Browser (desktop and phone 375): stock list on real data, receipt with cost and note,
  client validation, adjustment with the required reason and the below-zero warning,
  movements list with type and date filters, product picker search, phone layout without
  horizontal overflow. Two test movements remain in the local development ledger.
- Also fixed on the way: a non-UUID `category_id` on `GET /products` returned a 500, and
  the checkout concurrency test's cleanup did not know about `stock_balances`.
- Not exercised in a browser: the not-enrolled banner (the dev browser is enrolled).

## 6. Not built

Stock transfers (`TRANSFER_IN`/`TRANSFER_OUT` exist as types but no operation writes
them), stock counts and purchase orders (no contract operations), choosing a location for
a write, resolving the "By" column to a name (a manager cannot list users, so a short id
is shown), audit log and electronic journal screens, product CSV import/export, barcode
lookup, and the deferred shift/fiscal-day read endpoints.
