# Error Catalog — TindaFlow POS API (Stage 4)

## Status
DRAFT — Stage 4. Every code below corresponds to a real frozen Stage 2/3
rule or an obvious protocol requirement — none is speculative. Envelope
shape and HTTP status mapping are defined once here and used identically
across [openapi.yaml](openapi.yaml).

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

No further codes are defined speculatively. A new code is added only when
implementation surfaces a real, distinct failure mode this list doesn't
already cover — not pre-emptively.

## Error code → HTTP status is a stable contract

Once shipped, a given `code`'s HTTP status never changes (e.g.
`FISCAL_DAY_CLOSED` is always `409`, never reassigned to `422`) — clients
may safely branch on `code` without re-deriving behavior from the status
alone.
