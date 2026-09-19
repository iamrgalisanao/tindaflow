# Stage 18 — Store settings (business details)

## Status

**Done and tested**, backend and admin screen. **No change to the frozen contract**: both operations were
already in `openapi.yaml`, neither declares a `404`, and `SETTINGS_CHANGED` is already in the frozen audit
event catalog, so there is no missing-code question. No baseline tag moved and `scripts/validate-baselines.sh`
stayed green.

## 1. Scope

| Operation | Route | Who |
|---|---|---|
| `storeSettingsGet` | `GET /store-settings` | any session, the actor's own store |
| `storeSettingsUpdate` | `PATCH /store-settings` | `STORE_SETTINGS_MANAGE` (ADMIN only; a MANAGER or CASHIER gets `403 AUTHORIZATION_DENIED`) |

The store is never a request field (a test sends another store's id and it is ignored). The two
`taxRegistration*` operations under the same tag were built in Stage 8; the read here only *shows* the
current registration.

## 2. Decisions

- **One row per store, created on the first save.** The table's `business_name`, `registered_name` and `tin`
  are NOT NULL and the dev database had no row at all, while the contract's `GET` declares no `404`. So a
  read of a store without settings returns a **blank baseline** (`business_name` = the store's own name,
  `registered_name` and `tin` empty, everything else `null`) and creates nothing. The first `PATCH` must
  supply all three identity fields (`422 VALIDATION_FAILED` with a message per missing one).
- **`PATCH` is a real partial update**: only the fields sent change. A blank optional value clears it (the
  framework turns `""` into `null`); the three identity fields can never be blanked. Lengths follow the
  columns. **No TIN format or checksum is imposed** (BIR-006 is still open); values are only checked for
  shape and are HTML-escaped wherever printed.
- **Audited, precisely**: one `SETTINGS_CHANGED` event whose `before_metadata`/`after_metadata` hold only the
  fields that actually changed; a save that changes nothing writes nothing.
- **`current_tax_registration`** is `null` for a store with no registration on record. The schema calls it
  required, but inventing a registration would be worse; such a store cannot complete a sale anyway.
- **Effect on invoices (ADR-006).** Checkout copies the seller identity into each invoice's snapshot, so a
  change reaches invoices issued afterwards and never ones already issued; a test changes the settings twice
  and shows both existing invoices render byte-for-byte as before. To make the header, footer and branch code
  fields mean something, checkout now also records **`seller_branch_code`, `invoice_header` and
  `invoice_footer`** in the snapshot and the version-1 renderer prints them when present (branch beside the
  TIN, header under the seller block, footer at the bottom, line breaks kept, all escaped). These are
  optional, additive keys of the existing version 1, so invoices issued before this simply lack them and
  print nothing extra; no restructure and no version bump was needed. `telephone` and `email` are stored but
  not printed (nothing in the contract or ADR-006 puts them on an invoice).

## 3. Admin screen

**Store Setup → Business Details** (`/admin/store-setup/business`, `STORE_SETTINGS_MANAGE`): the three
groups of fields with a hint under each, the current tax registration for reference (linked to its own page),
and a small preview of how the top of an invoice will read. Save is enabled only when something changed and
sends only the changed fields; the very first save sends everything. A banner explains, before anything is
saved, that invoices are currently printing without the store's name, address or TIN. The audit log shows
"Business details changed: …".

## 4. Verification

- `StoreSettingsHttpTest` (13): blank baseline without creating a row, no-registration store, own-store
  scoping, first save creating the row with its audit event, first save needing every identity field,
  partial updates auditing only what changed, a no-op writing no event, clearing optional fields, identity
  fields not blankable, per-field shape and length validation, the store not choosable from the request,
  ADMIN-only writes and `401`, and the invoice-immutability scenario above.
- Three renderer unit tests for branch code, header and footer (present, escaped, absent).
- Full regression: Unit 123 + Feature 1 = 124, Database 457, Pint clean, baselines hold.
- **Not driven in a browser** (the session available to me was signed out); the page compiles and its
  behaviour is covered only through the API tests.

## 5. Not built

Printing the store's telephone or email on invoices, a settings readiness check on the Store Setup overview,
snapshot version 2 with payments and fiscal-installation fields (still waiting on the BIR field review,
BIR-006; see stage 17 §4), `auditEventGet`/`journalEntryGet` (owner decision, stage 16), product CSV
import/export, barcode lookup, and the deferred shift and fiscal-day read endpoints.
