# Operation Inventory — TindaFlow POS API (Stage 4)

## Status
DRAFT — Stage 4, remediation pass 5 (2026-09-16). This table is the review
surface before implementation and must match
[openapi.yaml](openapi.yaml) exactly — regenerate/diff this table whenever
the spec changes rather than letting the two drift. Validated against the
spec: **86 operations across 75 path templates** (78/67 through pass 2;
**+8 operations/+8 paths in pass 3**, the first endpoint additions across
any pass; unchanged in pass 4/5) — 53 GET, 30 POST, 3 PATCH, 0 PUT, 0
DELETE anywhere in the entire API. **Pass 1 closed the capability-naming
gap** (every "(catalog mgmt)"/"Admin only"/"or a dedicated capability"
placeholder below is now a concrete `Capability` enum value,
cross-checked programmatically against `x-capability` extensions in
openapi.yaml). **Pass 2 revised the Invoice reprint operation's response
schema** (`InvoiceReprintResult` replaces `InvoiceDetail`) and tightened
`invoice_number`'s pattern. **Pass 3 added the Void/Refund approval
workflow** (`voidList`/`voidGet`/`voidApprove`/`voidReject` and the
Refund equivalents — see the Sales section below) to close a real gap:
`SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` had no operation until then.
**Pass 4 verified approval-is-execution** against frozen text (confirmed,
no redesign) and corrected a wrong journal-entry claim plus a missing
`RefundSummary` field set. **Pass 5 corrects a real defect pass 4 itself
carried forward**: a failed approve attempt no longer auto-transitions
the request to `REJECTED` — it now leaves the request `REQUESTED`,
unchanged, with a stable `409`/`422`, for either Void or Refund. Void's
and Refund's stale-fiscal-day rules are now made explicit as distinct
(Void: permanent; Refund: no such rule at all). No new operations.

**Legend:** *Term. enrolled?* = requires a resolved terminal credential
(ADR-011), not just a user session — this is `false` for auth/reporting/
admin operations that make sense from any authenticated browser, and
`true` for anything attributing an action to a physical terminal.
*Idemp.?* = `Idempotency-Key` header required (ADR-010).

## Auth (3)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| POST | /auth/login | authLogin | none | false | — | no | email+password | 200 UserSummary | `AUTHENTICATION_REQUIRED` (bad creds), 429 |
| POST | /auth/logout | authLogout | session | false | — | no | — | 204 | 401 |
| GET | /auth/me | authMe | session | false | — | no | — | 200 UserSummary | 401 |

## Terminal (6)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| POST | /terminal-enrollment-tokens | terminalCreateEnrollmentToken | session | false | `TERMINAL_MANAGE` | no | terminal_id | 201 Token | 403, 404 |
| POST | /terminal/enroll | terminalEnroll | session (admin on this workstation) | false (this is what establishes it) | `TERMINAL_MANAGE` | no | token | 200 TerminalSummary | 409 (token used/expired/revoked) |
| GET | /terminal/current | terminalCurrent | session | true | — | no | — | 200 TerminalSummary | `TERMINAL_NOT_ENROLLED` |
| GET | /terminals | terminalList | session | false | `TERMINAL_MANAGE` | no | — | 200 paginated | 403 |
| GET | /terminals/{terminalId} | terminalGet | session | false | `TERMINAL_MANAGE` | no | — | 200 TerminalSummary | 403, 404 |
| POST | /terminals/{terminalId}/revoke | terminalRevoke | session | false | `TERMINAL_MANAGE` | no | — | 200 TerminalSummary | 403, 404 |

## Catalog (13)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /products | productList | session | false | — | no | query filters | 200 paginated | 401 |
| POST | /products | productCreate | session | false | `CATALOG_MANAGE` | no | ProductInput | 201 Product | 422 |
| GET | /products/{productId} | productGet | session | false | — | no | — | 200 Product | 404 |
| PATCH | /products/{productId} | productUpdate | session | false | `CATALOG_MANAGE` | no | ProductInput | 200 Product | 422, 404 |
| POST | /products/{productId}/activate | productActivate | session | false | `CATALOG_MANAGE` | no | — | 200 Product | 404 |
| POST | /products/{productId}/deactivate | productDeactivate | session | false | `CATALOG_MANAGE` | no | — | 200 Product | 404 |
| GET | /products/by-barcode/{barcode} | productLookupByBarcode | session | true | — | no | — | 200 Product | `BARCODE_NOT_FOUND` |
| GET | /categories | categoryList | session | false | — | no | — | 200 paginated | 401 |
| POST | /categories | categoryCreate | session | false | `CATALOG_MANAGE` | no | name | 201 Category | 422 |
| GET | /brands | brandList | session | false | — | no | — | 200 paginated | 401 |
| POST | /brands | brandCreate | session | false | `CATALOG_MANAGE` | no | name | 201 Brand | 422 |
| POST | /products/import | productImport | session | false | `CATALOG_MANAGE` | no | CSV | 202 ImportResult | 422 |
| GET | /products/export | productExport | session | false | — | no | — | 200 CSV | 401 |

