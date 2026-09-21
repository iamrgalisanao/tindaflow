# Stage 26 — Alternate barcodes

## Status

**Done and tested**, backend and the product edit panel. Three new operations are **forward-committed** into
`openapi.yaml` and `operation-inventory.md` (wholly new surface, no prior draft at any stage). **No new error code**:
a code already in use is the catalog's existing `422 VALIDATION_FAILED` field error. No `stage-*-baseline` tag moved
and `scripts/validate-baselines.sh` stayed green. `domain-model.md` (§2.3 `product_barcode`) and `erd.md` are frozen and
not edited; nothing here changes them.

## 1. What was missing

A product's **own** barcode (`products.barcode`) has always been editable. The alternate table
(`product_barcodes`: another supplier's packaging, a multipack) existed from Stage 5 and the Stage 20 scan lookup
already matches it, but **no operation could create one**, and the product form edited only the main barcode. So
alternates could not be used at all.

## 2. Contract added

| operationId | Route | Auth | Notes |
|---|---|---|---|
| `productBarcodeList` | `GET /products/{productId}/barcodes` | session | like the other catalog reads; paginated; the product's own barcode is not repeated |
| `productBarcodeCreate` | `POST /products/{productId}/barcodes` | `CATALOG_MANAGE` | body `{barcode}`; `201 ProductBarcode`; audited `PRODUCT_BARCODE_ADDED` |
| `productBarcodeDelete` | `DELETE /products/{productId}/barcodes/{barcodeId}` | `CATALOG_MANAGE` | `204`, idempotent; audited `PRODUCT_BARCODE_REMOVED` |

A product of another store is `PRODUCT_NOT_FOUND` on all three. `CATALOG_MANAGE` now gates 9 operations.

## 3. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | A barcode identifies **at most one product per store** across `products.barcode` and every alternate; a repeat is `VALIDATION_FAILED` on `barcode`, with a message that says which case (already this product's main barcode, already one of its alternates, or another product's) | Stage 12's decision, extended to the new writer; no new code because the contract already treats a duplicate barcode as a field error |
| D2 | **Every writer of a barcode takes one per-store advisory lock, then checks the code is free**: adding an alternate, creating or updating a product's own barcode, and each CSV import row (`ProductBarcodeService::claim`, `pg_advisory_xact_lock`) | The two tables have their own unique indexes but **no index can span them**, so before this stage the cross-table rule lived only in a FormRequest check made *before* the write. With a second writer that is a real race: adding alternate X to one product while another product is given X as its main barcode could both succeed, leaving a code that identifies two products, so a scan would pick one arbitrarily |
| D3 | Setting a product's main barcode to **one of its own alternates moves it**: the alternate row is dropped instead of leaving the code in both places | A promotion; the code stays scannable and the list never shows the main barcode twice |
| D4 | Removing is a real delete, `204`, and **idempotent**: a missing id, or one that belongs to a different product, changes nothing (the delete is scoped to the product in the URL) | An alternate is a lookup key, not accounting data, and sale lines keep their own barcode snapshot; scoping stops an id of another product being removed through this one |
| D5 | Only surrounding whitespace is trimmed (a scanner's line break); the code is otherwise stored **exactly** as given, case included; 1 to 255 characters | Matches the Stage 20 lookup, which is exact. No format, checksum or length rule beyond the column is invented, and there is **no limit on how many alternates** a product has |
| D6 | Inactive products can have alternates, and a scan still finds them (Stage 20 returns them as `active: false`) | Consistent with Stage 20 |
| D7 | Every add and remove is audited (`PRODUCT_BARCODE_ADDED`, `PRODUCT_BARCODE_REMOVED`, with the sku and code); the audit log describes them in plain sentences | Stage 24 decision 2: product changes are audited |

The design above completes an existing table and lookup and adopts no vendor behaviour. **Research was added afterwards** (section 9); it confirms D1 and D5 and answers the multipack question in section 6.

## 4. The concurrency guarantee

`ProductBarcodeConcurrencyTest` races two real OS processes: one adding alternate X to a product while another gives
X to a different product as its main barcode (three rounds), and two products racing to add the same alternate.
Exactly one wins every time and the code ends up identifying exactly one product. With the advisory lock disabled the
cross-table test **fails on 3 of 3 runs**, so the lock is what closes it; the same-table case is also guarded by the
table's own unique index.

## 5. Admin screen

The product **edit** panel gains **Other barcodes** under the barcode field: the current alternates each with **Remove**,
and a box to scan or type a new one with **Add**. A scanner types the code and presses Enter, which adds it (and does not
submit the product form). Changes are **saved at once**, separately from "Save changes", because the server is the only
judge of whether a code is free; a code in use elsewhere shows the server's message under the box. A new product shows
"Save the product first" because it has no id yet. Phone layout has no horizontal overflow.

## 6. Behaviour to know

- **Multipacks.** The domain model calls these "alternate/multipack barcodes", but the table has no quantity, so scanning
  any alternate sells **one unit of the product**. A six-pack barcode that should sell six needs a quantity per barcode:
  a schema and lookup change and a business question (does it price differently?). **Not invented; raised for the owner, and
  researched in section 9, which recommends the smallest safe step.**
- The list is oldest first; codes added within the same second are ordered by code.
- `product_barcodes.is_primary` (a Stage 5 column) is not used: the main barcode lives on the product and every row here is
  an alternate (`false`).
- CSV export and import carry only the main barcode (the column layout is fixed and may only be appended to). An import
  that sets a product's main barcode to another product's alternate is refused for that row, and to its own alternate
  promotes it (D3).

## 7. Verification

- `ProductBarcodeHttpTest` (17): add and scan through the real lookup, a product with no main barcode, whitespace
  trimmed and case kept, an inactive product, audit rows, the list (only that product's, oldest first, readable by a
  cashier, paged), cross-store and unknown products on every operation, validation, every duplicate case with its message,
  the same code allowed in another store, removal and re-adding, idempotent removal that cannot touch another product's
  row, `403`/`401`, promotion of an alternate to main, refusal of another product's alternate as a main barcode on
  create and update, and the CSV import cases.
- `ProductBarcodeConcurrencyTest` (2, real processes) as above.
- Driven in the Browser pane against the real app with a **temporary mocked API** (removed afterwards; the pane was
  never signed in): the existing alternates listed, a code belonging to another product refused with the message shown,
  a new code added, a scanner-style Enter adding one without submitting the product form, removal, the new-product
  hint, and 375px width with no overflow. **Not exercised against the real backend in a browser.**
- Full regression and Pint: see the manifest's Stage 26 section for the counts.

## 8. Not built

A quantity per barcode (multipacks); alternate barcodes in the CSV export or import; making an alternate the main
barcode in one step that also demotes the old main into an alternate (today the old main is simply replaced); searching
the product list by an alternate barcode (the scan lookup already finds it).

## 9. Research on multipack barcodes (2026-09-20)

Two researchers read vendor help centres, API references, open-source code and the GS1 standard; every claim is marked
V (read at the source) or I (inferred). Coverage gaps are stated: **UTAK's guides could not be read as text**, StoreHub
and Clover do not document multiple barcodes, Lightspeed R-Series is thinly documented, Bagisto was not checked, and the
GS1 General Specifications were read from a mirrored copy of v21.0.1 because gs1.org returned 403.

**Multiple codes per item (D1, D5).** Lightspeed X-Series (many typed codes per item, unique per store, a warning in the UI
and a hard error in the API), Shopify (up to 20 barcodes per product or variant) and Toast Retail (since Aug 2025) support
it; Square and Loyverse do not (V). Every project that has an extra-barcode table enforces uniqueness: Odoo globally,
ERPNext across all items, Lightspeed X per business (V). **Toast is the exception: it allows the same code on several items
and asks the cashier to choose** (V). This supports rejecting duplicates as built; Toast's chooser is the alternative if a
store ever needs shared codes. Lightspeed X keeps extra codes "internal use only" (imported by CSV, not exported), which
matches leaving them out of our CSV.

**Multipacks: three patterns exist, and no POS vendor uses the one I had feared was missing.**

| Pattern | Who | Trade-off |
|---|---|---|
| **A. A quantity on the extra barcode row** | **Odoo 17** `product.packaging` (`qty`, `barcode`; the POS sets the line quantity to the packaging quantity and keeps the product's unit price) and OCA add-ons (V, source read) | Smallest; stock stays in base units; price is only N x unit price (Odoo has no pack price field) |
| **B. A separate pack item that draws down the base item's stock** | StoreHub and Loyverse composite items, Lightspeed X Composite, OSPOS `qty_per_pack` design, Medusa inventory kits (V) | Own price and receipt line; needs a bundle feature that deducts components at checkout |
| **C. Unit-of-measure conversion** | ERPNext `Item Barcode.uom` and Odoo 19 `product.uom` (V), Square sell-by units, Lightspeed X Packaged Products and R-Series Box (V) | Own SKU and price per unit; heaviest (conversion tables, per-unit prices); ERPNext's scanned line stays "1 Box" |

No POS vendor documents a quantity or price attached to a secondary code (Toast, Shopify and Lightspeed X say nothing
either way), so vendors solve packs with B or C, while **Odoo 17 is verified precedent for A**. Users report the pain
of *not* modelling it: OSPOS issues #1124 and #1919 (one dozen versus one piece merging into one line), ERPNext bugs where a
scan ignored the unit or the rate did not follow it (#34755, PR #44147), and Loyverse and Square users asking how to sell
a 24-pack, a 6-pack and a single from one stock.

**GS1 (V, General Specifications).** A multipack sold at the till as one unit gets its **own GTIN** (a 3-pack and a 6-pack
of the same item need different ones, s.4.3.4.3.1), the number carries **no quantity** (s.2.1.7.2, the indicator digit
"has no meaning"), and the multipack code should be the only symbol visible on the pack to avoid a double scan (s.6.4.10).
Its modelling guidance (s.7.6) is a link table with a **"quantity of items contained"** column and inventory kept as one
base-item SKU. A pack quantity on the barcode row is therefore the standard's own minimum model, and quantity must never
be derived from the GTIN's format.

**Philippine rules.** NIRC s.237 as amended by RA 11976 requires the invoice to show quantity, unit cost and description
(V); no BIR, DTI or FDA rule on barcodes was found, and reseller claims that barcodes are not legally required are
secondary. I: a pack line must not print a quantity and a unit cost that disagree ("6 @ pack price").

**Recommendation (a proposal, not built; it needs the owner's yes).** If a pack sells at N times the unit price, add a
`pack_quantity` (default 1) to `product_barcodes`, return it from the scan lookup as an additive field, and have the POS
add that many units. Stock, the invoice line (quantity N at the unit price, which satisfies s.237) and checkout are
**unchanged**, so no frozen checkout code is touched; the new column and one response field are the whole change. It cannot
express a pack **price** different from N x unit price; that needs pattern B or C and should wait until a store asks. Before
building, confirm with the owner: do packs ever sell at a different total price than N units?

## 10. Follow-up research on pack pricing (2026-09-20)

The proposal in section 9 assumed a pack sells at N times the unit price. Research on that assumption (V = read at the
source, I = inferred; UTAK, Kyte, Imonggo, Moneypad, Zettle PH, GCash and Grab merchant POS and eSari document nothing on
packs or units that I could read, so they are "not documented"):

- **A distinct pack price is the norm wherever a pack is sellable at all.** Loyverse (moderator advice: a separate "box" item
  with its own barcode and price, stock drawn from the single item) and StoreHub (price books with minimum and maximum
  quantities and customer tags, composite items) are the Philippine-relevant vendors; Lightspeed X price books, Shopify volume
  pricing (up to 10 breaks, on a separate case product) and Odoo and ERPNext quantity-break rules do the same (V).
  Loyverse users complain that this doubles the catalogue.
- **Four patterns for pack pricing:** a separate pack item with its own price; a price per unit of measure; a quantity-break
  rule on the line; and price levels per customer group.
- **Philippine practice (V, thin).** A distributor price list shows the case price as primary and the unit price derived
  and rounded (Fita crackers: case P1,153.92, per piece P4.81, but 240 x 4.81 = P1,154.40), so "N x unit" already fails at the
  centavo. Tingi is priced above cost divided by N (secondary source). No DTI or DA document on pack versus unit price was
  found.
- **Rules (V).** RA 7394 Art. 81-82 requires a price per article, per unit in pesos and centavos, and that an article not be
  sold above its tag "without discrimination to all buyers"; I: a pack with its own price is compatible, a **customer-group
  wholesale price may be in tension** with that wording (needs legal judgement), and a quantity break open to every buyer is
  safer. RA 11976 needs quantity, unit cost and description on the invoice; how ordinary line discounts must appear was not
  verified.

**What this changes.** "N x unit only" is arithmetically safe but not what competitors treat as basic, and **any distinct pack
price needs checkout to know it**, because checkout recomputes every price from the product on the server (Stage 2
invariant #4): a separate pack item with its own price, or a price per unit, is a **change to frozen checkout** and, for a
separate item, a stock deduction of the single units too. That is a larger stage and an owner decision, not a small column.

**A refinement that avoids the frozen path (I, mine).** Philippine distributors price by case and small stores usually buy
by the case and sell by the piece (tingi), so the sharper need may be at **receiving**, not selling: scan the case code in
Receive stock, add N units, and set the unit cost to the case cost divided by N. That uses the same `pack_quantity` on the
barcode row, touches only the Stage 14 receipt code (not frozen), needs no pricing decision, and matches GS1's "quantity of
items contained" link. Toast Retail lists "receiving units for cases" (a search snippet, not opened). **Options for the
owner:** (a) leave alternates as one-unit aliases (today); (b) add `pack_quantity` and use it for **receiving** first, and for
selling at N x unit price only for shops that confirm their packs are priced that way; (c) real pack pricing (pattern 1, 2 or
3), which needs a frozen-checkout decision and should wait for a store to ask.

## 11. Implementation research on multipacks (2026-09-21)

Two more researchers looked at how others build the mechanics: receiving stock by the case, and how a pack line behaves
once sold. V = read at the source (Odoo, ERPNext and Frappe code was read through summaries of the raw files), I =
inferred. **Gaps:** UTAK documents nothing readable on either; ERPNext's Repack docs timed out; Revel returned 401; DTI DAO 09
could not be fetched; how UTAK, StoreHub PH and Loyverse-in-PH print pack sales is not documented.

**Receiving by the case (V).** "Receive in cases, keep stock in units, unit cost = case cost / pack size" is the **dominant,
conventional design**: ERPNext (`conversion_factor` copied onto each receipt row, `valuation_rate = amount / (qty x factor)`),
Odoo (`product.packaging` with a contained quantity and a barcode; scanning it adds its units; the PO line carries the
packaging), Toast Retail (receiving unit with a quantity per unit, per-unit cost calculated), Katana and Clover Sport. Lightspeed
X and R, Loyverse and StoreHub instead receive a separate case item and then **break** it into units (recorded as a queued
breakdown, or a disassembly document), and Lightspeed's breakdown "cannot be undone". Square cannot receive a case at all unless
it is the primary unit. **Our design keeps the piece as the only stock unit, so there is nothing to break and Square's limit
does not apply.** Odoo's packaging record (a quantity plus a barcode) is the closest precedent to a `pack_quantity` on the
barcode row; no source stores the pack size on a barcode row *for receiving* other than that. Cost after receiving is a rolling
average or FIFO per **unit**, never per case (Lightspeed R, StoreHub, Square, Shopify).

**Pitfalls the sources warn about (V, user reports and bug trackers).**
- **Rounding.** A Square user's $65.43 box divided by 20 drifts a cent per unit and accumulates; an Odoo user's EUR 14.26 for
  8 L needed 0.7825 per litre but the order showed 0.7800; StoreHub composite quantities stop at 3 decimals.
  **Our ledger is worse off: `stock_movements.unit_cost` and the receipt request are exactly two decimals (`NUMERIC(12,2)`,
  regex `\d{1,10}\.\d{2}`), so a case cost of 1153.92 for 240 units, which is 4.808, would be stored as 4.81 and 240 x 4.81 is
  1154.40, not 1153.92.** Store what was received (the case count, the pack size and the case total) beside the rounded unit
  cost, and do not treat the rounded figure as the truth.
- **A pack size that changes.** ERPNext copies the factor onto every receipt row so old receipts stay right; two of its bugs
  (#26789, #10889) are what happens otherwise. Snapshot the pack quantity on each receipt.
- **Several suppliers with different case sizes for one product.** Only Odoo models it (a per-supplier unit). Giving each
  barcode row its own pack quantity, and letting the user choose the row when receiving, covers it.

**After a pack is sold (V unless marked).** Three models coexist: a separate pack item or kit (Loyverse, OSPOS), a pack as a
unit of measure with its own row or variation (ERPNext, Square), and **a pack code that multiplies the quantity onto the base
line (Odoo POS), which is our design**. Refunds in Odoo, Lightspeed and Square reference the original line, count in its unit
and are capped at sold minus already refunded; none warns about part-pack returns. Counting in packs is a **display
conversion** (Square converts to the stock unit; ERPNext counts in the stock unit only). ERPNext's documented row-merge bugs
arise only when units are **mixed** on one line; a base-unit-only design always merges. RR 7-2024 requires "quantity, unit
cost and description" (V, from a copy of the full text); whether quantity means packs or pieces is not stated, so "6 x P10.00"
satisfies it (I). RA 7394 Art. 82 requires the price "per unit"; which unit is not stated.

**Barcode collisions.** GS1 (V) says a multipack symbol "should be the only visible symbol" and that a change of pack quantity
needs a new GTIN, so a manufacturer should never reuse one code for a case and a single. The POS documentation I found is
silent on a case code that equals a single code and on a shop label placed over a manufacturer's pack code. Our uniqueness
rule cannot represent one code with two multipliers and would reject the second, which is the safe answer.

| Edge case | Our proposed design |
|---|---|
| Part-pack refund | Handled: refunds are per original line in units and capped |
| Receipt "6 x P10.00" | Handled; meets RR 7-2024 (I). An optional "(1 pack)" note is a choice, not a requirement |
| Counting packs | Handled with units as the ledger; entering packs is only a UI multiplier |
| Merging lines | Handled: everything is base units, so lines merge. The cashier cannot tell one pack scan from six single scans (I) |
| Case code equal to a single code | Rejected by the per-store uniqueness rule |
| Unit cost from a case cost | **Needs care:** two-decimal `unit_cost`; record cases, pack size and case total beside it |

**The proposal, now better supported (not built; needs the owner's yes).** Add `pack_quantity` (default 1, greater than zero)
to `product_barcodes`, a forward migration, and accept and return it on the alternate-barcode operations (additive). It is used
first for **receiving**: the Receive stock panel lets you scan or pick a pack barcode, enter the number of cases and the case
cost, and sends the **existing** fields (`quantity` in units, a rounded `unit_cost`, and a `note` such as "5 cases of 24 at
P1,153.92") to `inventoryReceiptCreate`, so **the receipt operation and the ledger need no change**. Selling by the pack
(a scan adding `pack_quantity` units at the unit price, through an additive field on the scan lookup and the POS screen)
touches no frozen server code either, but should be switched on only for shops whose packs really sell at N times the unit
price (section 10). What this still cannot do is a pack **price** different from N x unit, or a unit cost finer than a
centavo; both need a frozen-ledger or frozen-checkout decision.
