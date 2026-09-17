# Idempotency Contract Example (ADR-010)

## Request A — first attempt

```
POST /api/v1/sales
Idempotency-Key: 9a1b2c3d-4e5f-4061-8a1b-2c3d4e5f6071
Content-Type: application/json

{
  "items": [ { "product_id": "prod-canned-goods-1", "quantity": "3.000" } ],
  "payments": [ { "method": "CASH", "amount": "200.00" } ]
}
```

**Response: `201 Created`**, `sale.id = "sale-cash-1"`, `invoice_number =
"000218"`. The server records this request's canonical payload hash
alongside `(terminal-1, 9a1b2c3d-...)`.

## Retry of Request A — same key, same payload (e.g. the client never saw the 201 due to a network drop)

```
POST /api/v1/sales
Idempotency-Key: 9a1b2c3d-4e5f-4061-8a1b-2c3d4e5f6071
Content-Type: application/json

{
  "items": [ { "product_id": "prod-canned-goods-1", "quantity": "3.000" } ],
  "payments": [ { "method": "CASH", "amount": "200.00" } ]
}
```

**Response: `201 Created`** (or `200 OK` — implementation detail; the
important guarantee is the body), **the same `sale.id = "sale-cash-1"`,
same `invoice_number = "000218"`.** No second `sale` row is created.
The client can safely retry an arbitrary number of times.

## Same key, different payload (a client bug, or a genuinely different attempt that reused an old key by mistake)

```
POST /api/v1/sales
Idempotency-Key: 9a1b2c3d-4e5f-4061-8a1b-2c3d4e5f6071
Content-Type: application/json

{
  "items": [ { "product_id": "prod-canned-goods-1", "quantity": "5.000" } ],
  "payments": [ { "method": "CASH", "amount": "300.00" } ]
}
```

**Response: `409 Conflict`**

```json
{
  "error": {
    "code": "IDEMPOTENCY_KEY_REUSED",
    "message": "This Idempotency-Key was already used for a different request. Generate a new key for a new attempt.",
    "details": { "idempotency_key": "9a1b2c3d-4e5f-4061-8a1b-2c3d4e5f6071" },
    "request_id": "f1e2d3c4-b5a6-4978-8879-6a5b4c3d2e1f"
  }
}
```

No `sale` row is created or modified.

## Different enrolled terminal, coincidentally the same UUID

Because the uniqueness scope is `(terminal_id, idempotency_key)` with
`terminal_id` resolved from the *authenticated terminal credential* (never
from the request), a second terminal presenting the literal same UUID
string lands in a completely separate namespace — it is treated as an
entirely independent request, not a near-miss, and proceeds to create its
own `sale` normally.
