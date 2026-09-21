# Stage 29 — Packs, and receiving stock by the pack

## Status

**Done and tested**, backend, contract notes, and the two admin screens (product panel, Receive stock panel). It follows
the owner's decision (2026-09-21): approve a **receiving-first** pack quantity on top of a packaging model that can later
carry checkout, with stock always kept in the product's own (base) unit and pack selling **off**. **No frozen file edited**,
no `stage-*-baseline` tag moved, no new error code (a bad field is the existing `422 VALIDATION_FAILED`). The one frozen
contract operation this touches, `inventoryReceiptCreate`, is extended additively (section 4); `openapi.yaml` is
intentionally not edited for it.

## 1. The problem

A store buys a case of 24 and sells singles. Until now the person receiving had to do the multiplication by hand, and
Stage 26's alternate barcode was only an alias: it could say "this code finds this product" but not "this code is 24 of
it". Research (recorded in stage 26 and the manifest) found three patterns in other systems: a quantity on the barcode row
(Odoo 17), a separate composite item, and unit-of-measure conversion (ERPNext). Receiving by the case is the conventional
first use of any of them; a distinct pack **price** at the till is common but needs a checkout decision that is frozen.

## 2. Model

**`product_barcodes` rows are the product's packagings.** A plain Stage 26 alternate barcode is simply a packaging that
holds one unit. New columns (migration `2026_09_21_094226_add_packaging_to_product_barcodes_table`):

| Column | Meaning |
|---|---|
| `name` `varchar(60)` null | "Case", "Tray"; unique per product ignoring case |
| `units_per_base` `numeric(10,3)` default 1 | how many of the product's own unit it holds; `> 0` (CHECK) |
| `can_receive` bool default true | may stock be received in this pack |
| `can_sell` bool default **false** | reserved for pack selling; stored, **not exposed, not read** anywhere |
| `barcode` now **nullable** | a pack needs no barcode; CHECK: it has a barcode **or** a name |

Barcode uniqueness is unchanged: per store, across `products.barcode` and `product_barcodes.barcode`, under Stage 26's
store-wide advisory lock (`ProductBarcodeService::claim()`), and taken only when a barcode is given.

### Deviation from the owner's proposed shape (for veto)

The owner's outline named `ProductPackaging` (name, units_per_base, barcode, can_receive, can_sell, is_base_unit) plus a
`StoreProductPackaging` table for per-store `selling_enabled`. I built neither as separate things:

- **No `is_base_unit` row.** The base unit is the product's own `unit_of_measure`; a base row would duplicate it and add a
  rule ("exactly one base row") to keep true. Stock stays canonical in base units, as decided.
- **No per-store table.** In this schema a product belongs to one store already (`products.store_id`), so a packaging is
  per store by construction and a second table would only repeat `store_id`. If products ever become shared across stores,
  a per-store enablement table is the additive step, and `can_sell` is already the column it would refine.
- **Reusing `product_barcodes`** rather than a new `product_packagings` table avoids a second table with its own barcode
  uniqueness rules and a second place the Stage 20 scan lookup must consult.

## 3. Contract (forward-committed)

`productBarcodeCreate` and `productBarcodeList` in `openapi.yaml` and `operation-inventory.md` (both were forward-committed
in stage 26, so editing them is not a frozen edit): `ProductBarcode` now has `barcode` (nullable), `name`, `units_per_base`,
`can_receive`. Create takes `barcode?`, `name?` (at least one), `units_per_base?` (default 1, up to 7 whole and 3 decimal
places, above zero), `can_receive?`. A name already used on the product (any case) is a `422` field error on `name`.
`can_sell` is deliberately not in the contract.

## 4. Receiving by the pack (additive to a frozen operation)

`inventoryReceiptCreate` (frozen, Stage 14) takes `{product_id, quantity, movement_type, unit_cost?, note?}`. It now also
accepts, **instead of** `quantity` and `unit_cost`:

| Field | |
|---|---|
| `packaging_id` | uuid of one of **this product's** packagings in the caller's store with `can_receive` |
| `packs` | how many arrived, up to 3 decimals, above zero |
| `pack_cost` | optional, the cost of one pack, exactly two decimals |

