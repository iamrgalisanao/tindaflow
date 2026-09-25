# Stage 25 — Stock counts and transfers between a store's own locations

## Status

**Done and tested**, backend and admin screens. **A scope override, chosen by the owner** (below). Ten new operations
and four new error codes are **forward-committed** into `openapi.yaml`, `operation-inventory.md` and
`error-catalog.md` under the rule the store-setup and users passes already set for wholly new surface (none of them
completes a gap in an already-frozen response). No `stage-*-baseline` tag moved and `scripts/validate-baselines.sh`
stayed green. Frozen documents that are *not* edited here (`scope.md`, `domain-model.md`, `erd.md`) are corrected by
this record and should absorb it at their next change.

## 1. The scope decision

`docs/00-product/scope.md` lists **stocktake** and **inter-store transfers** among the Phase 2 items ("not V1"). The
manifest's backlog called them "stock transfers/counts (types exist, no contract operation)" without saying so, and the
next-step recommendation repeated that, so the conflict was found only when design began. It was put to the owner
(2026-09-20) with three options; the owner chose **counts plus transfers between a store's own locations**.

| Built | Not built |
|---|---|
| Stock counts of one location (draft, review, post, discard) | Transfers **between stores**: they need multi-store tenancy, which contradicts the store-scoped model every operation rests on (a location of another store is simply "not found") |
| Transfers between two locations **of the same store** | An in-transit / receive workflow (see D7) |
| | Purchase orders, batch/expiry, count CSV, blind counting |

Consequence to know: receipts, adjustments and checkout still write only to the store's **default** location (the
Stage 6C ruling, unchanged). A non-default location therefore receives stock only through a transfer or a count, and
sales never take from it. That is what makes a transfer useful (backroom to counter) and is also its limit.

## 2. Research (2026-09-20)

Vendor help centres and API references, each claim marked verified (V) or inferred (I); **UTAK documents almost nothing
on either topic** (guide titles only), so its row is "not documented", not "no".

**Counts.** Every vendor with a documented count uses a **saved draft session with an explicit complete step**
(StoreHub, Loyverse, Lightspeed, Square, Shopify Quick Count, Odoo, ERPNext; V). Uncounted products: Square, Lightspeed
and the old Shopify Stocky full stocktake **set them to zero**, StoreHub asks, partial/cycle counts leave them alone
(V). **Sales during a count is the trap**: Lightspeed and Shopify overwrite stock with the counted value on submit, so
a sale between counting and submitting is lost or double-counted, and both tell users to count when closed (V).
Odoo warns on apply when stock moved (V). **No vendor documents a per-line expected snapshot.** Blind counting is
documented only by Odoo (V). Square's confirm "cannot be undone" but an in-progress count can be discarded (V).

**Transfers.** Most vendors model **draft, send (source deducted), receive**, with cancel allowed only before send (V).
That is built for stores that are far apart. For moves inside one business, Square offers an **instant transfer**,
Shopify "mark as transferred", ERPNext a single Material Transfer entry (V). Only StoreHub documents what happens when
the source has too little (a setting that allows transfers at zero or less; V).

**BIR.** The RMO 24-2023 digest has no requirement on stock counts, adjustments, transfers or inventory in the POS or
the electronic journal; its "adjustment documents" are sales returns, voids, cancellations and refunds (V, digest
only; the full order was not read). Nothing here is compliance-driven.

