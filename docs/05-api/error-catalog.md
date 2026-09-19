# Error Catalog — TindaFlow POS API (Stage 4)

## Status
DRAFT — Stage 4. Every code below corresponds to a real frozen Stage 2/3
rule or an obvious protocol requirement — none is speculative. Envelope
shape and HTTP status mapping are defined once here and used identically
across [openapi.yaml](openapi.yaml).

**Governance note (2026-09-18, store-setup pass)**: the five rows dated
2026-09-18 were added as a plain forward commit past `stage-6c-baseline`,
not via the reconstruct-and-re-tag procedure used for every earlier
addition to this file. All five are motivated by wholly new operations
(`InvoiceSeries`/`InventoryLocation`/`fiscalInstallationAssignTerminal`)
that had no draft in this document at any prior stage — unlike every
previous addition, none of them complete a gap in already-frozen content,
so no existing `stage-*-baseline` tag needed to move. This is an explicit
owner decision (see `docs/PROJECT-MANIFEST.md`'s store-setup section) to
extend forward-commit governance to this file for genuinely-new surface,
while completing a gap in an *already-frozen* response (as `TERMINAL_NOT_
FOUND`/`NO_CURRENT_SHIFT` did) still requires the full reconstruction
discipline.

**Governance exception (2026-09-19, users pass)**: `USER_NOT_FOUND` was
likewise added as a plain forward commit, but — unlike the store-setup
codes — it *does* complete a gap in already-frozen responses
(`userGet`/`userUpdate`/`userDeactivate`'s `404`s), so the strict rule
above calls for reconstruction. The owner explicitly chose to forward-
commit it instead, mainly because the repository is now published and a
reconstruction would mean force-pushing `main` and the baseline tags. This
is a recorded, one-time exception, not a new default: the next code that
fills a gap in an already-frozen response reopens the question. No
`stage-*-baseline` tag moved.

## Standard error envelope

```json
{
  "error": {
    "code": "FISCAL_DAY_CLOSED",
    "message": "The current fiscal day is already closed.",
    "details": {},
    "request_id": "b3f1a2c4-..."
  }
}
```

`code` is stable and machine-readable (never changes meaning across
versions once shipped). `message` is human-readable and may change wording
freely. `details` is an optional object for field-level or contextual
data (e.g., which field failed validation). `request_id` always matches
the `X-Request-ID` the response carried, even on success — see
[api-design.md](api-design.md) §Request correlation.

## HTTP status semantics

| Status | Meaning | Used for |
|---|---|---|
| 400 | Malformed request / protocol error | Unparseable JSON, missing required header where the header itself (not its value) is absent |
| 401 | Authentication required | No/invalid session |
| 403 | Authenticated but capability denied, or terminal not authorized | Missing capability; unenrolled/revoked terminal on a terminal-scoped operation |
| 404 | Resource does not exist | Unknown ID, or a resource whose existence is itself conditional (e.g. "no open shift") |
| 409 | Current-resource-state conflict, concurrency conflict, or idempotency-key reuse with a different payload | Business-state conflicts (already voided, fiscal day closed) and the `IDEMPOTENCY_KEY_REUSED` case |
| 422 | Structurally valid request containing invalid field/domain input | Failed validation on well-formed input (e.g. negative quantity, missing reason) |
| 429 | Rate limit | Login brute-force throttling, checkout-endpoint rate cap |
| 500 | Unexpected server fault | Anything not otherwise classified |
| 503 | Required local service unavailable | Database unreachable at the moment of request, where the application can meaningfully detect and report this rather than hanging |

200-with-an-error-flag is never used — every failure is a non-2xx status
with the envelope above.

## Domain error codes

| Code | HTTP | Meaning | Frozen source |
|---|---|---|---|
| `AUTHENTICATION_REQUIRED` | 401 | No valid session | — (protocol) |
| `AUTHORIZATION_DENIED` | 403 | Authenticated, but lacks the required capability | Stage 2 invariant #52, capability catalog §2.2 |
| `TERMINAL_NOT_ENROLLED` | 403 | This browser has no terminal credential | ADR-011 |
| `TERMINAL_REVOKED` | 403 | This browser's terminal credential was revoked | ADR-011 |
| `SHIFT_REQUIRED` | 409 | Operation requires an open shift; none exists at this terminal | Stage 2 invariant #29/#35 |
| `SHIFT_ALREADY_OPEN` | 409 | This cashier or this terminal already has an open shift | Stage 2 invariants #33/#34 |
| `SHIFT_NOT_OPEN` | 409 | Referenced shift is not open (e.g. cash movement against a closed shift) | Stage 2 §2.5 |
| `SHIFT_ALREADY_CLOSED` | 409 | Attempted to close an already-closed shift | Stage 2 §2.5 state machine |
| `FISCAL_DAY_NOT_OPEN` | 409 | Operation requires an open fiscal day; none exists | Stage 2 invariant #35 |
| `FISCAL_DAY_CLOSED` | 409 | The relevant fiscal day has already closed | Stage 2 invariants #2 (void eligibility)/#69; BIR-014 |
| `FISCAL_DAY_HAS_OPEN_SHIFT` | 409 | Cannot close a fiscal day while any shift referencing it is open | Stage 2 invariant #36 |
| `SHIFT_NOT_FOUND` | 404 | Referenced shift does not exist, or exists only for a different terminal than the credential presented — indistinguishable from "does not exist," matching how every other terminal-scoped resource never confirms cross-terminal existence | Shift-close module implementation discovery — `shiftClose`/`shiftCashMovementCreate`/`shiftXReadingCreate`/`shiftXReadingList`'s `404` `NotFound` responses already referenced the generic `Error` envelope with no code registered; follows the same resource-specific-404 pattern already established by `TERMINAL_NOT_FOUND`/`PRODUCT_NOT_FOUND`/etc. |
| `FISCAL_DAY_NOT_FOUND` | 404 | Referenced fiscal day does not exist, exists only for a different terminal than the credential presented, or (for `fiscalDayZReadingGet`) exists but has not closed yet and so has no Z-Reading — one deliberately non-enumerating outcome for `fiscalDayZReadingGet`'s two distinct "not found" causes, matching the project's existing non-enumeration convention (e.g. `ENROLLMENT_TOKEN_INVALID`) | Shift-close module implementation discovery — `fiscalDayClose`/`fiscalDayZReadingGet`'s `404` `NotFound` responses already referenced the generic `Error` envelope with no code registered |
| `PRODUCT_NOT_FOUND` | 404 | Referenced product does not exist | — |
| `PRODUCT_INACTIVE` | 422 | Referenced product is deactivated and cannot be sold | Module C |
| `BARCODE_NOT_FOUND` | 404 | No product matches the scanned barcode | Module D |
| `INSUFFICIENT_PAYMENT` | 422 | Sum of payments is less than the server-computed grand total | Stage 2 invariant #8 |
| `INVALID_PAYMENT_TOTAL` | 422 | Payment total is structurally invalid (e.g. negative, zero with no credit-sale support) | Stage 2 §2.7 (no credit sales in V1) |
| `IDEMPOTENCY_KEY_REQUIRED` | 400 | Header omitted on an operation that requires it | ADR-010 |
| `IDEMPOTENCY_KEY_REUSED` | 409 | Same `(terminal, key)` presented with a different request hash | ADR-010 |
| `SALE_NOT_FOUND` | 404 | Referenced sale does not exist | — |
| `SALE_NOT_VOIDABLE` | 409 | Void eligibility conditions not met — used both at request time (`saleVoid`) and, re-checked, at execution time (`voidApprove`); at execution time this leaves the void `REQUESTED`, never auto-`REJECTED` (Stage 2 amendment pass 4) | Stage 2 §2.8 five/six-condition eligibility |
| `SALE_ALREADY_VOIDED` | 409 | A concurrent or prior void already succeeded for this sale | Stage 2 invariant #21, partial unique index |
| `VOID_NOT_FOUND` | 404 | Referenced void does not exist | — (added pass 3, `GET /voids/{voidId}`) |
| `VOID_NOT_PENDING_APPROVAL` | 409 | `POST /voids/{voidId}/approve` or `/reject` called against a void that is no longer `REQUESTED` | Stage 2 state-machines.md §2 (added pass 3) |
| `REFUND_NOT_ALLOWED` | 409 | Sale is voided (invariant #29), or otherwise not a valid refund target | Stage 2 invariants #25/#29 |
| `REFUND_EXCEEDS_REMAINING_QUANTITY` | 422 | Requested refund quantity exceeds what remains for that `sale_item` | Stage 2 invariant #27 |
| `REFUND_EXCEEDS_REMAINING_AMOUNT` | 422 | Requested refund amount exceeds `net_line_amount` less prior refunds | Stage 2 invariant #28/DISC-004 |
| `REFUND_SETTLEMENT_MISMATCH` | 422 | `SUM(settlements.amount)` does not equal the derived refund total | Stage 2 invariant #70 |
| `REFUND_NOT_FOUND` | 404 | Referenced refund does not exist | — (added pass 3, `GET /refunds/{refundId}`) |
| `REFUND_NOT_PENDING_APPROVAL` | 409 | `POST /refunds/{refundId}/approve` or `/reject` called against a refund that is no longer `REQUESTED` | Stage 2 state-machines.md §3 (added pass 3) |
| `INVOICE_NOT_FOUND` | 404 | Referenced invoice does not exist | — |
| `INVOICE_SERIES_EXHAUSTED` | 409 | `invoice_series.ending_number` reached; finalization cannot allocate a number | Stage 2 invariant #19 |
| `STOCK_ADJUSTMENT_REASON_REQUIRED` | 422 | Adjustment/damage/expired movement submitted with an empty reason | Stage 2 invariant #46 |
| `CONCURRENCY_CONFLICT` | 409 | A generic current-state race lost against another request (used only where no more specific code above applies) | architecture.md §24 |
| `RATE_LIMITED` | 429 | Request throttled (e.g. login brute-force throttling, checkout-endpoint rate cap); emitted directly by throttling middleware, not a domain exception | module-a-auth-terminal-initialization.md §14 Ruling 6; `openapi.yaml`'s `TooManyRequests` response |
| `VALIDATION_FAILED` | 422 | Request body failed structural/shape validation at the `FormRequest` boundary (a required field missing, or malformed — e.g. not a valid email) — distinct from a domain-specific 422 (`PRODUCT_INACTIVE`, `INSUFFICIENT_PAYMENT`, etc.), which is raised by business logic against otherwise well-formed input. `details` carries the field → message(s) map. Applies uniformly to every operation's `UnprocessableEntity` response; no operation has bespoke structural-validation semantics (verified across every `422` in `openapi.yaml`) | Module A A1 correction — every `authLogin`/etc. `422` already referenced this same envelope with no code registered for the generic case |
| `ENROLLMENT_TOKEN_INVALID` | 409 | `POST /terminal/enroll`'s enrollment token does not exist, has already been used, has expired, or belongs to a different store than the authenticated actor — one deliberately non-enumerating outcome for every way a one-time enrollment token can be unusable, matching the project's existing non-enumeration convention (e.g. `AUTHENTICATION_REQUIRED` never distinguishing unknown-email from wrong-password) rather than inventing a separate code per sub-case | Module A A3 discovery — `openapi.yaml`'s `terminalEnroll` `409` ("Token already used, expired, or revoked") already referenced the generic `Error` envelope with no code registered; `CONCURRENCY_CONFLICT` was checked and rejected as a reuse candidate (its own description is race-specific, and the contract describes one outcome, not three to split across codes) |
| `TERMINAL_NOT_FOUND` | 404 | Referenced terminal does not exist, or exists only in a different store than the authenticated actor's own — indistinguishable from "does not exist," matching how every other store-scoped resource never confirms cross-store existence | Module A A3 final closeout discovery — `terminalGet`/`terminalCreateEnrollmentToken`/`terminalRevoke`'s `404` `NotFound` responses already referenced the generic `Error` envelope with no code registered; follows the same resource-specific-404 pattern already established by `PRODUCT_NOT_FOUND`/`SALE_NOT_FOUND`/`VOID_NOT_FOUND`/`REFUND_NOT_FOUND`/`INVOICE_NOT_FOUND` — no generic/reusable "not found" code exists in this catalog to reuse instead |
| `NO_CURRENT_SHIFT` | 404 | `GET /shifts/current`: no shift is currently open for this terminal — a resource whose existence is itself conditional, per this document's own HTTP-status-semantics table (§"no open shift" example) | `shiftOpen`/`shiftCurrentGet` implementation discovery — `openapi.yaml`'s `shiftCurrentGet` `404` response is descriptively labeled "SHIFT_NOT_OPEN," but that name is already a distinct, frozen 409 code for a different scenario (a shift referenced by ID, found closed — e.g. a cash movement against a closed shift); reusing it here would violate this document's own "a given code's HTTP status never changes" rule. `SHIFT_REQUIRED` (409, closest semantic match) is equally frozen at a different status. No existing code fits a 404 "no shift is currently open" outcome |
| `FISCAL_INSTALLATION_NOT_FOUND` | 404 | Referenced fiscal installation does not exist, or exists only in a different store than the authenticated actor's own | Store-setup pass (2026-09-18) — motivated entirely by the new `fiscalInstallationAssignTerminal` operation, which has no prior frozen draft; follows the same resource-specific-404 pattern already established by `TERMINAL_NOT_FOUND`/`PRODUCT_NOT_FOUND`/etc. |
| `INVOICE_SERIES_NOT_FOUND` | 404 | Referenced invoice series does not exist, or exists only in a different store than the authenticated actor's own | Store-setup pass (2026-09-18) — `invoiceSeriesClose`, a wholly new operation with no prior frozen draft |
| `INVOICE_SERIES_ALREADY_ACTIVE` | 409 | `invoice_series_one_active_per_installation` already has an ACTIVE row for this fiscal installation; the existing one must be closed (`invoiceSeriesClose`) before a new one can be activated — deliberately not an implicit auto-supersede, since silently retiring a fiscally-significant numbering sequence is not this API's decision to make | Store-setup pass (2026-09-18) — `invoiceSeriesCreate`, a wholly new operation with no prior frozen draft |
| `INVOICE_SERIES_ALREADY_CLOSED` | 409 | `invoiceSeriesClose` called against a series that is already CLOSED | Store-setup pass (2026-09-18) — mirrors the existing `SHIFT_ALREADY_CLOSED` shape exactly; wholly new operation with no prior frozen draft |
| `INVENTORY_LOCATION_NOT_FOUND` | 404 | Referenced inventory location does not exist, or exists only in a different store than the authenticated actor's own | Store-setup pass (2026-09-18) — `inventoryLocationUpdate`, a wholly new operation with no prior frozen draft |
| `USER_NOT_FOUND` | 404 | Referenced user does not exist, or exists only in a different store than the authenticated actor's own | Users pass (2026-09-19) — the `404` of the new `userActivate` operation and of the already-frozen `userGet`/`userUpdate`/`userDeactivate`, whose `NotFound` responses had no code registered. **Forward-committed by explicit owner decision** (see the governance note above) rather than reconstructed |

No further codes are defined speculatively. A new code is added only when
implementation surfaces a real, distinct failure mode this list doesn't
already cover — not pre-emptively.

## Error code → HTTP status is a stable contract

Once shipped, a given `code`'s HTTP status never changes (e.g.
`FISCAL_DAY_CLOSED` is always `409`, never reassigned to `422`) — clients
may safely branch on `code` without re-deriving behavior from the status
alone.