## Inventory (5)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /inventory/stock | inventoryStockList | session | false | — | no | — | 200 paginated | 401 |
| GET | /inventory/low-stock | inventoryLowStockList | session | false | — | no | — | 200 paginated | 401 |
| GET | /inventory/movements | inventoryMovementList | session | false | — | no | filters | 200 paginated | 401 |
| POST | /inventory/receipts | inventoryReceiptCreate | session | true | `STOCK_ADJUST` | **yes** | product/qty/type | 201 StockMovement | 404, 422 |
| POST | /inventory/adjustments | inventoryAdjustmentCreate | session | true | `STOCK_ADJUST` | **yes** | product/qty/type/reason | 201 StockMovement | `STOCK_ADJUSTMENT_REASON_REQUIRED`, 404 |

## Sales (13, was 5 — +8 in remediation pass 3) — the core financial surface

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /sales | saleList | session | false | — | no | filters | 200 paginated | 401 |
| **POST** | **/sales** | **saleFinalize** | session | **true** | — (any cashier with an open shift) | **yes** | SaleFinalizeRequest | 201 SaleDetail | `SHIFT_REQUIRED`, `FISCAL_DAY_NOT_OPEN`, `PRODUCT_NOT_FOUND`, `PRODUCT_INACTIVE`, `INSUFFICIENT_PAYMENT`, `INVOICE_SERIES_EXHAUSTED`, `IDEMPOTENCY_KEY_REUSED` |
| GET | /sales/{saleId} | saleGet | session | false | — | no | — | 200 SaleDetail | 404 |
| POST | /sales/{saleId}/void | saleVoid | session | **true** | `SALE_VOID` (+`SALE_VOID_APPROVE`) | **yes** | reason | 201 VoidResult | `SALE_NOT_VOIDABLE`, `SALE_ALREADY_VOIDED`, `FISCAL_DAY_CLOSED`, `REFUND_NOT_ALLOWED` (already refunded), 403 |
| POST | /sales/{saleId}/refunds | saleRefund | session | **true** | `SALE_REFUND` (+`SALE_REFUND_APPROVE`) | **yes** | RefundRequest | 201 RefundResult | `REFUND_EXCEEDS_REMAINING_QUANTITY`, `REFUND_EXCEEDS_REMAINING_AMOUNT`, `REFUND_SETTLEMENT_MISMATCH`, `REFUND_NOT_ALLOWED` (sale voided), `FISCAL_DAY_CLOSED` (processing day), `SHIFT_NOT_OPEN` (processing shift) |
| GET | /voids | **voidList** | session | false | — | no | filters (`status`, `sale_id`) | 200 paginated VoidSummary | 401 |
| GET | /voids/{voidId} | **voidGet** | session | false | — | no | — | 200 VoidResult | 404 |
| POST | /voids/{voidId}/approve | **voidApprove** | session | **true** | `SALE_VOID_APPROVE` | **yes** | — | 200 VoidResult (VOIDED only) | `VOID_NOT_PENDING_APPROVAL`, `SALE_NOT_VOIDABLE`, `FISCAL_DAY_CLOSED`, `SHIFT_NOT_OPEN` (all leave the void REQUESTED, unchanged — pass 5), 403, 404 |
| POST | /voids/{voidId}/reject | **voidReject** | session | false | `SALE_VOID_APPROVE` | **yes** | reason | 200 VoidResult (REJECTED) | `VOID_NOT_PENDING_APPROVAL`, 403, 404, 422 |
| GET | /refunds | **refundList** | session | false | — | no | filters (`status`, `sale_id`) | 200 paginated RefundSummary | 401 |
| GET | /refunds/{refundId} | **refundGet** | session | false | — | no | — | 200 RefundResult | 404 |
| POST | /refunds/{refundId}/approve | **refundApprove** | session | **true** | `SALE_REFUND_APPROVE` | **yes** | — | 200 RefundResult (COMPLETED) | `REFUND_NOT_PENDING_APPROVAL`, `FISCAL_DAY_CLOSED`, `SHIFT_NOT_OPEN`, `REFUND_EXCEEDS_REMAINING_QUANTITY`/`_AMOUNT` (re-validated at execution time, pass 4), 403, 404 |
| POST | /refunds/{refundId}/reject | **refundReject** | session | false | `SALE_REFUND_APPROVE` | **yes** | reason | 200 RefundResult (REJECTED) | `REFUND_NOT_PENDING_APPROVAL`, 403, 404, 422 |

**Why these eight exist (remediation pass 3 — see api-design.md §12.1 for
the full reasoning):** `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` were
canonical capabilities with no operation that used them — `saleVoid`/
`saleRefund` only ever executed immediately (requester already held the
approve capability) or left the row `REQUESTED` with no path back out
for a different, later actor. `sale.status` stays `COMPLETED` while a
void/refund sits `REQUESTED` (state-machines.md §1–§3), so `voidList`/
`refundList` are the *only* way to discover one pending approval — this
backs the Stage 1 sitemap's `/back-office/void-refund/approvals` and
`/back-office/void-refund/:id` screens, which existed since Stage 1 with
no backing endpoint until now. `voidApprove`/`refundApprove` perform the
frozen `APPROVED → VOIDED`/`APPROVED → COMPLETED` execution atomically
within the same call (Stage 2's own diagrams show no separately-observed
resting state between them). ~~`voidApprove` may legitimately return
`status: REJECTED` in its `200` body~~ **corrected in pass 5 — see
below: this was a real defect, not a documented, deliberate design.**

