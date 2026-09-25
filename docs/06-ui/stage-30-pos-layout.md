# Stage 30 — POS register and payment layout, in the dark theme, from the Stitch mockups

## Status

**Built and checked in a browser against a temporary mocked API** (the pane was not signed in as a user who can enrol a
terminal, so a real-backend pass is still to do). **Frontend only:** no API, contract, migration, PHP or frozen file
changed; no test count changes (the app has no JavaScript test suite). Source of the design: Stitch project
`16980011291157482081` ("TindaFlow POS Checkout Interface"), screens "Active Register (Ready for New Sale)", "Product
Selection & Register" and "Checkout Terminal"; the dark styling then followed the screen **"TindaFlow POS - Register
(Approved Branding Revision)"** (`9e97a6d3edee4e7a84d9f745e1ee067a`), see section 3. The review that led to this was a
comparison of the running `/pos` screen with those screens; the gaps and what was done about each are in section 1.

## 1. What changed

| # | Gap found | Now |
|---|---|---|
| 1 | Cart lines showed only name, a quantity box and "Remove"; no price, no line total | Each line: name, `@ ₱75.00 / pc`, a `[-] qty [+]` stepper (the box still takes decimals for weighed goods), the line total, and a remove button |
| 2 | The total was small grey text | A 30px monospace total in an emerald-bordered box; the Charge button carries the amount |
| 3 | The product list was empty until a search was made | Category tabs and a grid of tap-to-add tiles (the product list already filtered by category, search and active); a search replaces the grid until cleared |
| 4 | Layout capped at ~900px, so most of a wide screen was blank | Two columns filling the screen (cart 5/12, products 7/12), zero page scroll at 1500x850; stacks on a phone with the products first |
| 5 | Payment was a narrow card with a dropdown and a text box | Method chips, an amount display, quick-cash chips (Exact, ₱20 to ₱1,000), a keypad, live "change due" (emerald) or "balance remaining" (amber), and a Complete button that says what is short |
| 6 | The cash drawer form sat beside the cart for the whole sale | Moved to a **Shift** tab, with the shift's opening cash and Close shift |
| 7 | The header showed only "POS" | Terminal code, cashier, "shift open since 9:12 AM", tabs Register / Payment / Shift, Sales history and Dashboard links |
| 8 | Targets of 26 to 38px | 44px and up on cart controls, 48px on inputs and chips, 64px on the keypad and the Charge and Complete buttons |
| 9 | No way to reach sales history from the till | A "Sales history" link to the existing `/admin/sales` page (not checked here for a cashier without the sales permissions) |

## 2. Two defects fixed on the way

- **A quantity edited by hand then scanned again became "21"** (`"2" + 1` as strings). The cart now adds in whole
  thousandths.
- **The total was floating-point** (`Number(price) * Number(quantity)`). It is now whole centavos (BigInt, half up),
  `pos/posMoney.js`, the same approach as `inventory/packMath.js`. It is still only a preview; the server recomputes.

## 3. Decisions and deviations (for veto)

| Decision | Why |
|---|---|
| **Dark theme**, after the owner asked for it and named the approved Register screen. | Every screen under `/admin` is already dark slate with emerald accents, and the mockups' palette is exactly Tailwind's slate and emerald scale, so the POS uses the same classes (`bg-slate-950` canvas, `bg-slate-900` panels, `border-slate-700`, `text-emerald-400` for money, amber for a shortage). **A correction:** the first version of this stage kept a light theme on the claim that the rest of the app was light; that was wrong for the admin area (only Login, Dashboard and Terminal Enrollment are light). |
| **Kept the app's fonts** (Instrument Sans and the system monospace), not the mockups' Inter and JetBrains Mono. | Adding a font would change a dependency, which needs approval; the hierarchy (mono uppercase labels, mono figures) is kept. |
| **Two columns, not three.** | The mockup's third column held only the totals and the Charge button; they sit under the cart instead, where the eye already is. Payment is its own step, as before. |
| **Payment tab is not clickable.** | Payment is where Charge leads, not somewhere to jump to; all tabs are inert while paying so a half-entered payment cannot be abandoned by accident. Back to cart is the way out. |
| **Non-cash methods are charged the exact total** (no amount box). | The screen only ever sent one payment, and a card or e-wallet payment is the total. Cash is the only method that can be over-tendered. Previously the amount box was shown for every method. |
| **Complete is disabled until the tender covers the total.** | The server would refuse it anyway; the button says "Short by ₱240.00". |
| **No ticking clock in the header.** | Nothing supplies the business timezone to the client, and a device in another zone would show a wrong time. The shift's start time is shown instead. |
| **Close shift moved from the header link to the Shift tab.** | One tap in the header was an easy mistake; the confirmation form is unchanged. |

### What the approved Register screen showed, and what was taken

