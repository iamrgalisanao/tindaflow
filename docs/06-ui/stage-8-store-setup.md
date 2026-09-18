# Stage 8 — Store Setup & Fiscal Configuration (backend + admin screens)

## Status

**Done and verified end-to-end.** Backend admin CRUD for the four
prerequisites `CheckoutService`'s resolvers require, plus the admin
screens to drive them, plus a non-authoritative readiness pre-check
wired into the POS screen so a cashier sees a clear blocked state
instead of a raw 500.

## 1. Why this was built

The Stage 7 pass 2 POS checkout screen surfaced a real gap during its
own browser verification: a freshly seeded store hit
`FiscalInstallationResolutionException::noMappingForTerminal` at
checkout, because nothing except a factory/tinker script could ever
create a `FiscalInstallation`, an `InvoiceSeries`, a default
`InventoryLocation`, a `TaxRegistration`, or the `terminal_fiscal_
installations` mapping between a terminal and its installation. This
pass closes that gap with real, tested HTTP endpoints and admin
screens.

## 2. Frozen-corpus governance decision (owner-approved, 2026-09-18)

Every operation built in this project so far (Auth, Terminal, Shift,
Catalog, Sales) already had a full or partial draft in `openapi.yaml`
from Stage 1 onward — building it meant implementing an
already-frozen-but-unbuilt contract. This pass is the first time that
wasn't true: `InvoiceSeries`, `InventoryLocation`, and
`fiscalInstallationAssignTerminal` had **no draft anywhere in the
frozen corpus**. Completing them the way every previous gap (`TERMINAL_
NOT_FOUND`, `NO_CURRENT_SHIFT`, etc.) was completed would mean
reconstructing from `stage-1-baseline` forward — the largest
reconstruction this project would have done.

Given the choice (asked directly, not assumed), the owner picked:
**forward-commit the new contract surface past `stage-6c-baseline`,
without moving or reconstructing any of the 8 existing baseline tags.**
`FiscalInstallation`'s and `TaxRegistration`'s existing frozen
list/create operations were implemented as-is (zero new codes needed —
every one of their responses already referenced a generic, already-
registered error). `fiscalInstallationGet` was deliberately **left
unimplemented**: completing its already-frozen-but-gapped `404`
response would be the old-style reconstruction case (like `TERMINAL_
NOT_FOUND` was), out of scope for this decision; the admin screens
never needed a separate get-by-id fetch since the list response already
returns full objects.

Five new `error-catalog.md` codes were added the same way — see that
file's own 2026-09-18 governance note for the full record:
`FISCAL_INSTALLATION_NOT_FOUND`, `INVOICE_SERIES_NOT_FOUND`,
`INVOICE_SERIES_ALREADY_ACTIVE`, `INVOICE_SERIES_ALREADY_CLOSED`,
`INVENTORY_LOCATION_NOT_FOUND`. `scripts/validate-baselines.sh` still
passes 14/14 after this pass — none of the 8 tags moved.

## 3. Backend surface

Capability: **`FISCAL_CONFIGURATION_MANAGE`** (existing, `ADMIN`-only)
reused for every new write operation, including `InventoryLocation`
(a stock/location concept in general, but scoped here entirely to the
checkout-time default-location prerequisite) — no new capability was
minted, avoiding a `RoleCapabilityCatalog`/`Capability` enum change.

| Entity | Operations | Notes |
|---|---|---|
| FiscalInstallation | List, Create (already-frozen), AssignTerminal (new) | AssignTerminal closes the terminal's existing current mapping and opens a new one — same "prior one closed" shape as `taxRegistrationCreate`, never a separate close step |
| TaxRegistration | List, Create (already-frozen) | Closes the prior current registration the day **before** the new one starts, never the same day — `TaxRegistrationResolver` reads inclusive-both-ends, so a shared boundary day would resolve ambiguously |
| InvoiceSeries | Create, Close (new) | Bootstraps `current_number = starting_number - 1`. At most one ACTIVE per installation — Create conflicts (409) rather than auto-superseding; retiring a fiscally-significant sequence is never implicit |
| InventoryLocation | Create, Update (new) | A store's first location is always the default automatically. `is_default` may only be submitted as `true` (promote, transactionally demoting the prior default) — never explicitly `false`, so the endpoint can never leave a store with zero defaults |
| StoreSetup readiness | Get (new, read-only) | Terminal-scoped like `shiftCurrentGet`, no capability gate — a cashier needs to see the blocked state too. Independent re-implementation of each resolver's own lookup query, never a call into `app/Services/Checkout/*` (frozen under the Stage 6C addendum) |

