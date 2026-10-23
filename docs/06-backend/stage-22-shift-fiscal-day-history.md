# Stage 22 — Shift and fiscal-day history

## Status

**Done and tested** for the API; the four admin pages are built but were **not driven in a browser** (the
session available to me was signed out). **No frozen file was edited and no error code was minted.**
`scripts/validate-baselines.sh` stayed green.

Built: `shiftList`, `shiftGet`, `fiscalDayList`, `fiscalDayGet`. Repaired: `shiftXReadingList` and
`fiscalDayZReadingGet` (§2). **Not built: `fiscalDayCurrentGet`** (§3).

## 1. Contract audit

| Question | Finding |
|---|---|
| Is a 404 code missing (the `auditEventGet` situation)? | **No.** `SHIFT_NOT_FOUND` and `FISCAL_DAY_NOT_FOUND` are already registered, so `shiftGet` and `fiscalDayGet` use them. Their frozen responses name only the generic `NotFound`, but choosing a registered, semantically exact code mints nothing, so no exception or reconstruction is needed. The registered wording speaks of "a different terminal than the credential presented"; for these session-only reads the boundary is the **store**, the same non-enumerating principle. The catalog wording should say so at its next change. |
| Auth | Session only, not terminal-enrolled, **no capability** (operation-inventory.md). A shift has no `store_id`; it belongs to its terminal's store, so scoping goes through the terminal. |
| Data exposure | Because the contract sets no capability, any signed-in user of the store can read every shift, including other cashiers' cash variance, exactly as `saleList` already shows every cashier's sales. I followed the contract and precedent rather than invent a per-cashier rule. The **admin pages** are shown only to holders of `REPORT_VIEW` (the capability `reportShifts` already needs), but the API itself is not gated. Tightening it would be a contract change (a new 403); flagged for the owner. **Superseded by stage 24 (D3):** a user without `REPORT_VIEW` now reads only their own shifts (someone else's is `SHIFT_NOT_FOUND`), with no new 403. |

Filters: `shiftList` takes `from`/`to` (the day the shift was **opened**, inclusive), `terminal_id`, `cashier_id`
per the contract, plus an **additive `fiscal_day_id`** so a fiscal day can list its own shifts (the drill-down the
detail page needs). `fiscalDayList` takes `terminal_id` and `from`/`to` on the **business date**. Both sort newest
first. An id filter that cannot match (not a UUID) gives an empty page, never a wider one; a malformed date is
ignored, as on the other lists. `openapi.yaml` is untouched and should gain `fiscal_day_id` at its next change.

## 2. Two earlier reads did not match the contract, and were repaired

While auditing I found that `shiftXReadingList` and `fiscalDayZReadingGet`, built in Stage 9, differed from the
contract and inventory (both say **session only**, `shiftXReadingList` also `SHIFT_NOT_FOUND`):

1. They required an **enrolled terminal** and returned only the enrolled terminal's own records, so a manager in
   an ordinary browser could never open a Z-reading or a shift's X-readings, which is precisely what a history
   screen needs.
2. `shiftXReadingList` returned **`200 []` for a shift that does not exist** instead of `404 SHIFT_NOT_FOUND`.

Both are now session-only and store-scoped, and an unknown or foreign shift is `SHIFT_NOT_FOUND`. The POS is
unaffected (its terminal credential is simply ignored on these routes) and the existing Stage 9 tests pass
unchanged. A Z-reading of a fiscal day that has not closed is still `FISCAL_DAY_NOT_FOUND` (the contract's own
non-enumerating outcome). The `{shiftId}`/`{fiscalDayId}` routes are now constrained to UUIDs, so `/shifts/current`
can never be captured by them and a malformed id is a plain 404 instead of a database error.

## 3. `fiscalDayCurrentGet` stays unbuilt

Its frozen `404` is labelled `FISCAL_DAY_NOT_OPEN`, but `error-catalog.md` registers that code as a **409**: the
same already-frozen-and-mislabelled shape `shiftCurrentGet` had before `NO_CURRENT_SHIFT`. Building it means
minting a new code for a frozen response, which the governance rule sends through reconstruction (or another
owner exception). It is not needed: the POS already gets the fiscal-day id from `shiftOpen`/`shiftCurrentGet`.
It **reopens the same owner question** as `auditEventGet`/`journalEntryGet`.

## 4. The pages

Under **Records** (shown to `REPORT_VIEW`): **Shifts** and **Fiscal days**, each a list with date filters and a
detail page.

- **Shifts** list: opened, closed, cashier, terminal, status, opening / expected / declared cash and the
  variance. **Variance is declared minus expected**, so it reads "Short ₱50.00" or "Over ₱50.00" (or "Balanced"),
  and stays "—" while the shift is open.
- **Shift detail**: the drawer figures, a link to its fiscal day, and every X-reading with its figures and
  payment breakdown; the last is labelled the closing reading.
- **Fiscal days** list: business date, status, terminal, opened / closed.
- **Fiscal-day detail**: the day, its shifts (with their variances, linking to each), and, once closed, the
  **Z-reading**: counters, sales by tax class, VAT, voids, refunds, accumulated sales before and after, and
  payments by method. An open day says its Z-reading appears when it is closed.

Cashiers and terminals are shown as short ids (with the full id on hover), as on the sales and audit pages:
names would need `USER_MANAGE` / `TERMINAL_MANAGE`, which a manager does not hold.

## 5. Verification

- `ShiftFiscalDayHistoryHttpTest` (17): store-scoped newest-first lists for a user with no terminal; every
  filter (terminal, cashier, fiscal day, inclusive opening dates, business dates); unmatchable filters and
  malformed dates; pagination; a closed shift's figures through the real close endpoints; unknown and foreign
  shifts and fiscal days (`SHIFT_NOT_FOUND` / `FISCAL_DAY_NOT_FOUND`); X-readings and a Z-reading read from a
  browser enrolled nowhere; no `200 []` for a missing shift; `shifts/current` not shadowed; `401` for all six.
- Full regression: Unit 123 + Feature 1 = 124, Database 516, Pint clean, baselines hold.
- **Not driven in a browser.** Please open Records > Shifts and Fiscal days, follow a closed day through to its
  Z-reading, and check a shift with a variance.

## 6. Not built

`fiscalDayCurrentGet` (§3); cash-movement history (there is no list operation, only the totals on the shift);
cashier and terminal names on these pages; a way to print or export a Z-reading; and sales-per-shift links (the
sales list has no shift filter).

## Addendum (2026-10-20, revised 2026-10-23): printing the Z-reading

The Z-reading could be read on screen but not printed. `ZReadingPrinter` renders it as an 80 mm print document (the same narrow monospace
layout as the invoice, ADR-007) from the stored `totals_snapshot`; the figures are never recalculated, and only the shop name, the till's
code and the name of whoever closed the day are looked up around it. One operation, `fiscalDayZReadingPrint` (store-scoped, `REPORT_VIEW`,
30 an hour per person), and **the server decides which printout is the original**: the first print of a reading is the plain original,
recorded in `z_reading_prints` with a `Z_READING_PRINTED` audit event; every later print is a copy marked REPRINT -- COPY and recorded as
`Z_READING_REPRINTED`.

An earlier design (2026-10-20) had a plain-read "original" and a separate recorded reprint, and left "original once" to the screens, so a
direct API call could fetch any number of unmarked originals; a security review found that, and the original is now claimed on the server.
`z_readings` is append-only (the application role cannot UPDATE it), so the claim lives in its own insert-only table whose unique
`z_reading_id` is the arbiter: two simultaneous first prints cannot both be originals. The reading's resource shows `original_printed_at`
and `original_printed_by`. The till prints the original as the day closes (the "Business day closed" screen); Records, Fiscal days offers
**Print Z-reading** until the original exists and **Print a copy** after, so an original is whichever print comes first, by whoever, recorded.

The print is not idempotent (no terminal to key it by; an administrator can print from the back office): a retry after a lost response
records one extra print and never changes a figure. No journal entry is written (the journal's event list is closed and a printout is not a
journal event), and no legal wording is printed (BIR-006 is parked).
