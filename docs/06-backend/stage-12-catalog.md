# Stage 12 — Catalog management (products, categories, brands)

## Status

**Done and tested**, backend and admin screens. Like Stage 10, it needed **zero
frozen-corpus changes**: every operation was already in `openapi.yaml`, so nothing
was added to `error-catalog.md`, no baseline tag moved, and
`scripts/validate-baselines.sh` stayed 14/14.

## 1. Scope

Implemented (all already specified in `openapi.yaml`, tag *Catalog*):

| Operation | Route | Auth |
|---|---|---|
| `productList` | `GET /products` | session (already existed; now clamps `per_page` to 1..100) |
| `productGet` | `GET /products/{productId}` | session |
| `productCreate` | `POST /products` | `CATALOG_MANAGE` |
| `productUpdate` | `PATCH /products/{productId}` | `CATALOG_MANAGE` |
| `productActivate` / `productDeactivate` | `POST /products/{productId}/(de)activate` | `CATALOG_MANAGE` |
| `categoryList` / `categoryCreate` | `GET`/`POST /categories` | session / `CATALOG_MANAGE` |
| `brandList` / `brandCreate` | `GET`/`POST /brands` | session / `CATALOG_MANAGE` |

**Not implemented** (deliberately): `productLookupByBarcode` (a POS-side operation),
`productImport`, `productExport`. The contract defines no update or delete for
categories or brands, so the UI offers list and add only, and says so on screen.
`ADMIN` and `MANAGER` hold `CATALOG_MANAGE`; a `CASHIER` can read but not change.

## 2. Why no error code is new

- Product not found (or in another store) → the existing `PRODUCT_NOT_FOUND` (404).
- Everything else is the contract's generic `422 UnprocessableEntity`, which the
  catalog already maps to `VALIDATION_FAILED` with a `details` field map. A duplicate
  SKU, a duplicate barcode, a category/brand from another store, a bad tax class or a
  malformed price are all field errors of that kind, not new domain codes.

## 3. Decisions

- **Per-store uniqueness.** SKU is unique per store (unique index); barcode is unique
  per store across **both** `products.barcode` and the alternate `product_barcodes`
  rows (a partial unique index covers only the first). The form request checks both;
  the service maps a concurrent-insert `UniqueConstraintViolationException` back to the
  same `VALIDATION_FAILED` field error, so a race never becomes a 500.
- **`PATCH` follows the schema literally.** `productUpdate` reuses `ProductInput`,
  whose required fields (`sku`, `name`, `unit_of_measure`, `selling_price`, `tax_class`)
  are therefore required on update too; optional fields are applied only when present
  (`null` clears a nullable one).
- **Money** is a string with exactly two decimals (`^\d{1,10}\.\d{2}$`, matching
  `NUMERIC(12,2)`); non-negative, as the DB checks require. Cost is optional.
- **No name-uniqueness rule for categories/brands** — the contract and schema define
  none, so none is invented.
- **Updates never touch history.** `sale_items` hold immutable snapshots (Stage 2); a
  test proves editing a product leaves an existing sale line's name, SKU and price
  unchanged. Products are never deleted; retirement is `active = false`, and
  activate/deactivate are idempotent.
- `{productId}` routes are constrained to UUIDs so the future literal paths
  (`/products/import`, `/products/export`, `/products/by-barcode/...`) cannot be
  captured by them.
- Everything is store-scoped to the actor's `store_id`; another store's product is
  indistinguishable from a missing one.

## 4. A defect found and fixed along the way

`CheckoutService` fetched products with `Product::whereIn('id', …)` and **no store
scope**: a cashier at one store could complete a sale of another store's product if
they knew its id (a test reproduced a `201`). It now filters on the terminal's store,
so a foreign product is `PRODUCT_NOT_FOUND`, and a regression test guards it
(`SaleFinalizationHttpTest::test_checkout_cannot_sell_a_product_belonging_to_another_store`,
verified to fail without the fix).

## 5. Admin screens

Routes under `/admin/catalog/…`, gated by `CATALOG_MANAGE`, in the shared admin shell
(new **Catalog** sidebar section with Products / Categories / Brands; also a
Dashboard link).

- **Products** (`ProductsPage.jsx`): server-side search (name or SKU, debounced),
  category filter, All/Active/Inactive, sort, 25 per page. Table on `md+`, cards on
  phones. **New product / Edit** open a slide-over (`ProductFormPanel.jsx`) with the
  fields of `ProductInput`; client checks only shape, and server field errors (duplicate
  SKU, etc.) appear under their fields. `+ New category` / `+ New brand` create and
  select in place. Deactivate asks for confirmation (Escape closes only the dialog);
  Activate is one click.
- **Categories / Brands** (`CatalogNamesPage.jsx`): one component, two routes — add
  form, A–Z list with id fragment, pagination, empty state.
- Stitch mockups (project `13465419248509126107`): *Catalog - Product Form (slide-over)*
  (`f601506515ee…`) and *Catalog - Categories and Brands* (`1e6225876cca…`). The
  *Product List* generation timed out and was not retried; that screen was built from
  its written spec. **Ignored** from the mockups: the "Exempt under section 109" and
  "Standard 12% value added tax" tax-class captions (statutory wording nothing in the
  contract supports — the form uses neutral one-liners), the `/api/v1/back-office/…`
  path annotations and `HTTP 422` tags, and the mockups' selection checkboxes/bulk UI.
- 401 mid-session: the page offers **Sign in**, which stores the current path so
  `Login.jsx` returns there (same mechanism as the reports).

Also changed: `shortId()` in `reports/formatters.js`. Ids are time-ordered UUIDs, so
the first 8 characters are a timestamp (identical for records created close together);
the display form is now the random last 8 characters, used by the reports and the
catalog. Non-UUID values (invoice numbers, SKUs) are shown whole.

## 6. Verification

- New `CatalogHttpTest` (26 tests) plus the checkout regression test (27 new in all)
  alongside the existing product-list test: create/get/update/activate/deactivate, both capability roles,
  cross-store isolation, SKU and barcode collisions (including alternates), money and
  tax-class shapes, `per_page` clamp, categories and brands.
- Full regression: Unit 102 + Feature 1 = 103 passing, Database 329 passing
  (302 before this stage), Pint clean, `validate-baselines.sh` all invariants hold.
- Browser (desktop 1280 and phone 375): empty and duplicate-SKU submits, valid create
  with inline category/brand, edit (omitted optional fields preserved), the confirm
  dialog, deactivate → inactive list → reactivate, search/category/status filters,
  no-match and clear-filters, categories and brands add/validation, phone cards and
  the full-width panel, Escape and scroll lock. Test records were removed afterwards.
- Not exercised in a browser: a non-`CATALOG_MANAGE` user (covered by the HTTP tests),
  pagination beyond one page (only 3 products in dev), and the 401 return trip.

## 7. Still unbuilt

Product CSV import/export, barcode lookup, renaming/deleting categories and brands
(no contract operation), and Users management.
