# Stage 24 — The four owner decisions, researched and applied

## Status

All four open owner decisions were researched against competitors, public APIs and BIR primary sources, decided
(2026-09-20, "approve all"), and applied. **No frozen file was edited**: what the contract left open or under-specified is
forward-committed here, and the additive parts should be folded into `openapi.yaml` and `domain-model.md` at their next
contract change. `scripts/validate-baselines.sh` stayed green.

| # | Decision | Outcome |
|---|---|---|
| D1 | The missing 404 codes (`auditEventGet`, `journalEntryGet`, `fiscalDayCurrentGet`) | **Leave unbuilt**; names reserved (below) |
| D2 | Audit product changes | **Yes**: `PRODUCT_CREATED`, `PRODUCT_UPDATED`, `PRODUCT_IMPORTED` |
| D3 | Who reads cash variance | **Managers see all; a cashier sees only their own**, blind while the shift is open |
| D4 | BIR-006 and invoice snapshot v2 | **Snapshot v2 built** with nullable data-only fields; legal questions referred |

Research method: four parallel researchers used primary sources where they existed (BIR issuances on `bir-cdn.bir.gov.ph`,
lawphil, vendor help centres, API references) and labelled each claim verified or inferred. **UTAK documents almost nothing on
any of these topics, so its rows below read "not documented", not "no".**

## D1 — Not-found codes: leave the three operations unbuilt