**Pass 4 verification — approval IS execution, confirmed against frozen
text (api-design.md §12.1):** before tagging the baseline, the owner
asked whether `REQUESTED → APPROVED` means the fiscal adjustment is
already finalized, or whether `APPROVED` is only authorization for a
later, separate execution step. `state-machines.md` §2/§3 show `APPROVED`
immediately followed by system execution in the same diagram, and
`invariants.md` #24 names exactly three void audit events for four
lifecycle moments — there is no `SALE_VOID_APPROVED` event. **Confirmed:
`APPROVED` is not an independently-effective resting state; `voidApprove`/
`refundApprove` are fiscal execution commands**, exactly as pass 3
already implemented (no redesign needed). Pass 4 corrects two
description-accuracy issues this check surfaced:
- `voidReject`/`refundReject` (and `voidApprove`'s system-auto-`REJECTED`
  path) were described as writing `electronic_journal_entry` — wrong,
  since a rejection is never a fiscally-journalable event
  (domain-model.md §2.10). They now correctly write `audit_event` only.
- `RefundSummary` was missing `requested_by`/`approved_by`/
  `requested_at`/`resolved_at` (`VoidSummary` always had them) — added,
  additive-only, since `refundReject` needed to state explicitly who
  rejected a request and when.

Four stale-request scenarios were also added in pass 4 (later revised to
five in pass 5).

**Pass 5 correction — failed approval is not automatic rejection
(api-design.md §12.1):** on further owner review, pass 4's own citation
was found to be the actual defect. `state-machines.md` §2 explicitly
said a failed execution-time eligibility recheck moves the void to
`REJECTED` ("system-rejected") — that conflates *"this attempt didn't
succeed"* with *"an authorized decision has been made that this request
should never proceed."* **`state-machines.md` §2 has been corrected**
(Stage 2 amendment, disclosed, committed separately): a failed recheck
now leaves the void `REQUESTED`, unchanged, and `voidApprove` returns a
stable `409` instead of a `200` with `status: REJECTED`. This applies to
every execution-time failure mode for both Void and Refund — the
previously-described "asymmetry" between them is retired; they now share
the same failure-handling shape.

**Void's and Refund's stale-fiscal-day rules, made explicit and
distinct:**
- **Void** requires the *originating* sale's own `fiscal_day` to still
  be `OPEN`. Once that specific day Z-closes, this is a **permanent**
  failure (`409 SALE_NOT_VOIDABLE`) — every future approve attempt fails
  identically, resolvable only by an explicit reject. The void is never
  attributed to a later, currently-open fiscal_day merely to make it
  executable.