Taken: the dark surfaces and 1px slate borders; a bordered tab group and terminal chip in the header; uppercase monospace
category tabs with a count on the selected one; tiles that show SKU, name, category and a large emerald price with a
circled plus; an inset "total amount due" panel with the figure in emerald; a large rounded Charge button; a scan icon in
the search box; an illustrated empty cart. **Left out because the backend has nothing behind it:** the "BIR EVAT (12%)"
and "Discount / Sc. PWD" lines, stock counts (`STK`) on tiles, the TXN number chips, the "ONLINE (14ms)" badge, RESET and
HOLD, the AUTOSCAN badge, F-key shortcuts and their footer, and the hardware status line ("scanner ready", "printer
connected"). The screen's header wording "Hardware fiscal register computes the certified receipt" and "BIR CAS
compliant" are compliance claims and are not reproduced.

## 4. Not built, on purpose

The mockups show things the backend does not have; none were built and none should be treated as requirements: the
"2PC resync" banner and ledger mask, offline mode and split-brain screens, loyalty and discount rows, hold, recall and
print-draft, UTANG/credit as a method, a fixed "12% VAT" line (the store's tax status decides that, and the server
computes it), stock counts on tiles, the printer and drawer status line, F-key shortcut footers, and any "BIR CAS
compliant" wording, which is a compliance claim the product has not earned.

## 5. Screens added to the Stitch project (2026-09-21)

The project had no screen for these states of the real app. Nine were generated with the project's own design system and
with instructions to use only backed features and the app's exact copy. Screen ids are under
`projects/16980011291157482081/screens/`.

| Screen (title in Stitch) | Id | Note when implementing |
|---|---|---|
| Terminal Not Enrolled | `c654003b8116444196f48d3e2711c78a` | Adds a 3-step "what happens next" the app does not show |
| Store Setup Incomplete | `353fea17aae6465abe6a52aeecd6bfc5` | The app lists only the **missing** checks; the mockup also shows the done one struck through |
| Shift Tab: Cash Drawer and Close Shift | `da9d631217cd485aab39d71da20e41e6` | Matches what was built |
| Payment (GCash) | `a843010b70184b7a8b08373ad8903736` | Matches what was built |
| Register with Scan Feedback and Invalid Quantity | `287f01a13a0541ef8f50016eb29fa881` | Matches what was built |
| Sale Could Not Be Completed (Retryable Conflict) | `69426dcbd25c43f0b35c568df7e47401` | The app shows this message in a red banner; the mockup uses amber |
| Close Shift: Count the Drawer (blind count) | `55e26ab87e144c5d9e4cded2a1d9f0d9` | The app has a plain text box, no keypad, here |
| Shift Closed (Reconciliation Result) | `d8d9424cbc1a4986bb2213fb70c0fc1f` | **Ignore** two lines Stitch added: "The variance is recorded on the shift." and the note under Close business day |
| Business Day Closed (Z-Reading Summary) | `c933cf3da43540cfa3deb3bc98239600` | The app shows no "CLOSED" badge; the figures are examples |

**These nine screens do not appear on the project's canvas** (`get_project` lists only the earlier screens plus the
approved Register); they are reachable by id through the API. Open them by id or place them on the canvas from the Stitch
screen list before relying on them.

Stage 24 decided a cashier is blind to variance only **while the shift is open** and sees their own after it closes, so
"Close Shift: Count the Drawer" shows no expected amount and "Shift Closed" does. An earlier Stitch screen, "Shift Close &
Z-Reading Reconciliation", may show the expected amount at the count; treat the new screen as the reference for that step.

## 6. Verification and what is left

After the dark restyle, a contrast audit of the register, Shift tab and receipt (text against its real, composited
background) found two failures, the SKU codes and the "A preview" notes at about 4:1; both were raised to pass 4.5:1, and
the payment step's keypad keys were made 56px so it fits an 850px-tall screen without scrolling. The pages Login, Dashboard
and Terminal Enrollment are still light and now look out of place next to the dark POS and admin; restyling them is a
separate small change.

Against a mocked API (deleted afterwards): the grid, category filter, search and back, the scan-then-search path, decimal
and invalid quantities (Charge blocked with a message), line totals and the total agreeing to the centavo, the payment
step's keypad, quick cash, change and shortage readouts, the exact-total non-cash path, the sale request body, New sale,
the Shift tab's cash-drawer request, Close shift and back, and no horizontal overflow at 375px.

**2026-09-25 — real-backend walkthrough done.** Enrolled a browser against `MAIN-01`, opened the existing shift, added a
product tile twice (the stepper reads `2`, not a concatenated `21`), moved to Payment, used the `₱50` quick-cash chip
against a ₱30.00 total (Change due read ₱20.00, matching the server), and completed the sale: the receipt showed
invoice `000001`, grand total, tendered and change all agreeing with the server's response. One defect found in the dev
fixture data along the way, not in this stage's code: seeded products for the admin's own store carried `tax_class`
`VATABLE` against a `NON_VAT`-registered store, which `FinancialCalculator`/`InvalidTaxConfigurationException` correctly
rejected with a 500 — the guard worked as designed; the fixture data was wrong, fixed directly in the dev database, no
application code changed.