## 3. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | A count is a **persisted draft** (`OPEN` then `POSTED` or `CANCELLED`) of **one location**, with **at most one `OPEN` count per location** (a partial unique index) | Majority behaviour. Two open counts of one shelf would each post a correction against the same stock and apply it twice; several people share one count instead (each line remembers who counted it) |
| D2 | **Products nobody counted are left alone**, never assumed to be zero | The zeroing behaviour is the most complained-about trap in the research, and an accidental "full count" that empties the shop is unrecoverable |
| D3 | Each line stores the **expected quantity read from the ledger at the moment it was recorded**; posting applies `counted - expected` | Vendors overwrite stock with the counted value, so any sale made after counting is undone. This keeps sales made while people count, and a sale made *before* a product is counted is already in its expected quantity (no false discrepancy). A recount replaces the line and re-reads the expected quantity |
| D4 | Posting writes **one `STOCK_ADJUSTMENT_IN` or `_OUT` per line with a variance** through `StockLedger`, reason "Stock count", referencing the count; zero-variance lines write nothing | Reuses the two existing movement types and invariant #46 (adjustments carry a reason); no new movement type or schema for the ledger |
| D5 | Posting is **final**; a wrong count is corrected by a later count or an adjustment. Discarding an `OPEN` count writes nothing to the ledger | Square's model; the ledger is append-only |
| D6 | Audit: `STOCK_COUNT_POSTED` (lines counted and adjusted, units over and short), `STOCK_COUNT_CANCELLED`, `STOCK_TRANSFERRED`. One `STOCK_ADJUSTED` journal entry per count movement (as for any adjustment); **no journal entry for a transfer** (stock is neither created nor destroyed, and the journal's event list is frozen) | Consistent with Stage 14; draft line edits are working data, not audited |
| D7 | A transfer is **immediate**: one `TRANSFER_OUT` and one `TRANSFER_IN` per product, in one transaction with the transfer record. **No in-transit state, no edit, no cancel**; correct by transferring back | The in-transit workflow exists for distant stores; inside one store the two locations are steps apart. Square, Shopify and ERPNext all offer the instant form |
| D8 | The source **may go below zero**, as with every V1 outflow; the screen warns first | No frozen "insufficient stock" code exists, sales already behave this way, and the recorded quantity may simply be wrong. Only StoreHub documents a rule (a setting) |
| D9 | Only **tracked** products can be counted or moved (`VALIDATION_FAILED` otherwise); inactive tracked products can | Untracked products have no stock (Stage 14) |
| D10 | Every operation needs **`STOCK_ADJUST`, reads included**, and is store-scoped (another store's record is its own `404`, never `403`) | Counts and transfers are management documents (variance figures). Stage 14's three stock reads stay session-only. No new capability was minted (the 17-value catalog is frozen) |
| D11 | Drafting a count is **session-only**; posting a count and creating a transfer need an **enrolled terminal and an `Idempotency-Key`**, like receipts and adjustments | Only those two write the ledger, which attributes each movement to a terminal |
| D12 | **`inventoryLocationList` is loosened to session-only** (it was `FISCAL_CONFIGURATION_MANAGE`) | A MANAGER has `STOCK_ADJUST` but not `FISCAL_CONFIGURATION_MANAGE`, so they got a `403` and the **Stock page** (Stage 14) could not show them location names, a latent gap. A location is just a name; create and update are unchanged |

## 4. Contract added

All ten need `STOCK_ADJUST`. Full request and response shapes are in `openapi.yaml`; the table is in
`operation-inventory.md`.

| operationId | Route | Notes |
|---|---|---|
| `stockCountList` | `GET /inventory/counts` | `status`, `location_id`; newest first; carries `lines_count` |
| `stockCountCreate` | `POST /inventory/counts` | optional `location_id` (default location), `note`; `409 STOCK_COUNT_ALREADY_OPEN` |
| `stockCountGet` | `GET /inventory/counts/{id}` | lines by product name, plus a `summary` of what posting does |
| `stockCountLinesRecord` | `PUT /inventory/counts/{id}/lines` | 1 to 500 lines, all or nothing; zero is a valid count |
| `stockCountLineRemove` | `DELETE /inventory/counts/{id}/lines/{productId}` | removing an absent line is not an error |
| `stockCountPost` | `POST /inventory/counts/{id}/post` | terminal + `Idempotency-Key`; a count with no lines is `VALIDATION_FAILED` |
| `stockCountCancel` | `POST /inventory/counts/{id}/cancel` | |
| `stockTransferList` | `GET /inventory/transfers` | `location_id` (either side), `product_id`, `from`, `to` |
| `stockTransferCreate` | `POST /inventory/transfers` | terminal + `Idempotency-Key`; 1 to 200 items; destination must differ |
| `stockTransferGet` | `GET /inventory/transfers/{id}` | |

New codes: `STOCK_COUNT_NOT_FOUND` (404), `STOCK_COUNT_NOT_OPEN` (409, `details.status`),
`STOCK_COUNT_ALREADY_OPEN` (409, `details.stock_count_id` so a second counter can join),
`STOCK_TRANSFER_NOT_FOUND` (404). A location of another store, or an unknown one, is the existing
`INVENTORY_LOCATION_NOT_FOUND`. Idempotent operations are now **16** (`STOCK_COUNT_POST`, `STOCK_TRANSFER`).

## 5. Data model

Migration `..._create_stock_counts_and_transfers_tables`: `stock_counts` (status check, a check that the state's own
timestamp and actor agree with the status, and the partial unique index `stock_counts_one_open_per_location`),
`stock_count_lines` (unique per count and product, `counted_quantity >= 0`, `expected_quantity`, `stock_movement_id`
set when posting produced a correction), `stock_transfers` (`from <> to` check) and `stock_transfer_lines`
(`quantity > 0`). Migration `..._extend_idempotency_operation_types_for_counts_and_transfers` replaces the
`idempotency_records_operation_type_check` constraint (the 14 original values kept) to match
`IdempotencyOperationType`. Neither document is a balance: on-hand still changes only through `StockLedger`, and
`php artisan inventory:rebuild-balances --dry-run` is used in the tests to prove balances still equal the ledger.

## 6. Concurrency

`StockCountService` takes a **row lock on the count** for every edit, post and cancel, so two people posting the same
count adjust stock once (the loser gets `STOCK_COUNT_NOT_OPEN`) and a post racing a cancel has one winner. Both
services write their ledger rows in a **fixed order** (product id for a count, location then product for a transfer),
because two transfers moving the same products in opposite directions otherwise deadlock on each other's balance rows.
`StockCountTransferConcurrencyTest` proves all three with separate OS processes; the deadlock test was checked to have
teeth by removing the ordering, which made it fail on 3 of 3 runs.

## 7. Behaviour to know

- **The expected snapshot is taken when a line is *saved*, not when the shelf was physically counted.** A sale made in
  between is counted against the line. Save products as you count them (the screen saves each one at once) and, for a
  precise count, count outside trading hours. This is still strictly better than overwriting, and no vendor snapshots at
  all.
- **A location is counted or moved by explicit id**; only the default location is ever sold from or received into by
  the other operations. Counting a non-default location works and is how its opening quantities are entered.
- **Expected quantities are visible** to the counter (the screen shows "System expected" beside "Found"). Only Odoo
  documents a blind mode; STOCK_ADJUST holders can already read on-hand from the Stock page, so hiding it here would be
  theatre. Add it if a store asks.
- **Found, not fixed (needs an owner decision):** stock balance rows are not part of `GlobalLockOrder` (shift, fiscal
  day, sale, sale item, invoice series), and `CheckoutService` writes its ledger rows in **cart order**. Two concurrent
  checkouts with the same two products in opposite order, or a checkout and a count post, can deadlock on those rows
  and PostgreSQL will abort one (the client's retry with the same `Idempotency-Key` is safe). The stage's own
  operations are ordered; sorting checkout's stock writes by product id is a one-line change but `app/Services/Checkout/`
  is frozen under the stage-6c baseline, and the mechanism was reproduced here for transfers, not separately for
  checkout.

## 8. Admin screens

Inventory sidebar gains **Counts** and **Transfers**.

- `/admin/inventory/counts`: status tabs, a "Start a count" panel (location, note; if the location already has a
  count the error links to it), a table on `md+` and cards on phones.
- `/admin/inventory/counts/:id`: the worksheet. Add a product and what was found, edit a found quantity in place
  (saved when you leave the field), remove a line, see **System expected, Found, Difference** with a summary
  ("3 products counted, 2 differ (3 found extra, 2 missing)"). **Post count** (confirm dialog) needs an enrolled
  terminal and says so when there is none; **Discard count** confirms. A posted or discarded count is read-only.
- `/admin/inventory/transfers`: the list, a read-only detail slide-over, and a **Move stock** panel (from, to, products
  with an "on hand at the source" preview and a below-zero warning that does not block). It explains when the store has
  fewer than two locations.
- The audit log describes the three new events in plain sentences.

## 9. Verification

- `StockCountHttpTest` (24), `StockTransferHttpTest` (14), `StockCountTransferConcurrencyTest` (3, real processes),
  three new `InventoryLocationHttpTest` cases (list open to a manager and cashier, create and edit still admin-only,
  guest refused): balances stay equal to the ledger, sales made while counting are kept, uncounted products untouched,
  idempotent replay, key-reuse conflict, final states, cross-store `404` on every operation, capability and terminal
  gating, all-or-nothing input validation.
- Frontend built. Driven in the Browser pane against the real app with a **temporary mocked API** (removed afterwards;
  the pane was never signed in): start a count, add three products, edit one in place, post through the confirm dialog,
  the not-enrolled state, a second count on the same location, a transfer with the below-zero warning, the transfer
  detail, and 375px width with no horizontal overflow on all three pages.
- **2026-09-25 — exercised against the real backend, signed in as the admin.** Started and posted a stock count of two
  products with a variance each (a third product left alone, uncounted); added a second location and moved stock to
  it, then confirmed the transfer detail, the Stock page's per-location rows, and the movements ledger all agreed on
  the same numbers. No defect found; no code changed.
- Full regression and Pint: see the manifest's Stage 25 section for the counts.

## 10. Not built

Transfers between stores; an in-transit or receive step; editing or cancelling a transfer; reversing a posted count;
blind counts; count CSV import or export; choosing a location for receipts, adjustments or checkout; purchase orders;
alternate-barcode management (still on the backlog).

## 11. Research on the checkout deadlock (2026-09-20)

Read from PostgreSQL 17's documentation, the source of eight open-source systems and your installed Laravel 13.32; V means
read at the source, I means inferred.

- **PostgreSQL (V).** "The best defense against deadlocks is generally to avoid them by being certain that all applications
  using a database acquire locks on multiple objects in a consistent order", with two transactions updating the same two rows
  in opposite order as the worked example; and "deadlocks can be handled on-the-fly by retrying transactions that abort due to
  deadlocks" (SQLSTATE `40P01`). Which transaction is aborted "should not be relied upon". `INSERT ... ON CONFLICT DO UPDATE`
  row-locks what it updates until the transaction ends, and the page says nothing about multi-row order.
- **Who orders their stock writes (V).** Saleor (`order_by("pk")` with `select_for_update`), WooCommerce stock holds
  (`ksort` before reserving, commented as avoiding "cross-product ordering deadlocks from concurrent orders with same products
  added in different sequences"), and ERPNext (`sorted({(item_code, warehouse)})` before taking locks). Odoo retries instead:
  `DeadlockDetected` is in its retry list, up to 5 tries with jittered backoff; Frappe maps a deadlock to HTTP 508.
- **Who does neither (V).** OSPOS runs `quantity = quantity + delta` per cart line in cart order inside one transaction, which
  is exactly our checkout and is exposed to the same cycle (no OSPOS report found); Bagisto and Medusa also do not order or
  retry. Magento MSI avoids the shared row altogether with append-only reservations, and Shopify's inventory write-up uses one
  row per unit with `SKIP LOCKED`; both are throughput solutions we do not need at a till (I).
- **No system returns 409 for a deadlock (not found).** They hide it with a retry or fail it loudly. A 409 is our own choice.
- **Laravel (V).** `DB::transaction($callback, $attempts)` retries a deadlock only when asked (default 1 attempt, so a
  `QueryException`, which is our 500). Detection of `40P01` is by error-message text, not by code (I: a server with a
  non-English `lc_messages` might be missed), and **a nested transaction never retries**, so a retry must sit at the
  outermost boundary.
- **Here (V, read from our code).** `DatabaseExceptionTranslator` (Stage 6A, frozen) maps only unique, foreign-key and check
  violations to `409 CONCURRENCY_CONFLICT`; a `40P01` falls through and surfaces as a 500. That class is not wired into any
  service today (only its own test uses it).

**Options, from the evidence.** (1) **No frozen file touched:** render a deadlock (`40P01`, and `40001`) as the existing
`409 CONCURRENCY_CONFLICT` in `bootstrap/app.php`'s exception handler (edited forward before, e.g. stage 23) and have the POS
retry once with the same `Idempotency-Key`, which is already safe; this removes the 500 at the till but not the cycle.
(2) **Removes the cycle, edits frozen `app/Services/Checkout/`:** sort checkout's stock writes by `(location_id, product_id)`,
which has direct precedent in Saleor, WooCommerce and ERPNext and matches what the count and transfer services already do.
(3) **Also needs a frozen edit** (`app/Services/Idempotency/`, stage 6a): a bounded retry of the whole checkout transaction at
its outermost boundary, as Odoo does. Mature systems that handle this use ordering plus retry; none rely on the database's
detection alone by design. My recommendation is (1) now and (2) once you approve a recorded exception for the frozen path.

**Update (2026-09-21):** option 1 was built in stage 27 (`docs/06-backend/stage-27-deadlock-handling.md`); options 2 and 3 still need your approval of an exception for the frozen path.

**Update (2026-09-21, stage 28):** option 2 was built as a documented concurrency invariant (all stock writers, one shared ordering) under an owner-approved exception to the frozen checkout folder. **Correction:** the section 7 claim that two concurrent sales with the same products in opposite order can deadlock was overstated. Checkout holds the invoice series row lock from numbering to commit, before it writes stock, so checkouts that share a series run one at a time and cannot deadlock on stock rows; a control test confirmed it. The cycle needs checkouts on different series, or a checkout against a count, transfer, void or refund. See `docs/06-backend/stage-28-stock-write-ordering.md`.
