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
