# Stage 37 — Dashboard rebuild, POS X-reading, and an unsaved-cart guard

## Status

**Done and tested**, frontend only. No API, contract, migration, PHP, or frozen file changed —
`shiftXReadingCreate` has been a real, working backend operation since Stage 9; this stage only wires
existing endpoints (`daily-sales-summary`, `voids`/`refunds` pending counts, `low-stock`, `x-readings`)
into screens that had no UI for them yet.

## 1. What changed

**Dashboard rebuilt as a real landing page** (`Dashboard.jsx`), replacing the placeholder "your
name/email/capabilities" card from Stage 7 pass 1. Now built on `AdminLayout` like every other admin
screen, with three capability-gated blocks: **Today so far** (transaction count, gross sales, discounts,
grand total from `daily-sales-summary`, `REPORT_VIEW` only), **Needs attention** (pending void/refund
approval count, low-stock product count), and **Jump to** (shortcuts filtered to capabilities the actor
actually holds). A cashier holding neither `REPORT_VIEW` nor `STOCK_ADJUST` sees neither metrics block,
only a plain note pointing them at the till — never an empty grid. Nothing is computed client-side beyond
adding the two pending-approval counts together; every figure is read from an endpoint the actor's own
capabilities already permit. `AdminLayout` gained a `requiredCapability === null` case (any authenticated
user may open this page) and a `pos`-icon nav entry ("POS / Till") so a cashier who followed "Sales
history" out of the till has a way back that isn't the browser's back button — deliberately never
"active" (`matches: () => false`), since it's a mode switch out of the back office, not a section of it.

**X-reading, taken from the till itself** (`XReadingPanel.jsx`, new; wired into `Pos.jsx`'s Shift tab
via `ShiftPanel`'s new `children` slot). `shiftXReadingCreate` already existed in the frozen contract and
already had a controller (Stage 9) — there was simply no screen that called it. Taking a reading writes a
new record every time (no `Idempotency-Key` in this operation's contract; two readings a second apart are
two legitimate readings, not a retry), so the button just disables itself while one is in flight. Every
cash-deriving figure — and only the CASH row of the payment breakdown — is already blanked by
`XReadingResource` for an actor without `REPORT_VIEW` (a cashier must not be able to count their own
drawer against a number the same screen just handed them); the panel renders those as a labelled
"withheld" state, never a bare zero and never an empty cell that reads as a bug.

**Leave-the-till guard.** `sitemap.md`'s POS navigation rule (validated in Stage 7) says leaving `/pos`
must never silently discard an in-progress cart — but the cart lives in `Pos.jsx`'s own component state,
so unmounting *is* what destroys it, and the confirmation has to happen before the route changes, not
after. `PosHeader`'s two exit links ("Sales history", "Dashboard") now call an optional `onLeave(to)`
instead of navigating directly whenever the cart holds at least one item; `Pos.jsx` intercepts it, shows
the existing `ConfirmDialog` component (reused from the catalog screens, not a new one), and only calls
`useNavigate()`'s `navigate(to)` once the cashier confirms. An empty cart still navigates immediately, no
dialog — the guard exists for something to lose, not for every click.

**Demo-seeder store consolidation** (`DemoDataSeeder.php`). The demo cashier/terminal/products used to
land in a second, separate "Demo Sari-Sari Store" instead of the one `AdminUserSeeder` creates. Since
`ComposeAuthoritativeContext` requires `user.store_id == terminal.store_id`, that cashier could never
actually use that terminal, and none of the demo products ever appeared on any screen the admin could
reach — a genuinely unusable seed, not a deliberate second-store scenario (V1 is single-store; the app
does not support a second one regardless). Fixed to resolve the same store `AdminUserSeeder` resolves
(`env('TINDAFLOW_INITIAL_STORE_NAME', ...)`), and the cashier's `firstOrCreate` now keys on
`[store_id, email]` together, matching the `users` table's own unique index, instead of `email` alone
(which would have silently matched some other store's cashier and skipped creating this one).

## 2. Verification

Full regression: Unit+Feature 161, Database 668, Pint clean, frontend builds. No new backend behavior was
introduced, so no new PHPUnit coverage was needed — `shiftXReadingCreate`'s authorization, cash-withholding,
and response shape were already covered by Stage 9's own tests, and this stage sends the same request
that coverage already exercises.

## 3. Not built

A dedicated screen listing every X-reading a shift has taken with its full figures (the panel shows the
latest one in full and earlier ones as a timestamp-only list, matching `shiftXReadingList`'s own existing
scope). A dashboard date-range picker ("Today so far" is always the current calendar day; a different
range is available through the Daily Sales Summary report it links to).