All four resolver prerequisites' underlying tables already existed from
Stage 5 — this pass added zero migrations, only the HTTP/service layer.

## 4. Frontend

- `resources/js/pages/admin/AdminLayout.jsx` + four CRUD pages
  (`FiscalInstallations`, `InvoiceSeriesPage`, `InventoryLocations`,
  `TaxRegistrations`) + `StoreSetupOverview.jsx`, under `/admin/store-
  setup*`, gated on `FISCAL_CONFIGURATION_MANAGE`.
- **Visual direction**: a dedicated dark theme (slate surfaces, emerald
  accent, monospace for codes/counts) scoped to this admin section only
  — POS/login/dashboard keep their existing light theme. This follows
  the Stitch design-reference project the user pointed this pass at
  (`https://stitch.withgoogle.com/projects/13465419248509126107`,
  "TindaFlow Store Setup & Fiscal Configuration Module"). Its *visual
  language* was reused; its *invented fields* (cryptographic key
  rotation with manager-PIN authorization, a hardcoded Philippine RDO
  dropdown, "SEQUENCE IMMUTABILITY PROTOCOL," pallet-bay counts,
  auto-push-to-registers toggles) were not — none of those exist in
  this application's real domain model, and building against them
  would have violated this project's standing rule against inventing
  unsupported business rules. Every field on every admin screen is
  grounded in the real `fiscal_installations`/`invoice_series`/
  `inventory_locations`/`tax_registrations` schema (Stage 5).
- `StoreSetupOverview` derives its checklist client-side from the four
  list endpoints rather than calling the terminal-scoped readiness
  endpoint — an admin browsing this page may have no terminal enrolled
  on this browser at all.
- `Pos.jsx` gained a `setup-incomplete` step: after a shift is open, it
  calls `GET /store-setup/readiness` before showing the cart, and shows
  a plain-language blocked message (matching the light POS theme, not
  the admin dark theme) naming exactly which prerequisite is missing,
  with a "Check again" retry.

## 5. Verified end-to-end in a real browser

Against the same seeded store used to verify the Stage 7 pass 2 POS
screen (already fully configured from that pass): toured all five admin
screens, created a second `FiscalInstallation` and a second
`InventoryLocation`, reassigned the enrolled terminal to the new
installation (which had no active series) and confirmed `/pos` correctly
showed the `setup-incomplete` blocked screen naming "No active invoice
series for this terminal's fiscal installation" — the same failure mode
that used to be a raw 500 — instead of erroring; reassigned the terminal
back to the configured installation and confirmed `/pos` unblocked
immediately; completed a real sale (invoice `#000004`, continuing the
existing series' sequence correctly); promoted "Back Room" to the
store's default inventory location and confirmed "Main Store" was
demoted; hit the `INVOICE_SERIES_ALREADY_ACTIVE` conflict directly by
trying to activate a second series for an installation that already had
one.

**Bug found and fixed during this verification**: `FiscalInstallation`
model's `terminals()` relation returns every terminal ever historically
mapped to it, not just the current one — `FiscalInstallationResource`
was listing a just-superseded terminal as still "current" on the OLD
installation (an admin visibility bug, not a checkout-correctness one —
`FiscalInstallationResolver` itself already correctly filters by
`effective_to`). Fixed by constraining the eager-loaded relation to
`wherePivotNull('effective_to')` in both `FiscalInstallationController::
list` and `FiscalInstallationService::create`; added a regression test
(`test_reassigned_terminal_no_longer_appears_on_the_prior_installation`).

Full regression after this pass: Unit 102 + Feature 1 + Database 256
(30 new store-setup tests, incl. the regression test above) = 359
passing, 0 failures. Pint clean.
