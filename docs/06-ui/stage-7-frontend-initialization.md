# Stage 7 — Frontend: Initialization and Scope

## Status

**Pass 1: SPA scaffold + real, working login + authenticated shell.** **Pass 2 (below, §7): a real, working POS checkout screen**, unblocked once the Shift/FiscalDay backend gap called out in Pass 1 §5 was filled. Back-office CRUD screens (Products, Inventory, Reports, Users, Store Settings) remain blocked on backend controllers that still don't exist, disclosed below rather than built against a guess. This mirrors Stage 6C's own "domain layer VERIFIED, HTTP layer BLOCKED" discipline: build exactly what the frozen contract *and* the current backend both support, disclose the rest.

## 1. Evidence reviewed

- `docs/06-ui/sitemap.md` (Stage 1, APPROVED but explicitly "proposed for validation in Stage 7... subject to change once the API contract and domain model are finalized") — proposes a single React SPA with POS mode and Back-office mode, gated by role/context, not two separate apps.
- `docs/03-architecture/architecture.md` — confirms React as the established frontend technology (not decided here; already assumed throughout Stage 2/3/4 as "the React component," "React computes a preview only," client-side calculations are explicitly non-authoritative).
- `docs/05-api/api-design.md` §"Session/CSRF" — same-origin, Laravel session-cookie auth, double-submit `X-XSRF-TOKEN` header sourced from the `XSRF-TOKEN` cookie. This is already implemented and tested (A1) via `GET /` in the `web` middleware group.
- `docs/05-api/openapi.yaml` — direct check of which operations exist as frozen contract vs. which have a real Laravel implementation today (`php artisan route:list`).
- `package.json`/`vite.config.js` — confirmed stock Laravel+Tailwind scaffold only; no React, router, or state library installed yet; `resources/views/welcome.blade.php` is the stock starter page (references a `login` named route that doesn't exist in this app — harmless no-op, not our auth system).

## 2. What the backend actually supports today (not a guess)

`php artisan route:list --path=api/v1` shows exactly: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /terminal-enrollment-tokens`, `POST /terminal/enroll`, `GET /terminal/current`, `GET /terminals`, `GET /terminals/{id}`, `POST /terminals/{id}/revoke`, `POST /sales`. Nothing else. In particular:

- **No `shiftOpen`/`shiftClose`/`fiscalDayClose` controller exists**, even though all of `openapi.yaml`'s Shift/FiscalDay operations are fully specified in the frozen contract. `CheckoutService`'s own precondition — an `OPEN` shift and `OPEN` fiscal_day row must already exist — currently can only be satisfied by a factory/seeder, never by a real HTTP action. **A fresh store cannot open a shift through the UI or API today.** This means a genuinely working, real-data POS checkout screen cannot be built yet — it would have nothing to check out against.
- **No Products/Inventory/Reports/Users/Store-settings controllers exist.** `sitemap.md`'s entire `/back-office/*` tree (except terminal management, which A3 already built) has no backend to call.
- **Terminal management (A3) and the human auth/session flow (A1) are the only two areas with both a frozen contract and a real, tested Laravel implementation** that a UI can be built against honestly today.

## 3. Decision (made directly — routine technology choices, no owner ruling required)

- **React** (already assumed throughout Stages 2–4), served **same-origin** from Laravel's existing `web` group — no separate Node server, no CORS, matching `api-design.md`'s already-frozen same-origin/session-cookie model exactly. `GET /` (already CSRF-tested in A1) becomes the SPA's single HTML entry point.
- **React Router** for client-side routing — the standard, minimal choice for a client-rendered SPA; no meta-framework (Next.js) needed since there is no server-rendering requirement and the API is already a separate, frozen REST contract.
- **No Inertia.js.** Inertia's controller-returns-page-props model would duplicate/conflict with the already-frozen, contract-first REST API (`openapi.yaml`) this project deliberately built instead. The frontend consumes that REST API directly via `fetch`.
- **No additional state-management library** (Redux/Zustand/etc.) for this pass — React's built-in `useState`/`Context` are sufficient for "who is logged in" and nothing else exists yet to justify more.
- **Plain JavaScript + JSX**, not TypeScript — no TypeScript tooling exists in `package.json`, and introducing a new toolchain dimension is out of scope for a first pass; matches the project's general minimalism (smallest reasonable choice, not the most feature-rich one).
- **A thin `apiFetch` wrapper** (not a full HTTP client library) reads the `XSRF-TOKEN` cookie and attaches it as `X-XSRF-TOKEN` on every mutating request, exactly matching the already-tested Laravel double-submit convention — no new client-side auth mechanism invented.

## 4. Pass 1 deliverables

- Vite + React plugin wired into the existing `vite.config.js`.
- `resources/js/app.jsx` — SPA entry, mounts into a new `resources/views/app.blade.php` shell (replaces `welcome.blade.php` as the root view).
- `GET /{any}` catch-all route (after the `api/v1` group) serving the same shell, so client-side routes survive a direct load/refresh.
- Routes: `/login` (real, calls `POST /auth/login`, surfaces `VALIDATION_FAILED`/`AUTHENTICATION_REQUIRED`/`RATE_LIMITED` distinctly) and `/` (authenticated landing — calls `GET /auth/me` on load; shows the user's name/role/capabilities and a logout button; redirects to `/login` if unauthenticated). No POS/back-office screens yet.
- Verified by hand in the browser against the real dev server and real backend (not merely unit-tested), per this project's own standing instruction for frontend work.

## 5. Explicitly deferred, not silently dropped

- **POS checkout screen** — blocked on Shift/FiscalDay backend endpoints (`shiftOpen` at minimum). Building the screen without them would mean either faking the precondition or shipping a screen that can never complete a real transaction.
- **Back-office CRUD screens** (Products, Inventory, Reports, Users, Store Settings) — blocked on their respective backend controllers, none of which exist yet.
- **`sitemap.md`'s two open questions** (dashboard vs. report-as-dashboard; POS-mode lookup reachability) remain open — deferred to whichever pass actually builds those screens.

## 6. Next steps (not started here)

In roughly dependency order: Shift/FiscalDay backend endpoints (unblocks a real POS checkout flow) → POS checkout screen → terminal-enrollment back-office screen (A3 already has a full backend) → Products/Inventory/Reports/Users backend + screens. Each remains its own explicitly-instructed pass, matching this project's standing practice.

## 7. Pass 2 — POS checkout screen (real, verified working)

Unblocked by the Shift/FiscalDay backend pass (`shiftOpen`/`shiftCurrentGet`) that followed Pass 1. Two gaps had to be closed first, neither anticipated in Pass 1:

- **No UI for A3's terminal-enrollment backend.** A3 built the full enrollment flow (issue token, redeem token, current-terminal lookup) but nothing could call it from a browser. Added `pages/TerminalEnroll.jsx` (TERMINAL_MANAGE only): lists the store's terminals, generates a one-time enrollment token, and enrolls the current browser with it (either the token just generated or one pasted from another admin session).
- **No Catalog backend at all.** The POS cart needs to browse/search products; nothing under `openapi.yaml`'s Catalog tag had a Laravel implementation. Added the smallest slice that unblocks the cart screen — `GET /products` (`ProductController::list` + `ProductResource`) — store-scoped, session-only (no terminal credential, no capability gate, per `operation-inventory.md`'s classification of `productList`), with name/sku search, an `active` filter, and pagination matching `TerminalController`'s existing shape. `productCreate`/`productGet`/`productUpdate`/etc. remain unbuilt — same smallest-slice discipline used for every other phase this session.

`pages/Pos.jsx` is a single route (`/pos`) with internal step state (`loading | not-enrolled | open-shift | cart | checkout | receipt`) rather than the separate `/pos/checkout` route `sitemap.md` proposes — a deliberate implementation-simplicity deviation; `sitemap.md` already documents its own IA as "proposed... subject to change." It detects shift status via `GET /shifts/current` (403 `TERMINAL_NOT_ENROLLED` → prompts enrollment; 404 `NO_CURRENT_SHIFT` → shows an open-shift form), and every total shown before the receipt step is an explicitly labeled client-side **preview only** — `CheckoutService` always recomputes authoritatively server-side, matching `architecture.md`'s existing non-authoritative-preview convention used elsewhere.

**Verified end-to-end in a real browser** against a real seeded store and PostgreSQL backend (not just code review): login → enroll a terminal → open a shift (₱1000 opening cash) → search and add a product → checkout with an exact-tendered CASH payment → receipt showing a real `transaction_number`, `invoice_number` (`000001`), `grand_total`, `amount_tendered`, `change`, and line items; "New sale" resets the cart without re-prompting for a shift (shift stays open); a second sale in the same shift correctly allocates the next sequential invoice number (`000002`); a third sale with an over-tendered amount (₱100 against a ₱55 total) correctly returns ₱45 change.

**Disclosed, not fixed** (same "setup defect" pattern A6 established): a genuinely fresh store cannot check out at all until an admin has configured `FiscalInstallation`, `InvoiceSeries`, a `terminal_fiscal_installations` mapping, `InventoryLocation`, and `tax_registrations` — confirmed directly when a freshly seeded store hit `FiscalInstallationResolutionException::noMappingForTerminal` during this verification. That exception is deliberately NOT a `DomainException` (deliberately uncaught, surfaces as a genuine 500) — it is correctly refusing to paper over incomplete store setup with a stable client-facing error code, by design. No back-office UI/API exists yet to let a store self-serve this configuration; that remains Module B/C backend work, not something this pass should invent around.

Full regression after this pass: Unit 102 + Feature 1 + Database 226 (including 5 new `ProductListHttpTest` cases) = 329 tests passing, 0 failures. No frozen-corpus baseline touched — `GET /products` is new surface area added forward, not a change to any already-frozen contract row.
