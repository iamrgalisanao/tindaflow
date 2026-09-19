# Stage 20 — Barcode lookup and scanning at the POS

## Status

**Done and tested** for the API; the POS scanning behaviour is built but was **not driven in a browser** (the
session available to me was signed out). **No change to the frozen contract**: `productLookupByBarcode` was
already specified and `BARCODE_NOT_FOUND` is already registered, so there is no missing-code question. No
baseline tag moved and `scripts/validate-baselines.sh` stayed green.

## 1. The operation

`GET /products/by-barcode/{barcode}` — a **cashier-scanning operation**: session **and enrolled terminal**, no
capability (operation-inventory.md lists it as terminal-enrolled, unlike the catalog reads around it). A
browser that is not an enrolled terminal gets `403 TERMINAL_NOT_ENROLLED`; a cashier can use it.

- **Matches** a product's own `barcode` or an alternate `product_barcodes` row, both unique per store, so at
  most one product can match; both are indexed, so it stays a cheap lookup. The match is **exact** (no prefix,
  no case folding: barcodes such as Code 128 can be case-sensitive).
- **Scoped to the actor's store.** The same barcode may exist in two stores (uniqueness is per store); each
  finds its own, and a barcode that exists only elsewhere is `404 BARCODE_NOT_FOUND`.
- **Scanner line breaks are ignored**: surrounding whitespace, including a trailing `\r\n`, is trimmed.
- **An inactive product is returned as it is** (`active: false`) instead of being reported as unknown. The
  contract says "saleable product data" without settling this; hiding it would send the cashier looking for a
  typo when the item has simply been switched off, so the till is told and refuses to add it (checkout would
  refuse it anyway with `PRODUCT_INACTIVE`).
- The price and tax class are read fresh each time and are a **preview only**; checkout recomputes everything.

## 2. At the POS

The search box now takes a scan. A scanner types the code and presses Enter, which submits the existing form:

- The text is tried as a barcode first (any single token of three or more characters). A hit adds one to the
  cart (or one more if it is already there), shows "Added <name>", clears the box and keeps focus there for the
  next scan. An inactive product shows "<name> is inactive and cannot be sold."
- Anything that is not a barcode — a name or SKU, or a miss, or a lookup that cannot be reached — falls through
  to the ordinary name/SKU search exactly as before, so typing "rice" still works and a network problem never
  blocks the cashier. If nothing matches, the notice says "No product found for “…”."
- The box is focused when the cart step opens.

## 3. Verification

- `BarcodeLookupHttpTest` (10): own and alternate barcodes, the full product shape, a price change read fresh,
  unknown / near-miss barcodes, exact (not prefix or case-folded) matching, scanner whitespace and `\r\n`,
  store scoping including the same barcode in two stores, an inactive product, session + terminal but no
  capability, `401`, and that the new route does not shadow fetching a product by id.
- Full regression: Unit 123 + Feature 1 = 124, Database 467, Pint clean, baselines hold.
- **Not driven in a browser**: the scan flow on the POS. Please scan (or type) a product's barcode and press
  Enter, scan it again (the quantity should go up), and try an inactive product and an unknown code.

## 4. Not built

Managing **alternate barcodes** from the catalog screen (the lookup honours them, but no operation creates them
and the product form edits only the primary barcode), matching a barcode inside the ordinary text search,
scanning on the other screens, product CSV import/export, `auditEventGet`/`journalEntryGet` (owner decision,
stage 16), and the deferred shift and fiscal-day read endpoints.