- **Refund** has no such condition (`state-machines.md` §3: *"Unlike
  Void, Refund has no eligibility condition requiring the original
  sale's fiscal day to still be open"*) — it may legitimately execute
  under a much later fiscal_day, permanently referencing the original
  Sale/SaleItems while its processing context is entirely current.
- Both separately re-check the *executing terminal's own* open
  shift/fiscal_day, which is **transient** for both — opening a shift
  and retrying (with the same Idempotency-Key) resolves it.

Five stale-request scenarios now cover this
([examples/approval-stale-context-scenarios.json](examples/approval-stale-context-scenarios.json)):
(A) no current open shift — transient, `409`, `REQUESTED` unchanged; (B)
Void's originating fiscal_day closed — permanent, `409
SALE_NOT_VOIDABLE`, `REQUESTED` unchanged; (C) Refund against an old sale
approved in a later valid fiscal_day — succeeds; (D) a concurrent refund
consumes the quantity first — `422`, `REQUESTED` unchanged; (E) a refund
completes before a pending void's approval — permanent, `409
SALE_NOT_VOIDABLE`, `REQUESTED` unchanged. None of the five shows an
automatic rejection.

**Idempotency after a failed approval attempt (pass 5):** a `409`/`422`
response commits no fiscal mutation, so no idempotency_record is durably
resolved for that attempt — the same Idempotency-Key may be reused for a
fresh attempt once the underlying condition is corrected (e.g. a shift
is opened). This is a Stage 4 clarification of how ADR-010 applies to
these operations, not a change to ADR-010 itself.

## Invoices (2)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /invoices/{invoiceId} | invoiceGet | session | false | — | no | — | 200 InvoiceDetail | `INVOICE_NOT_FOUND` |
| POST | /invoices/{invoiceId}/reprints | invoiceReprint | session | true | — (any store staff; V1 does not gate reprint behind a dedicated capability, per the brief's "cashier: invoice reprint when permitted" baseline) | **yes** | — | 201 InvoiceReprintResult | 404 |

**Reprint semantics (see api-design.md §17 for the full BIR crosswalk;
resource shape revised in remediation pass 2):** the response is
`InvoiceReprintResult` — a wrapper (`reprint_event_id`, `invoice`,
`is_reprint: true`, `reprinted_at`, `requested_by`) around the unchanged
`InvoiceDetail` — **not** `InvoiceDetail` itself, because an Invoice may
be reprinted any number of times and `is_reprint`/`reprinted_at` describe
one reprint occurrence, never a property of the immutable Invoice
resource. The wrapped `invoice` preserves the original `invoice_number`,
`sale_id`, and full `invoice_snapshot_json`-derived content, identical to
what `GET /invoices/{invoiceId}` returns; the operation never allocates a
number, never alters `Sale` totals or `invoice_series` state, never
touches a Z-Reading counter.

## Shifts (8)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /shifts/current | shiftCurrentGet | session | true | — | no | — | 200 Shift | `SHIFT_NOT_OPEN` |
| POST | /shifts/open | shiftOpen | session | true | — | **yes** | opening_cash | 201 ShiftOpenResult | `SHIFT_ALREADY_OPEN` |
| GET | /shifts/{shiftId} | shiftGet | session | false | — | no | — | 200 Shift | 404 — **not implemented this pass** (pure historical browsing; see FiscalDay note below) |
| GET | /shifts | shiftList | session | false | — | no | filters | 200 paginated | 401 — **not implemented this pass** |
| POST | /shifts/{shiftId}/cash-movements | shiftCashMovementCreate | session | true | `CASH_OUT` (for CASH_OUT above threshold) | **yes** | type/amount/reason | 201 CashMovement | `SHIFT_NOT_FOUND`, `SHIFT_NOT_OPEN`, 403 |
| GET | /shifts/{shiftId}/x-readings | shiftXReadingList | session | false | — | no | — | 200 array | `SHIFT_NOT_FOUND` |
| POST | /shifts/{shiftId}/x-readings | shiftXReadingCreate | session | true | — | no | — | 201 XReading | `SHIFT_NOT_FOUND` (no 409 exists in the frozen contract for this operation, so a CLOSED shift is still readable, never rejected) |
| POST | /shifts/{shiftId}/close | shiftClose | session | true | — | **yes** | declared_cash | 200 ShiftCloseResult | `SHIFT_NOT_FOUND`, `SHIFT_ALREADY_CLOSED` |

**Implementation status (2026-09-19, shift-close module)**: `shiftClose`/`shiftCashMovementCreate`/`shiftXReadingList`/`shiftXReadingCreate` are now implemented, closing the lifecycle `shiftOpen`/`shiftCurrentGet` started. `shiftGet`/`shiftList` remain unimplemented — pure historical browsing with no bearing on the operational open→close flow, deferred to a future Reports module. `SHIFT_NOT_FOUND` (404, sixth baseline reconstruction) replaces the generic `NotFound` these operations' already-frozen responses previously carried.

### X-Reading BIR crosswalk (RMO 24-2023 — Cashier's Accountability / End-of-Shift Report)

| RMO 24-2023 concept | Schema field | Notes |
|---|---|---|
| Shift | `XReading.shift_id` | — |
| Cashier | `XReading.cashier_id` | — |
| Terminal | `XReading.terminal_id` | — |
| Reading timestamp / as-of point | `XReading.generated_at`, `from_at`/`to_at` | Range covered, not just an instant |
| Accountability totals (opening/expected cash) | `XReadingTotalsSnapshot.opening_cash`, `.expected_cash`, `.variance` (closing reading only — null on an interim pull) | — |
| Sales/payment figures | `XReadingTotalsSnapshot.cash_sales`, `.non_cash_sales`, `.payment_breakdown` | Keyed by `PaymentMethod` |
| Adjustment figures | `XReadingTotalsSnapshot.refunds_total`, `.cash_in_total`, `.cash_out_total` | — |
| Multiple readings per shift ("as required by management") | `Shift 1--0..N XReading` (Stage 2 invariant #43, erd.md `SHIFT ||--o{ X_READING`) | Interim pulls never close/reset the shift; `is_closing_reading` distinguishes the automatic end-of-shift one |
| Not reused for Z-Reading semantics | `XReadingTotalsSnapshot` is a distinct schema from `ZReadingTotalsSnapshot` — no accumulated-grand-total, tax-category, or counter fields on X | Confirmed by schema inspection, not just convention |

## FiscalDay (5)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /fiscal-days/current | fiscalDayCurrentGet | session | true | — | no | — | 200 FiscalDay | `FISCAL_DAY_NOT_OPEN` — **not implemented this pass** |
| GET | /fiscal-days | fiscalDayList | session | false | — | no | filters | 200 paginated | 401 — **not implemented this pass** |
| GET | /fiscal-days/{fiscalDayId} | fiscalDayGet | session | false | — | no | — | 200 FiscalDay | 404 — **not implemented this pass** |
| GET | /fiscal-days/{fiscalDayId}/z-reading | fiscalDayZReadingGet | session | false | — | no | — | 200 ZReading | `FISCAL_DAY_NOT_FOUND` (non-enumerating: doesn't exist, wrong terminal, or not closed yet) |
| POST | /fiscal-days/{fiscalDayId}/close | fiscalDayClose | session | true | `FISCAL_DAY_CLOSE` | **yes** | — | 200 FiscalDayCloseResult | `FISCAL_DAY_NOT_FOUND`, `FISCAL_DAY_HAS_OPEN_SHIFT`, `FISCAL_DAY_CLOSED` |

**Implementation status (2026-09-19, shift-close module)**: `fiscalDayClose`/`fiscalDayZReadingGet` are now implemented. `fiscalDayCurrentGet`/`fiscalDayList`/`fiscalDayGet` remain unimplemented this pass -- deliberately: `fiscalDayCurrentGet`'s own frozen `404` is descriptively labeled `FISCAL_DAY_NOT_OPEN`, the same already-frozen-and-mislabeled shape as `shiftCurrentGet` before it (see the `NO_CURRENT_SHIFT` entry in error-catalog.md), so implementing it now would require minting yet another new code via the reconstruction procedure; the frontend instead obtains the `fiscal_day_id` it needs to close from the shift lifecycle it already holds in state (`shiftOpen`/`shiftCurrentGet` both return it inline), so this is not required to reach a working close flow. `fiscalDayGet`/`fiscalDayList` are pure historical browsing, deferred to a future Reports module alongside `shiftGet`/`shiftList`. `FISCAL_DAY_NOT_FOUND` (404, sixth baseline reconstruction) backs the two operations built this pass.

### Z-Reading BIR crosswalk (RMO 24-2023 — End-of-Day Report)

| RMO 24-2023 concept | Schema field | Notes |
|---|---|---|
| FiscalDay / business date | `ZReading.fiscal_day_id`, `.business_date` | — |
| Terminal | `ZReading.terminal_id` | — |
| Generation timestamp | `ZReading.generated_at`, `from_at`/`to_at` | — |
| Previous accumulated grand total sales | `ZReadingTotalsSnapshot.accumulated_grand_total_sales_before` | `AccumulatedMoney` schema, not `Money` — see §12 below |
| Present/current accumulated grand total sales | `ZReadingTotalsSnapshot.accumulated_grand_total_sales_after` | `= before + gross_sales`, exactly |
| VATable Sales | `ZReadingTotalsSnapshot.taxable_sales` | — |
| VAT-Exempt Sales | `ZReadingTotalsSnapshot.vat_exempt_sales` | — |
| Zero-Rated Sales | `ZReadingTotalsSnapshot.zero_rated_sales` | — |
| VAT Amount | `ZReadingTotalsSnapshot.vat_amount` | — |
| Non-VAT Sales | `ZReadingTotalsSnapshot.non_vat_sales` | Added 2026-09-17 (Stage 6A NON_VAT gap, domain-model.md 4a) — not an RMO 24-2023 line item by that name; TindaFlow reports it here to keep Non-VAT sales distinct from VAT-Exempt Sales rather than folding one into the other. `0.00` for a VAT-registered store's fiscal day; equals `gross_sales` for a NON_VAT-registered store's fiscal day. |
| Payment-mode breakdown | `ZReadingTotalsSnapshot.payment_breakdown` | Keyed by `PaymentMethod` |
| Z Counter | `ZReadingTotalsSnapshot.z_counter` | Per-terminal running sequence, ever-incrementing |
| Reset Counter | `ZReadingTotalsSnapshot.reset_counter` | Present for accreditation-demonstration completeness; expected to remain `0` — TindaFlow has no user-facing reset function |
| Sales-adjustment summaries (voids/refunds) | `ZReadingTotalsSnapshot.void_total`, `.refund_total` | Voided sales excluded from `gross_sales`, reported separately for audit transparency |
| Exactly one per FiscalDay | `FiscalDay 1--0..1 ZReading` (Stage 2 invariant #42, erd.md `FISCAL_DAY ||--o| Z_READING`) | Created atomically with the `OPEN→CLOSED` transition |

## Reports (15) — all `REPORT_VIEW`

| Method | Path | operationId |
|---|---|---|
| GET | /reports/daily-sales-summary | reportDailySalesSummary |
| GET | /reports/sales-by-date-range | reportSalesByDateRange |
| GET | /reports/sales-by-product | reportSalesByProduct |
| GET | /reports/sales-by-category | reportSalesByCategory |
| GET | /reports/sales-by-cashier | reportSalesByCashier |
| GET | /reports/sales-by-payment-method | reportSalesByPaymentMethod |
| GET | /reports/tax-breakdown | reportTaxBreakdown |
| GET | /reports/voids | reportVoids |
| GET | /reports/refunds | reportRefunds |
| GET | /reports/discounts | reportDiscounts |
| GET | /reports/inventory-on-hand | reportInventoryOnHand |
| GET | /reports/low-stock | reportLowStock |
| GET | /reports/inventory-movement | reportInventoryMovement |
| GET | /reports/shifts | reportShifts |
| GET | /reports/cash-variance | reportCashVariance |

All 15: session auth, no terminal enrollment required, `REPORT_VIEW`
capability, no idempotency (read-only), `Accept: application/json|text/csv`
content negotiation, success = 200 `ReportResult` or CSV, errors = 401/403
only (no domain-specific failure modes — reports never fail on business
state, only on auth). **CSV column layout for each report (and for the
Electronic Journal's CSV export) is pinned exactly in
[csv-export-contract.md](csv-export-contract.md)** — closed during this
remediation pass rather than left to Stage 5/6 to invent independently.

## StoreSettings (4)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /store-settings | storeSettingsGet | session | false | — | no | — | 200 StoreSettings | 401 |
| PATCH | /store-settings | storeSettingsUpdate | session | false | `STORE_SETTINGS_MANAGE` | no | StoreSettingsInput | 200 StoreSettings | 403, 422 |
| GET | /tax-registrations | taxRegistrationList | session | false | — | no | — | 200 array | 401 |
| POST | /tax-registrations | taxRegistrationCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | type+effective_from | 201 TaxRegistration | 403, 422 |

## FiscalInstallation (3)

**HTTP shape (see api-design.md §16 for the full crosswalk):** `Accreditation`
and `PermitToUse` are distinct nested objects on `FiscalInstallation`
(`accreditation: {...}`, `permit_to_use: {...}`), never collapsed into one
generic `compliance_status` field — matching ADR-009's independent-
lifecycles decision. The database realization (two column-pairs vs. two
child tables) remains a Stage 5 decision this HTTP shape does not
prejudge.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /fiscal-installations | fiscalInstallationList | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | — | 200 array | 403 |
| POST | /fiscal-installations | fiscalInstallationCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | FiscalInstallationInput (`accreditation`/`permit_to_use` objects) | 201 FiscalInstallation | 403, 422 |
| POST | /fiscal-installations/{fiscalInstallationId}/terminals | fiscalInstallationAssignTerminal | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | terminal_id+effective_from? | 201 TerminalFiscalInstallation | 403, 404, 422, 409 |

`fiscalInstallationGet` (declared above the table this section documents) remains **unimplemented** — the store-setup pass (2026-09-18) implemented `List`/`Create`/`AssignTerminal` only; the admin screens built against this operation-inventory entry read full `FiscalInstallation` objects from the list response inline, so a separate get-by-id fetch was never needed. Implementing it later would complete a gap in an *already-frozen* `404` response (`fiscalInstallationGet`'s response already existed before this pass), which this project's frozen-corpus discipline treats as a Stage 4 content change requiring the full reconstruction procedure — distinct from `AssignTerminal` above, a wholly new operation forward-committed without one.

## InvoiceSeries (3) — store-setup pass (2026-09-18), no prior draft at any stage

The table `InvoiceSeriesAllocator::allocateForFiscalInstallation` reads at checkout time. `invoice_series_one_active_per_installation` allows at most one ACTIVE row per fiscal installation — `Create` conflicts (409) rather than auto-superseding; `Close` is the only way to retire the current one first.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /invoice-series | invoiceSeriesList | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | fiscal_installation_id? (query) | 200 paginated array | 403 |
| POST | /invoice-series | invoiceSeriesCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | InvoiceSeriesInput | 201 InvoiceSeries | 403, 409 `INVOICE_SERIES_ALREADY_ACTIVE`, 422 |
| POST | /invoice-series/{invoiceSeriesId}/close | invoiceSeriesClose | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | — | 200 InvoiceSeries | 403, 404, 409 `INVOICE_SERIES_ALREADY_CLOSED` |

## InventoryLocation (3) — store-setup pass (2026-09-18), tagged `Inventory` (existing tag), no prior draft at any stage

The table `InventoryLocationResolver::resolveDefaultForStore` reads at checkout time. A store's first location is always made the default (regardless of the submitted `is_default`), so a fresh store never needs a second call just to pass the checkout-time check. `Update`'s `is_default` may only be submitted as `true` — a location is promoted (transactionally demoting the current default), never explicitly demoted on its own, so this endpoint can never leave a store with zero defaults.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /inventory-locations | inventoryLocationList | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | — | 200 paginated array | 403 |
| POST | /inventory-locations | inventoryLocationCreate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | InventoryLocationInput | 201 InventoryLocation | 403, 422 |
| PATCH | /inventory-locations/{inventoryLocationId} | inventoryLocationUpdate | session | false | `FISCAL_CONFIGURATION_MANAGE` | no | name?+is_default? (true only) | 200 InventoryLocation | 403, 404, 422 |

## StoreSetup (1) — store-setup pass (2026-09-18), no prior draft at any stage

Read-only, non-authoritative aggregate across the four checkout-time resolver prerequisites (`FiscalInstallationResolver`, `InvoiceSeriesAllocator`, `InventoryLocationResolver`, `TaxRegistrationResolver`) — independent re-implementations of each resolver's own lookup, not a call into `app/Services/Checkout/*` (frozen under the Stage 6C addendum). Terminal-scoped like `shiftCurrentGet`, deliberately **no x-capability** — a cashier on an enrolled terminal needs to see the blocked state too, not just an admin.

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /store-setup/readiness | storeSetupReadinessGet | session+terminal | true | — | no | — | 200 StoreSetupReadiness | 401, 403 |

## Audit (2) — read-only

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /audit-events | auditEventList | session | false | `AUDIT_VIEW` | no | filters | 200 paginated | 403 |
| GET | /audit-events/{auditEventId} | auditEventGet | session | false | `AUDIT_VIEW` | no | — | 200 AuditEvent | 403, 404 |

## ElectronicJournal (2) — read-only

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /electronic-journal-entries | journalEntryList | session | false | `JOURNAL_VIEW` | no | filters | 200 paginated / CSV | 403 |
| GET | /electronic-journal-entries/{journalEntryId} | journalEntryGet | session | false | `JOURNAL_VIEW` | no | — | 200 entry | 403, 404 |

## Users (5)

| Method | Path | operationId | Auth | Term. enrolled? | Capability | Idemp.? | Request | Success | Key errors |
|---|---|---|---|---|---|---|---|---|---|
| GET | /users | userList | session | false | `USER_MANAGE` | no | filters | 200 paginated | 403 |
| POST | /users | userCreate | session | false | `USER_MANAGE` | no | UserInput | 201 UserSummary | 403, 422 |
| GET | /users/{userId} | userGet | session | false | `USER_MANAGE` | no | — | 200 UserSummary | 403, 404 |
| PATCH | /users/{userId} | userUpdate | session | false | `USER_MANAGE` | no | UserInput | 200 UserSummary | 403, 422, 404 |
| POST | /users/{userId}/deactivate | userDeactivate | session | false | `USER_MANAGE` | no | — | 200 UserSummary | 403, 404 |

## Idempotency inventory (cross-checked programmatically against openapi.yaml — item 10)

Exactly **14** operations require `Idempotency-Key` (10 through pass 2;
**+4 in pass 3**) — regenerated directly from the spec (not counted by
hand):

| operationId | Method | Path |
|---|---|---|
| `saleFinalize` | POST | /sales |
| `saleVoid` | POST | /sales/{saleId}/void |
| `saleRefund` | POST | /sales/{saleId}/refunds |
| `invoiceReprint` | POST | /invoices/{invoiceId}/reprints |
| `shiftOpen` | POST | /shifts/open |
| `shiftCashMovementCreate` | POST | /shifts/{shiftId}/cash-movements |
| `shiftClose` | POST | /shifts/{shiftId}/close |
| `inventoryReceiptCreate` | POST | /inventory/receipts |
| `inventoryAdjustmentCreate` | POST | /inventory/adjustments |
| `fiscalDayClose` | POST | /fiscal-days/{fiscalDayId}/close |
| `voidApprove` | POST | /voids/{voidId}/approve |
| `voidReject` | POST | /voids/{voidId}/reject |
| `refundApprove` | POST | /refunds/{refundId}/approve |
| `refundReject` | POST | /refunds/{refundId}/reject |

For each, all four required properties hold (verified against openapi.yaml
directly, not asserted narratively): `Idempotency-Key` required (parameter
present via `$ref` to the shared `IdempotencyKeyHeader` component,
`required: true`); terminal-scoped identity (ADR-010 — `terminal_id`
resolved server-side, never a request field on any of the 14); request-
hash conflict semantics (`409`/`IDEMPOTENCY_KEY_REUSED` documented
identically for all 14 via the shared `Conflict` response component);
original-result replay semantics (same behavior description in each
operation, inherited from ADR-010's single canonical definition rather
than restated ad hoc per operation). **`invoiceReprint` is confirmed
idempotent** — included in the 10 from the original draft, not added by
this remediation; its idempotency prevents a network retry from creating
a duplicate `INVOICE_REPRINTED` audit/journal event. Concretely, a replay
with the same `Idempotency-Key` returns the **same** `reprint_event_id`
(not a newly-minted one) — the original `InvoiceReprintResult`, byte-for-
byte, per ADR-010's original-result-replay guarantee. **The four pass-3
additions need it for the same reason**: a network-dropped
approve/reject response must never risk a second execution (a duplicate
`SALE_VOIDED`/refund completion) or a duplicate rejection audit event on
retry.

## Enum reconciliation table

Every enum exposed by the API is checked against its Stage 2 source —
none is invented or renamed for API convenience.

| API enum (`components/schemas`) | Domain source | Values |
|---|---|---|
| `SaleStatus` | Stage 2 `sale.status` (domain-model.md §2.7) | `COMPLETED`, `VOIDED`, `PARTIALLY_REFUNDED`, `REFUNDED` — no `DRAFT`, matching Stage 2's explicit removal |
| `RefundDisposition` | Stage 2 `refund_item.disposition` (§2.9) | `RETURN_TO_STOCK`, `DAMAGED`, `EXPIRED`, `DISPOSED` |
| `StockMovementType` | Stage 2 `stock_movement.movement_type` (§2.4) | `OPENING_STOCK`, `PURCHASE_RECEIPT`, `SALE`, `SALE_RETURN`, `STOCK_ADJUSTMENT_IN`, `STOCK_ADJUSTMENT_OUT`, `DAMAGE`, `EXPIRED`, `TRANSFER_IN`, `TRANSFER_OUT` |
| `PaymentMethod` | Stage 2 `payment.method` / `refund_settlement.payment_method` (§2.7/§2.9) | `CASH`, `GCASH`, `MAYA`, `CARD`, `OTHER` |
| `TaxClassification` | Stage 2 `product.tax_class` / `sale_item.tax_classification_snapshot` (§2.3/§2.7) | `VATABLE`, `VAT_EXEMPT`, `ZERO_RATED`, `NON_VAT` |
| `TaxRegistrationType` | Stage 2 `tax_registration.registration_type` (§2.1) | `VAT`, `NON_VAT` |
| `TerminalStatus` | Stage 2 `terminal.status` (§2.1) | `ACTIVE`, `INACTIVE`, `DECOMMISSIONED` |
| `UserRole` | Stage 2 `user.role` (§2.2) | `ADMIN`, `MANAGER`, `CASHIER` |
| `Capability` | Stage 2 capability catalog (§2.2) — full 17-value catalog, including 6 additions closed in pass 1 and **synced back into `domain-model.md` §2.2 itself in pass 2**, so this enum and its domain source are now identical, not just cross-referenced | `SALE_VOID`, `SALE_VOID_APPROVE`, `SALE_REFUND`, `SALE_REFUND_APPROVE`, `PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, `STOCK_ADJUST`, `CASH_OUT`, `REPORT_VIEW`, `STORE_SETTINGS_MANAGE`, `FISCAL_DAY_CLOSE`, `CATALOG_MANAGE`, `AUDIT_VIEW`, `JOURNAL_VIEW`, `USER_MANAGE`, `TERMINAL_MANAGE`, `FISCAL_CONFIGURATION_MANAGE` |
| `Shift.status` / `FiscalDay.status` | Stage 2 (§2.5) | `OPEN`, `CLOSED` |
| `Void.status` (inline enum) | Stage 2 `void.status` (§2.8) | `REQUESTED`, `APPROVED`, `REJECTED`, `VOIDED` — reused identically on `voidList`'s new `status` query filter (pass 3) |
| `Refund.status` (inline enum) | Stage 2 `refund.status` (§2.9) | `REQUESTED`, `APPROVED`, `REJECTED`, `COMPLETED` — reused identically on `refundList`'s new `status` query filter (pass 3) |
| `FiscalInstallationInput.deployment_model` (inline enum) | Stage 2/ADR-009 `fiscal_installation.deployment_model` | `STANDALONE`, `SERVER_CONNECTED` |
| Cash movement `type` (inline enum) | Stage 2 `cash_movement.type` (§2.5) | `CASH_IN`, `CASH_OUT` |

No enum in the contract introduces a value the domain doesn't already
define, and no domain enum value is renamed for API readability.

See [api-design.md](api-design.md) §4 for the fixed V1 role→capability
mapping (which roles get which of the 17 capabilities above) — kept there,
not duplicated here, to avoid the two documents drifting apart.

## Capability-to-operation coverage (both directions, new pass 3)

Checking `x-capability ⊆ Capability enum` alone (as prior passes did)
only proves the contract invents no unknown capability — it says nothing
about whether every canonical capability is actually reachable. Pass 3
ran the reverse check too:

| Capability | Operation(s) | 
|---|---|
| `SALE_VOID` | `saleVoid` |
| `SALE_VOID_APPROVE` | `voidApprove`, `voidReject` — **new pass 3, closing the exact gap found on review** |
| `SALE_REFUND` | `saleRefund` |
| `SALE_REFUND_APPROVE` | `refundApprove`, `refundReject` — **new pass 3** |
| `PRICE_OVERRIDE` | *(none directly)* — conditional field inside `saleFinalize`'s `SaleItemInput.override_reason`, pre-existing |
| `DISCOUNT_OVERRIDE` | *(none directly)* — same field, same endpoint, pre-existing |
| `STOCK_ADJUST` | `inventoryReceiptCreate`, `inventoryAdjustmentCreate` |
| `CASH_OUT` | *(none directly)* — conditional inside `shiftCashMovementCreate` ("above a configurable threshold"), pre-existing |
| `REPORT_VIEW` | all 15 report operations |
| `STORE_SETTINGS_MANAGE` | `storeSettingsUpdate` |
| `FISCAL_DAY_CLOSE` | `fiscalDayClose` |
| `CATALOG_MANAGE` | 7 catalog-mutation operations |
| `AUDIT_VIEW` | `auditEventList`, `auditEventGet` |
| `JOURNAL_VIEW` | `journalEntryList`, `journalEntryGet` |
| `USER_MANAGE` | 5 user-management operations |
| `TERMINAL_MANAGE` | 5 terminal-management operations |
| `FISCAL_CONFIGURATION_MANAGE` | 4 fiscal-configuration operations |

Three capabilities show "(none directly)" for a documented reason, not an
oversight — see api-design.md §4 for why a capability that only gates
*some* request shapes on a shared endpoint cannot be that endpoint's
single `x-capability` value. `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE`
were the only capabilities that had **no** operation and **no** documented
reason — that was the real gap this pass closes.