`packaging_id` prohibits `quantity` and `unit_cost` (and `packs`/`pack_cost` require `packaging_id`), so a request cannot say
both. A request without `packaging_id` behaves exactly as before, and every existing receipt test passes unchanged. Because
the change is additive and optional it is recorded **here** and the frozen `inventoryReceiptCreate` text is not edited; a
client that knows only the frozen contract is unaffected.

**The server does the conversion, never the client** (the client preview is only a preview):

- `units = packs x units_per_base`, exact to 3 decimals. If the result is not a whole number of thousandths, or
  exceeds the ledger's `NUMERIC(10,3)` (9,999,999.999), it is `422` on `packs`. Nothing is silently rounded.
- `unit_cost = pack_cost / units_per_base`, rounded **half up to the centavo**, because `stock_movements.unit_cost` is
  `NUMERIC(12,2)` and the receipt's cost is exactly two decimals. 1,153.92 for a case of 240 is 4.81 a unit (4.808). The pack
  cost, which is what was actually paid, is **kept in the audit snapshot**, so the rounding loses nothing on the record.
- The ledger and stock balance receive base units only. Stock stays canonical; there is no second quantity to reconcile.

The `STOCK_ADJUSTED` audit event and the journal entry gain a `packaging` snapshot `{packaging_id, name, barcode,
units_per_base, packs, pack_cost}`: a copy taken at receiving time, so renaming or deleting the pack later cannot rewrite
history. The movement's reason, if the person gave none, reads "5.000 x Case (240.000 units each)". The Movements page
shows "(5 x Case of 240)".

## 5. Screens

- **Product panel**: "Packs and other barcodes": name, units and barcode, each saved at once as in Stage 26; the list shows
  `Case x 240 units` with its barcode (or "no barcode"). Enter adds without submitting the product form.
- **Receive stock**: when the product has a pack that can be received, a "Received as" choice (single units by default, or
  each pack). Choosing a pack relabels the fields ("Number of Case", "Cost of one Case (P)") and shows the conversion before
  the button is pressed ("= 1,200 units at P4.81 each", with a note that the unit cost is rounded and the pack cost is kept).
  The after-balance is in units. The server's field errors are mapped back onto those two fields. `packMath.js` mirrors
  `PackagingMath` in exact integer arithmetic; it agreed with the PHP on 16 of 16 sample values.

Checked in a real browser against a temporary mocked API (removed afterwards; my browser pane is not signed in): the pack
list, duplicate-name error, Enter-to-add, the receive preview and request body, and no horizontal overflow at 375 px. **A
walk-through against the real backend with a signed-in session is still to do.**

## 6. Tests

- `PackagingMathTest` (5, pure arithmetic): packs to units, a non-whole-thousandths count refused, the ledger ceiling, half-up
  unit cost, and that a rounded unit cost does not always return the pack cost (which is why the pack cost is kept).
- `ProductPackagingHttpTest` (11, PostgreSQL): a named pack with size and barcode, a pack with no barcode, a plain alias is one
  unit and audited exactly as before, the audit records a pack's name and size, a row needs a barcode or a name, size and name
  validation, duplicate names refused whatever the capitals, `can_receive` off, barcode uniqueness beside barcode-less packs,
  the database CHECKs refuse an empty or zero-unit row, and a CSV import still works with barcode-less packs in the store.
- `StockReceiptPackagingHttpTest` (12, PostgreSQL): 5 x 240 = 1,200 units at a derived 4.81, the audit and journal snapshot,
  a later change of pack size never rewrites a past receipt, no pack cost leaves the unit cost empty, half-up rounding with the
  pack cost kept, a fraction of a pack and a barcode-only alias, non-exact and oversize counts refused, `quantity` mixed with
  `packs` refused, only a receivable pack of this product and store accepted, a retried receipt counts once and the key
  cannot be reused for another request, the same capability and terminal as any receipt, and the plain receipt unchanged.

## 7. Not built

- **Selling by the pack** (`can_sell` is stored and unread), and any **distinct pack price**: both change checkout, which is
  frozen and would need its own recorded decision.
- Per-store enablement (nothing to enable: packs are per product, products are per store; section 2).
- A Stock page shown as "10 cases + 17 pieces".
- Other movements (adjustments, counts, transfers) by the pack; they stay in base units.
- Backfill: existing alternates keep `units_per_base = 1` and behave exactly as before.