**Evidence.** A missing single record by id is a 404 in every API read, and several (GitHub, Microsoft's guidelines, RFC 9110)
say another tenant's record should look identical to a missing one, which is what we do. Code granularity is split: Stripe,
Square and Toast use mostly one generic code; our per-resource style is the JSON:API "application-specific code" pattern and is
defensible. **No POS API found reports "no open shift" as a 404**: Square offers only a list; Shopify a list whose closing fields
are null; Lightspeed an `is_open` flag; Odoo returns `False`; ERPNext errors only on the write that needs a session.

**Decision.** `auditEventGet`, `journalEntryGet` and `fiscalDayCurrentGet` stay unbuilt: a list row already carries the whole
event, and the POS gets the fiscal-day id from `shiftOpen`, so nothing is blocked and building them costs a second governance
exception for a frozen response. **If they are ever wanted**, the names are `AUDIT_EVENT_NOT_FOUND`,
`ELECTRONIC_JOURNAL_ENTRY_NOT_FOUND` (both 404, identical for a missing and a cross-store record) and `NO_CURRENT_FISCAL_DAY`
(404, mirroring `NO_CURRENT_SHIFT`); never reuse `FISCAL_DAY_NOT_OPEN`, which is registered as a 409 and must not carry two
statuses. Revisit if a client needs a single-record read.

## D2 — Product changes are audited

**Evidence.** Lightspeed X-Series keeps a per-product history (who, when, source including spreadsheet import, field, old and
new value) and logs a CSV import as its own event with the line count. StoreHub's activity log covers staff actions for
managers (6 months) but item and price edits are not documented. Toast and Clover keep none; Square has no item-level history
(merchants have asked since 2017); Shopify and Odoo are partial or opt-in. **RMO 24-2023 §IV(5)(c)** requires the software to
generate an activity log of all actions, including edit and delete, with who, the values and the time. It never says
"product" or "price", so whether it reaches catalog edits is an interpretation: skipping the trail is a compliance gamble, and
the trail is cheap because sale lines already snapshot the price.

**Built** (`ProductAuditor`, written in the same transaction as the change, so an audit row never exists without its change
and a dry-run import leaves nothing):

- `PRODUCT_CREATED`: the product as created.
- `PRODUCT_UPDATED`: **only the fields that changed**, before and after, plus the SKU for context; an activate or deactivate is
  the `active` field; a save that changes nothing writes nothing.
- `PRODUCT_IMPORTED`: one summary per CSV import (rows, created, updated, unchanged, failed, and the file's SHA-256). Every row
  that really changed also gets its own event, whose reason names the batch id (the summary's entity id). Unchanged and failed
  rows write nothing.
- Categories and brands are **not** audited (no price, tax or stock meaning; no competitor documents it).
- Visible to `AUDIT_VIEW` (manager, admin) through the existing audit log, with plain-sentence descriptions such as
  "Product RIC-001 changed: price ₱55.00 → ₱60.00"; a cashier gets 403.

**Contract note.** `audit_events.event_type` is a plain string and the OpenAPI declares `{type: string}`, so nothing technical
changes; the three names extend the event list in `domain-model.md`, which should gain them at its next change.

## D3 — Cash variance: managers see all, a cashier sees their own, blind while open

**Evidence.** Toast, Lightspeed, Loyverse and StoreHub all put cross-employee shift and variance review behind a manager-level
permission, and none documents line staff browsing other people's variances. "Blind" counting is common: a permission in Toast
(its blind drawer also hides over/short), Loyverse (the "View shift report" right decides who sees expected cash) and Square; a
per-payment-type setting in Lightspeed (totals appear after close); the default in StoreHub. Two loss-prevention sources give
the reason: a cashier who knows the target can make the count match it. **BIR states no rule on who may view X or Z readings**;
its sample Z-reading carries a SHORT/OVER line, so variance is an expected field.

**Rule.** A user holding `REPORT_VIEW` (manager, admin) reads every shift and sale of the store. A user without it (a cashier)
reads only their own; someone else's is `SHIFT_NOT_FOUND` / `SALE_NOT_FOUND`, never 403, so it is not confirmed to exist. No
new capability requirement and no new status code were added to any operation.

**Built.**
- `shiftList`, `shiftGet` and `shiftXReadingList` are limited to the caller's own shifts unless they hold `REPORT_VIEW`
  (`total` in the paging meta does not leak the others either).
- **`saleList` and `saleGet` are limited the same way.** Without this the blind close would be theatre: a cashier could add up
  the day's cash sales and rebuild the expected figure.
- **Interim X-readings hide every cash-deriving figure from a cashier**: `expected_cash`, `variance`, `cash_sales`,
  `refunds_total`, `cash_in_total`, `cash_out_total`, and the `CASH` line of the payment breakdown. Hiding only "expected"
  would fail, because `opening_cash + cash_sales + cash_in - cash_out - refunds` is the expected figure. `opening_cash`, non-cash
  payments and the transaction count stay. The **closing reading**, produced when the cashier declares their count, and their
  own closed shift show everything, so they see the result once they have committed to a count (Lightspeed's after-close
  reveal; the permanently blind Toast model is not adopted because RMO 24-2023 makes the X-reading the cashier's
  accountability report).
- **Contract effect.** `XReadingTotalsSnapshot` fields become nullable for a cashier's interim reading (additive nullability);
  everything else is a narrowing of what a cashier can list.
- Unchanged: fiscal-day list and detail and the Z-reading (a Z-reading exists only after every shift has closed, and holds no
  expected-cash figure), and the admin Shifts and Fiscal-days pages (already `REPORT_VIEW`). A cashier's Sales page now shows
  only their own sales.

## D4 — BIR-006 and invoice snapshot v2

**The finding that changes BIR-006's footing.** The register cites RMO 24-2023 with RR 16-2018 / RR 6-2022 / RR 11-2004 as
summarised by the RMO. **RR 7-2024 §6(B)** (implementing RA 11976, effective 27 April 2024; RMC 77-2024 quotes it) is now the
controlling list of invoice content and postdates the RMO. The register itself is stage-1-frozen (`docs/01-research/`) and was
not edited; this section is its addendum, to be absorbed at its next baseline change.

| Element | On the printed invoice | Source | In our snapshot |
|---|---|---|---|
| Registered name, address; "VAT Reg TIN" / "Non-VAT Reg TIN"; branch code | yes | RR 7-2024 §6(B) | v1 |
| The word "Invoice", serial number (6+ digits) | yes | RR 7-2024 | v1 (already printed) |
| Date, buyer fields (address/TIN required only for a VAT buyer at ₱1,000+) | yes / conditional | RR 7-2024 | v1 |
| Quantity, unit cost, description, total, VAT shown separately | yes | RA 11976, RR 7-2024 | v1 |
| MIN, machine serial number, "REPRINT" on reprints | yes for POS | RR 7-2024, RMO | **v2** (REPRINT already) |
| PTU number (and date) | yes for POS | RR 7-2024 (secondary transcription), RMO | **v2** |
| Supplier name/address/TIN, accreditation no. and dates | RMO says yes; still required after RR 7-2024 is **unresolved** | RMO 24-2023 | **v2** (accreditation only) |
| SC/PWD/NAAC/Solo Parent: ID number, name, discount and VAT-exemption breakdown, signature | conditional | RR 7-2024 | **v2 renderer**; checkout capture not built |
| Payment method, tendered, change | not in either list | none | **v2** (customary) |
| "THIS DOCUMENT IS NOT VALID FOR CLAIM OF INPUT TAX." | supplementary documents only, not principal invoices | RR 7-2024 | not printed, correctly |

**PTU.** "PTU is no longer required for POS" is **not supported by primary text**: RR 7-2024 requires the PTU number on POS
invoices and keeps the PTU when a machine's wording changes to "Invoice"; RMC 72-2025 says POS PTUs do not expire (software
accreditation does). The rumour likely stems from RMC 5-2021, which concerned CAS only. This confirms the register's BIR-007
position. **E-invoicing:** RR 11-2025 and RR 26-2025 set 31 December 2026 for e-commerce, large taxpayers and CAS users; POS
users await a separate regulation with no date, micro taxpayers are exempt, and no e-invoicing field is required on a POS
printout now.

**Built (schema version 2).** Checkout now writes `schema_version` 2: every v1 key plus four, all nullable:

- `registration`: MIN, machine serial number, software version, PTU number and date, accreditation number, date and validity,
  **copied from the terminal's fiscal installation as it stood at the moment of sale** (effective-dated, the permit and
  accreditation in force that day), so a later change never rewrites an issued invoice; null for anything not on file.
- `payments`, `amount_tendered`, `change`.
- `discount_beneficiary` (type, name, ID number, TIN): the renderer supports it; checkout does not yet capture it.

`InvoiceSnapshotV2Renderer` prints these through four hooks in a shared base layout, **only what the snapshot recorded and
never a placeholder**: MIN and S/N in the header, payments under the total, PTU and accreditation before the store's own
footer. The v1 renderer is the same base with every hook empty, so **every invoice already issued prints byte-for-byte as it
did**, and a v2 snapshot with none of the new data renders exactly like v1 (both proven by tests).

**Referred to the business or legal owner** (nothing was guessed):
- PTU versus ATG classification, and whether a cloud POS counts as "POS" or as "subscription e-invoicing".
- Whether the RMO's supplier and accreditation footer is still mandatory after RR 7-2024 (the model records no supplier
  name, address or TIN, so that part cannot print yet).
- The "EXEMPT" wording for percentage-tax and 8% sellers, and the non-VAT legend generally.
- Senior Citizen / PWD / Solo Parent capture at checkout and its computation (the 20% discount and VAT exemption): a checkout
  contract change and legal computation rules, so only the renderer side is ready.
- TindaFlow's own BIR accreditation, and any e-invoicing fields.

## Verification

- New tests: `ProductAuditHttpTest` (10), `CashVisibilityHttpTest` (8), `InvoiceSnapshotV2RendererTest` (11),
  `InvoiceSnapshotV2CheckoutTest` (7). Updated: two assertions that pinned `schema_version` 1 and the shift-list test, which
  now uses a manager.
- Full regression: Unit 134 + Feature 7 = 141, Database 547, Pint clean, frontend builds, baselines hold.
- Not driven in a browser: the two audit-log descriptions (built, not viewed).

## Not built

`fiscalDayCurrentGet`, `auditEventGet`, `journalEntryGet` (D1); a per-store blind-close toggle (add if a store asks); supplier
block, SC/PWD capture, "EXEMPT" wording and e-invoicing fields (the wait-list above); auditing of categories and brands.
