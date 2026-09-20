# Stage 21 — Product CSV import and export

## Status

**Done and tested** for the API; the Import / Export controls on the Products page are built but were **not
driven in a browser** (the session available to me was signed out). **No frozen file was edited.**
`productImport` and `productExport` were already in the contract but under-specified, so this stage
forward-commits the detail below; the additive parts are recorded here and should be folded into
`openapi.yaml` and `csv-export-contract.md` at the next contract change. `scripts/validate-baselines.sh`
stayed green.

## 1. What the contract left open, and how it was settled

| Gap | Resolution |
|---|---|
| `productExport` had no column contract (api-design.md §29.3 flagged it) | Pinned: `sku, barcode, name, description, category, brand, unit_of_measure, cost, selling_price, tax_class, track_inventory, reorder_level, active` (see §2). New columns may only be appended, as for every other CSV export. |
| `ProductInput` carries `category_id` / `brand_id` (UUIDs) | The CSV uses **names** (`category`, `brand`): a spreadsheet user cannot be expected to know UUIDs, and a UUID column would make the file useless for onboarding. |
| `ProductInput` has no `active` | The CSV has one, so an export→edit→import round trip is lossless. `CATALOG_MANAGE` already covers activate/deactivate. |
| `ImportResult` has `created`, `updated`, `failed`, `errors` | Read as **upsert on SKU with partial success**: valid rows are applied, invalid rows are reported. |
| Whether an unchanged row is an update | An **additive `unchanged` counter**. Otherwise re-importing an unedited export would report "500 updated". `updated` counts only rows that actually changed. |
| No way to preview a bulk change | An **additive `?dry_run=true`**: runs everything, rolls it back, returns the same result. The UI always checks first. |
| `422` shape for a bad file | `VALIDATION_FAILED` with the message under `details.file` (no new code; the catalog registers none for this). |
| Idempotency | Not required by the contract, and unnecessary: re-running the same file leaves the same end state. |

## 2. Export

`GET /products/export` — any signed-in user, no capability (as the contract says, and as `productList` already is;
note that `cost` is therefore visible to every signed-in role, exactly as it already is in the product list).

- Every product of the actor's store, **active and inactive**, ordered by SKU, streamed from a cursor.
- UTF-8, no BOM, RFC 4180 quoting with no escape character, money as `55.00`, booleans as `true` / `false`, an
  empty cell for null.
- **Formula guard.** A text cell that starts with `=`, `+`, `-`, `@`, tab or CR is written with a leading
  apostrophe, so opening an export in Excel can never run a formula planted in a product name (anyone signed in
  can export). The import removes it again, and the pair reverses exactly for any value (including ones that
  already start with apostrophes). This is the one departure from "raw values" in the CSV format rules, and it
  only ever touches cells that a spreadsheet would execute.

## 3. Import

`POST /products/import` — `CATALOG_MANAGE`; the request body **is** the CSV; answers `202` with
`{created, updated, unchanged, failed, errors: [{row, message}]}`. `row` is the row as a spreadsheet shows it
(the heading is row 1).

- **Matching:** SKU, exact, per store. A new SKU creates; an existing one updates.
- **Columns:** only `sku` is always required. A column the file omits is left alone (so `sku,selling_price` is a
  bulk price update). A blank cell clears an optional field (`barcode`, `description`, `category`, `brand`,
  `cost`) and is an error for a required one. Blank `track_inventory`, `reorder_level` and `active` leave an
  existing product alone and default a new one (`true`, `0`, `true`). Creating a product needs
  `name, unit_of_measure, selling_price, tax_class` in the file.
- **Lenient where a spreadsheet is lossy:** money may be `55` or `55.5` (Excel drops trailing zeros) and is
  completed to two places; more than two decimals is an error, never rounded. Tax class accepts `non-vat` and
  `VAT EXEMPT`. Headings are case-insensitive; a BOM, CRLF, blank rows and empty-heading columns are tolerated.
- **Strict where a typo would be silent:** an unrecognised or repeated heading rejects the whole file (a
  misspelled `sellingprice` must not "succeed" by ignoring the prices); so does a missing `sku` column, a file
  that is not UTF-8 (Excel's plain "CSV" is Windows-1252 and would turn `Niño` into garbage), more than 5,000
  products, or more than 5 MB. A barcode that Excel turned into scientific notation (`4.8E+12`) is rejected: the
  digits are already lost.
- **Barcodes** follow the same per-store uniqueness as the product form: one already on another product (its own
  or an alternate), or earlier in the same file, is a row error. A barcode freed by an earlier row can be reused
  by a later one.
- **Categories and brands** are matched by name, case-insensitively, within the store. An unknown name is a row
  error that says to create it first; **the import never creates a category or a brand**. Names are not unique in
  the schema, so a name that matches two is reported as ambiguous rather than guessed.
- **Every problem in a row is reported together**, so a row is fixed once.
- **Atomicity:** each row is applied in its own savepoint inside one transaction; a concurrent change that takes a
  SKU or barcode mid-import fails only that row. Updates lock the product row like `productUpdate` does. A crash
  applies nothing.
- Historical `sale_items` carry their own snapshots, so an import can never change a past sale.

## 4. At the Products page

**Import CSV** and **Export CSV** beside **New product**. Import opens a slide-over: download the current catalog
(the easiest template), choose a file, and it is **checked immediately** (`dry_run`), showing what will be
created / updated / unchanged and a Row / Problem table. **Import N products** applies it; rows with problems
are skipped and listed. A file that cannot be read shows the reason (for example "The file is not UTF-8 text…").
A collapsible help lists the columns and the Excel pitfalls.

`apiFetch` now sends a body untouched when the caller supplies its own `Content-Type` (needed for `text/csv`);
existing JSON callers are unaffected.

## 5. Verification

- `ProductCsvHttpTest` (32): column order and content, store scoping, inactive included, quoting of commas,
  quotes and newlines; the formula guard and its exact reversal; an export importing straight back with nothing
  changed; create and update in one file; partial columns; new SKU without the creating columns; `active`;
  blank-cell rules; BOM / blank rows / empty headings; per-row errors with correct row numbers and good rows still
  applied; combined row problems; barcode collisions (other product, alternate, earlier row, freed barcode);
  scientific notation; category and brand matching (missing, foreign store, ambiguous, never created); store
  isolation; dry run changing nothing and matching the real run; seven unusable-file cases; the 5,000-row
  boundary both sides; capability (`403` cashier, manager allowed, `401`); and that the literal paths do not
  shadow `/products/{id}`.
- Full regression: Unit 123 + Feature 1 = 124, Database 499, Pint clean, baselines hold.
- **Not driven in a browser:** the import panel and the export button. Please try: Export CSV, edit a price in
  the file, Import CSV → choose it → check the preview → Import; then a file with a bad row and a misspelled
  heading.

## 6. Not built

Importing **alternate barcodes** (the catalog form still edits only the primary), creating categories or brands
from the file, importing opening **stock quantities** (stock has its own receipts and adjustments), semicolon-
separated or Windows-1252 files, asynchronous import beyond 5,000 rows (the contract's synchronous 202 is kept),
and an **audit trail for product changes**: product create/update are not audited today either, so a bulk import
is no different, but it makes that gap more visible and is worth a decision if catalog changes need to be
traceable (it would need an audit event type, which is a contract question). **Resolved in stage 24 (D2):** product create, update, activate/deactivate and CSV import are now audited
(`PRODUCT_CREATED`, `PRODUCT_UPDATED`, `PRODUCT_IMPORTED`).
