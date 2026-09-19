# API Design — TindaFlow POS (Stage 4)

## Status
DRAFT — Stage 4, remediation pass 5 (2026-09-16), contract design only.
Implements the frozen Stage 2 domain (`stage-2-baseline`, commit
`11d594f`) and Stage 3 architecture (`stage-3-baseline`, commit
`c8a8bbe`) as an HTTP contract. **No Laravel code, migrations, or React
components exist yet.** Pass 1 touched no Stage 2/3 document. **Pass 2
touched one Stage 2 document** — a disclosed, narrow amendment to
`domain-model.md` §2.2 (capability catalog vocabulary sync) — committed
separately from this contract (`11d594f`, now `stage-2-baseline` itself).
**Pass 5 touches a second Stage 2 document** — `state-machines.md`'s
Void lifecycle notes, correcting the "failed execution-time recheck
auto-rejects" wording (see below) — also committed separately, disclosed
the same way. **Gating check performed
first in pass 1, before any other change:** Stage 2's `x_reading`
cardinality was re-verified directly against `domain-model.md` §2.5,
`invariants.md` #43, and `erd.md`'s `SHIFT ||--o{ X_READING` relationship
— all three confirm zero-or-more `XReading`s per `Shift` was already the
frozen design. **No frozen-domain contradiction exists**, so pass 1
proceeded to document that semantics explicitly (§13) and close nine
other contract-level gaps. Pass 2 made four surgical corrections found on
owner review of pass 1: invoice reprint resource semantics
(`InvoiceReprintResult`), the capability-catalog sync into Stage 2, a
tightened `invoice_number` pattern, and a first pass at verifying
`AccumulatedMoney`'s capacity.

**Pass 3 corrects two further issues found on owner review of pass 2:**

1. Pass 2's own `AccumulatedMoney` boundary test mislabeled
   `99999999999999.99` (14 integer digits, precision 16) as "the
   `NUMERIC(18,2)` maximum." The actual `NUMERIC(18,2)` maximum is
   `9999999999999999.99` (16 integer digits, precision 18). The schema's
   pattern is now capped at that exact value — see §8 and the corrected
   boundary tests in §25.
2. A genuine Stage 4 contract gap, found by checking capability→operation
   coverage in the direction pass 1/2 never checked: `SALE_VOID_APPROVE`/
   `SALE_REFUND_APPROVE` were canonical capabilities with **no operation
   that used them**. Eight new operations
   (`voidList`/`voidGet`/`voidApprove`/`voidReject` and the Refund
   equivalents) close this — see §12.1, the new authoritative section for
   this workflow.

**Pass 4 performs one final semantic verification requested before
tagging the baseline — approval vs. execution — and corrects two
description-accuracy issues it surfaced (no new endpoints):**

1. **Verified against frozen text, not inferred (§12.1):** `APPROVED` is
   not a separately-effective resting state in Stage 2's Void/Refund
   state machines — it is immediately followed by system execution in
   the same diagram, and `invariants.md` #24 names exactly three void
   audit events for four lifecycle moments (no `SALE_VOID_APPROVED`
   exists). `voidApprove`/`refundApprove` are therefore confirmed fiscal
   execution commands, not permission grants — this validates pass 3's
   atomic design; no redesign was needed.
2. **Corrected:** `voidReject`/`refundReject` (and `voidApprove`'s
   system-auto-`REJECTED` outcome) were described as writing to
   `electronic_journal_entry` — wrong, since a rejection is never a
   fiscally-journalable event (domain-model.md §2.10). They now correctly
   write `audit_event` only.
3. **Added:** `RefundSummary.requested_by`/`approved_by`/`requested_at`/
   `resolved_at` — `VoidSummary` always had these; `RefundSummary` never
   did, which only became a real problem once `refundReject` needed to
   state explicitly who rejected a request and when.
4. **Added:** four stale-request scenarios (later revised to five in
   pass 5 — see below)
   ([examples/approval-stale-context-scenarios.json](examples/approval-stale-context-scenarios.json))
   proving approval always uses the processing context and eligibility
   that exist at execution time, never what existed at request time.

**Pass 5 corrects one final issue found on owner review of pass 4 — the
last thing standing between this contract and `stage-4-baseline`:**

1. **A real defect, not a misreading:** pass 4's "documented, deliberate
   asymmetry" framing treated `voidApprove`'s auto-`REJECTED` outcome
   (on a failed execution-time eligibility recheck) as correct because
   `state-machines.md` §2 said so explicitly. On further review, that
   frozen text itself conflated "this attempt didn't succeed" with "this
   request is authoritatively declined." **Corrected:** a failed approve
   attempt (for any reason — the original eligibility conditions, the
   executing terminal's own shift/fiscal_day, or, for Refund, the
   cumulative-cap re-check) now always leaves the request `REQUESTED`,
   unchanged, and returns a stable `409`/`422` instead. `REQUESTED →
   REJECTED` is reached exclusively through the explicit
   `POST .../reject` commands.
2. **`state-machines.md` amended** (Stage 2, disclosed, committed
   separately) to remove the "moves to REJECTED (system-rejected...)"
   wording from the Void lifecycle notes.
3. **Void's and Refund's stale-fiscal-day behavior made explicit and
   distinct** (§12.1): Void's originating-sale-fiscal_day condition is a
   **permanent** eligibility gate (once that day Z-closes, that void can
   never execute again); Refund has no such condition at all and may
   legitimately execute under a much later fiscal_day. The executing
   terminal's own open-shift/fiscal_day condition, by contrast, is
   **transient** for both Void and Refund — correctable by opening a
   shift and retrying.
4. **Idempotency refined for the failure path:** a failed (409/422,
   non-committing) approve attempt does not durably consume its
   Idempotency-Key — the same key may be reused for a fresh attempt once
   the underlying condition is corrected.

See also: [openapi.yaml](openapi.yaml) (the contract itself — 86
operations, 75 path templates, 67 component schemas, validated
structurally and semantically — see §"OpenAPI validation results"),
[operation-inventory.md](operation-inventory.md) (the full per-operation
review table, now including X-Reading and Z-Reading BIR crosswalks),
[error-catalog.md](error-catalog.md) (the standard envelope and domain
error codes), [csv-export-contract.md](csv-export-contract.md) (new —
exact column contract for every CSV export), [examples/](examples/)
(request/response examples, now schema-validated — see §"OpenAPI
validation results" — not merely parse-checked).

---

## 1. Versioning and style

Base path `/api/v1`; no implementation version embedded in resource names.
Financially sensitive domain commands are modeled as business commands
(`POST /sales`, `POST /sales/{saleId}/void`, `POST /sales/{saleId}/refunds`,
`POST /shifts/open`, `POST /shifts/{shiftId}/close`, `POST /fiscal-days/{fiscalDayId}/close`)
rather than forced into generic CRUD — there is no `PATCH /sales/{id}`
anywhere in the contract, and **zero `DELETE` endpoints exist in the entire
API** (verified — see §"OpenAPI validation results").

## 2. Authentication

Session-based, same-origin, Laravel-compatible (`cookieAuth` security
scheme — `openapi.yaml` `components/securitySchemes`). `POST /auth/login`,
`POST /auth/logout`, `GET /auth/me`. Session expiration is a server-side
TTL/inactivity policy (Stage 6 configuration; not renegotiated per-request
in the contract). CSRF protection follows Laravel's standard double-submit-
cookie mechanism (`X-XSRF-TOKEN` header sourced from the `XSRF-TOKEN`
cookie) applied as **global middleware to every state-changing request** —
this is documented once here rather than repeated as an explicit parameter
on all ~50 mutating operations, which would make the spec unreadable
without adding any real information (every mutating operation has it,
uniformly, no exceptions). Authorization failure (missing capability) is
`403 AUTHORIZATION_DENIED`, distinct from `401 AUTHENTICATION_REQUIRED`
(no/invalid session). No endpoint returns a password hash or any other
credential material — verified in the data-exposure review (§19).

## 3. Terminal identity (ADR-011 implemented)

`POST /terminal-enrollment-tokens` (admin generates a one-time token) →
`POST /terminal/enroll` (workstation submits it, establishing an `HttpOnly`
terminal credential on that browser) → `GET /terminal/current` → admin
`GET /terminals`, `GET /terminals/{id}`, `POST /terminals/{id}/revoke`.
**No request body field named `terminal_id` (or any variant) is accepted
on any financially-consequential operation.** Every operation that needs
"which terminal is this" resolves it from the authenticated terminal
credential established by `terminal/enroll` — verified explicitly for
`SaleFinalizeRequest` and `RefundRequest` in §"OpenAPI validation results,"
and true by construction for Void/Shift/Cash-movement/Stock/FiscalDay
requests, none of which expose a `terminal_id` property at all.

## 4. Authorization — capability model

**Capability-naming gap closed this remediation pass** (previously
deferred to Stage 6 — the owner's instruction was explicit that contract
vocabulary should not wait). Six capabilities were added to Stage 2's
original catalog, directly in `openapi.yaml`'s `Capability` enum
(domain-model.md §2.2 is the frozen source for the original eleven; this
addition is disclosed here, not silently made — see §28):

| New capability | Covers |
|---|---|
| `CATALOG_MANAGE` | Create/update/activate/deactivate products; create categories/brands; CSV import |
| `AUDIT_VIEW` | Read `audit_events` |
| `JOURNAL_VIEW` | Read `electronic_journal_entries` |
| `USER_MANAGE` | Create/update/deactivate/activate users |
| `TERMINAL_MANAGE` | Terminal enrollment tokens, enrollment, list/get/revoke |
| `FISCAL_CONFIGURATION_MANAGE` | Tax-registration changes, fiscal-installation records |

This is **contract vocabulary — a fixed enum of string constants — not a
dynamic/configurable RBAC system.** Every sensitive operation's required
capability is now a stable value, documented per-row in
[operation-inventory.md](operation-inventory.md) and machine-readable via
an `x-capability` vendor extension on the relevant `openapi.yaml`
operations — never described merely as "Manager endpoint"/"Admin
endpoint." The `Capability` enum is also exposed in the contract
(`UserSummary.capabilities`) so a frontend can adapt its own UI to what
the current user can actually do, without hardcoding role names.

**Stage 6 default role→capability mapping** (illustrative for contract
purposes — the fixed role→capability table itself is a Stage 2/6
code-level concern, not something the API exposes as configurable in V1;
this mapping can be tuned during implementation without a contract
change, since call sites ask `can($user, CAPABILITY)`, never `if role ==
'MANAGER'` — Stage 2 §2.2):

| Capability | ADMIN | MANAGER | CASHIER |
|---|---|---|---|
| `SALE_VOID` | ✓ | ✓ | ✓ (request only) |
| `SALE_VOID_APPROVE` | ✓ | ✓ | — |
| `SALE_REFUND` | ✓ | ✓ | ✓ (request only) |
| `SALE_REFUND_APPROVE` | ✓ | ✓ | — |
| `PRICE_OVERRIDE` | ✓ | ✓ | — |
| `DISCOUNT_OVERRIDE` | ✓ | ✓ | — |
| `STOCK_ADJUST` | ✓ | ✓ | — |
| `CASH_OUT` | ✓ | ✓ | — |
| `REPORT_VIEW` | ✓ | ✓ | — |
| `CATALOG_MANAGE` | ✓ | ✓ | — |
| `AUDIT_VIEW` | ✓ | ✓ | — |
| `JOURNAL_VIEW` | ✓ | ✓ | — |
| `FISCAL_DAY_CLOSE` | ✓ | ✓ | — |
| `STORE_SETTINGS_MANAGE` | ✓ | — | — |
| `USER_MANAGE` | ✓ | — | — |
| `TERMINAL_MANAGE` | ✓ | — | — |
| `FISCAL_CONFIGURATION_MANAGE` | ✓ | — | — |

This matches the governing brief's own role sketch (Manager: "POS,
products, inventory, reports, shifts, approve void/refund, limited
settings"; Admin: "all functions"; Cashier: "POS checkout... request
void/refund") without inventing new role semantics — only naming the
capabilities that sketch already implied.

**Capability-to-operation coverage (both directions, verified pass 3):**
verifying `x-capability` ⊆ `Capability` enum was never sufficient on its
own — it proves the contract invents no unknown capability, but says
nothing about whether every canonical capability is actually reachable.
Running the reverse check surfaced exactly one real gap
(`SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` had no operation at all — now
closed, §12.1) and three capabilities that remain, correctly, without a
whole-endpoint `x-capability` gate:

| Capability | Where it's actually checked |
|---|---|
| `PRICE_OVERRIDE` | Conditionally, inside `saleFinalize` (`POST /sales`) — `SaleItemInput.override_reason` is "Required when a PRICE_OVERRIDE/DISCOUNT_OVERRIDE capability check applies." A capability that only gates *some* request shapes on a shared endpoint cannot be that endpoint's single `x-capability` value. |
| `DISCOUNT_OVERRIDE` | Same field, same endpoint — a manual per-line discount override, as opposed to `order_discount_eligible`'s ordinary allocation path. |
| `CASH_OUT` | Conditionally, inside `shiftCashMovementCreate` (`POST /shifts/{shiftId}/cash-movements`) — its own description states "`CASH_OUT` above a configurable threshold requires the `CASH_OUT` capability," while `CASH_IN` and below-threshold `CASH_OUT` need no capability at all. |

This was already-existing pass-1/pass-2 design (both fields predate this
remediation), just never previously cross-checked against the full
capability list in one pass. All three are pre-existing, documented,
intentional field-level conditions — not a contract gap requiring a new
operation — recorded here so a coverage report never has to re-derive
this reasoning from scratch.

## 5. Idempotency (ADR-010, now contractually mandatory)

`Idempotency-Key` (required header, UUID) is **required** on: Sale
finalization, Refund finalization, Void execution, Shift open, Shift
close, Cash In/Out, Stock Receipt, Stock Adjustment, FiscalDay closure,
Invoice reprint, and — **added remediation pass 3** — Void approve, Void
reject, Refund approve, and Refund reject (§12.1) — verified as a hard
contract requirement for all **fourteen** in §"OpenAPI validation
results." Approve/reject need it for the same reason every other
financially-consequential command does: a network-dropped response must
never risk a second execution or a duplicate audit event on retry. Scope
is `(terminal_id, key)` with
`terminal_id` resolved from the enrolled terminal context (§3), never from
the header or body. Same key + same canonical request-hash → original
result; same key + different hash → `409 IDEMPOTENCY_KEY_REUSED`; a
different enrolled terminal presenting the same UUID string is a
completely separate namespace. Retention is **not** time-boxed — see
ADR-010's revision note; a short TTL would make a legitimate delayed retry
unsafe, and the storage/retention *implementation* (table shape, index
strategy) is explicitly left to Stage 5/6. Full walkthrough: [examples/idempotency-example.md](examples/idempotency-example.md).

## 6. Request correlation

Every response — success or error — carries `request_id` (in the error
envelope's `error.request_id` field for failures; as a response header,
conceptually `X-Request-ID`, for all responses including success, Stage 6
implementation detail not separately schema'd per 2xx response to avoid
bloating every success schema with a header that adds no business
information). The server accepts an inbound `X-Request-ID` from the client
and echoes it if present, generating one if absent — this is **never** the
same value as `Idempotency-Key`: `request_id` is an observability/support
correlation identifier (useful for "which request produced this log
line"), while `Idempotency-Key` is a financial safe-retry identifier tied
to one specific attempt's payload. Conflating them would mean every
retry (same `Idempotency-Key`, by design) would also collide on request
tracing, defeating the purpose of having distinct request IDs per
HTTP call for observability.

## 7. Standard error envelope

See [error-catalog.md](error-catalog.md) for the full envelope shape, HTTP
status table, and domain code list. No 200-with-error-flag exists anywhere
in the contract (verified).

## 8. Money and Quantity representation

`Money` — decimal string, exactly 2 places, pattern `^-?\d+\.\d{2}$`,
never `type: number`/`format: float` anywhere in the spec (verified — see
§"OpenAPI validation results"). Negative values are permitted by the
schema pattern but **no field in the contract that represents a Refund
amount is ever negative** — `RefundResult.refund_total`,
`RefundItem.unit_refund_amount`, and `RefundSettlement.amount` are all
positive quantities representing "how much came back," per the instruction
to prefer a positive amount over a sign-encoded direction; the *fact* that
it's a refund (as opposed to a sale) is carried by which resource/endpoint
produced it, not by a negative sign. `Quantity` — decimal string, up to 3
places, pattern `^\d+(\.\d{1,3})?$`, matching Stage 2's `NUMERIC(10,3)`
value object exactly; never a binary float.

**`AccumulatedMoney` — new schema added in pass 1, capacity corrected in
pass 3**, closing a capacity concern: RMO 24-2023 requires the
Accumulated Grand Total Sales figure to support at least 12 digits
inclusive of the 2 decimal places. `Money`'s own regex (`^-?\d+\.\d{2}$`)
never actually capped the integer portion's digit count, so no value was
previously *rejected* — but `Money` is documented and storage-sized
(architecture.md §3.1, `NUMERIC(12,2)`) for a single transaction/line,
while an accumulated lifetime total is sized `NUMERIC(18,2)`.
`AccumulatedMoney` is a deliberately distinct schema (same string shape,
no sign allowed) specifically so a future tightening of `Money` for
per-transaction use (e.g., a practical `maxLength` sanity bound on a
single sale) can never be silently inherited by
`ZReadingTotalsSnapshot.accumulated_grand_total_sales_before`/`_after` —
see [operation-inventory.md](operation-inventory.md)'s Z-Reading
crosswalk. **Pass 1 described this schema as having "no artificial digit
cap," which was itself the bug pass 3 fixes**: an uncapped string schema
let the contract accept values `NUMERIC(18,2)` cannot store. The pattern
is now `^(?:0|[1-9][0-9]{0,15})\.[0-9]{2}$` — capped at exactly
`NUMERIC(18,2)`'s own maximum (16 integer digits + 2 fractional =
`9999999999999999.99`), comfortably above the RMO 24-2023 12-digit floor
(`9999999999.99`) without ever promising a value the storage layer would
reject.

## 9. Pagination, filtering, sorting

One reusable pattern: `page`/`per_page` query params (max `per_page` 100),
response `{ data: [...], meta: { page, per_page, total, last_page } }` —
used identically across every list endpoint (`PaginatedResponse` schema,
referenced via `allOf`). Filtering uses a fixed, predictable query-param
vocabulary per resource (`from`, `to`, `status`, `cashier_id`,
`terminal_id`, `payment_method`, `invoice_number`, `transaction_number`,
`product_id`, `category_id`) — no arbitrary SQL-like filter expressions
are accepted anywhere. Sorting (where offered) uses an explicit `sort`
enum allow-list per endpoint (e.g. `sort=-sold_at`), never a raw column
name.

**CSV export contract — closed this remediation pass, not left to Stage
5/6 to invent independently.** Every report's exact column names/order,
plus the shared format rules (UTF-8, header row present, RFC 3339
timestamps, plain-decimal money with no currency formatting, RFC 4180
quoting), are pinned in the new
[csv-export-contract.md](csv-export-contract.md). The V1 layout is a
stable contract within `/api/v1` — a breaking column rename/removal
requires a versioned export contract, never a silent implementation
choice.

## 10. FiscalDay opening policy — closing the previously-deferred Stage 2/3 decision

**Decision: auto-open a FiscalDay on the first Shift-open when the
terminal has no currently `OPEN` FiscalDay.** `POST /shifts/open`
resolves (or atomically creates, in the same transaction) the terminal's
`fiscal_day` before opening the `shift`; the response's
`fiscal_day_was_opened` flag tells the caller whether a new business day
was just started. If an `OPEN` FiscalDay already exists, it is reused.
Z-Reading remains the **only** explicit closure boundary — this policy
never uses calendar midnight to imply closure (consistent with Stage 2
`fiscal_day.business_date` being a label, not a query filter — Stage 2
invariant #9/domain-model.md §2.5).

**This is TindaFlow V1 operating policy, explicitly not a claim about a
BIR-mandated opening sequence** — no BIR issuance reviewed in
[bir-reference-register.md](../01-research/bir-reference-register.md)
prescribes how a FiscalDay/business-day must be opened, only how it must
be closed (BIR-014). This resolves the question Stage 2 (state-machines.md
§4) and Stage 3 (architecture.md §8) both explicitly left open for a later
stage to decide — it does not reopen or contradict either document, and
neither was edited to record this; it is recorded here, where the decision
actually takes effect (the `shiftOpen` operation's behavior).

## 11. Checkout contract

`POST /sales` — see [operation-inventory.md](operation-inventory.md)'s
Sales section for the full error-code list. The request accepts only
`items` (product + quantity + optional line-level discount/eligibility),
`order_level_discount_amount`, `payments`, optional buyer info, and a
**non-authoritative** `client_expected_grand_total` used only for a UI
mismatch warning. It never accepts `subtotal`, tax figures, `grand_total`,
`change`, `invoice_number`, `transaction_number`, `fiscal_day_id`,
`shift_id`, `cashier_id`, or `terminal_id` — all resolved/computed
server-side (verified in §"OpenAPI validation results"). The response
(`SaleDetail`) returns everything the cashier screen needs to update
immediately and print, without reconstructing any total from the original
request. See [examples/checkout-cash-sale.json](examples/checkout-cash-sale.json)
and [examples/checkout-mixed-tax-and-payment.json](examples/checkout-mixed-tax-and-payment.json).

## 12. Void and Refund contracts

`POST /sales/{saleId}/void` accepts only `reason`; `POST
/sales/{saleId}/refunds` accepts `items` (original `sale_item_id` +
quantity + disposition), `settlements` (payment method + amount +
optional external reference), and `reason`. Neither accepts a processing
terminal/shift/fiscal_day field — both are resolved from the authenticated
terminal context, populated into the response's `terminal_id`/
`fiscal_day_id`/`shift_id` (Stage 2 invariants #67–#69), which are
explicitly documented as independent of the original sale's own fields of
the same name. The Refund response's `remaining_refundable` array is
explicitly marked non-authoritative (a UI convenience, always re-derived
server-side on the next attempt). `RefundDisposition` matches the frozen
four-value enum exactly (`RETURN_TO_STOCK`, `DAMAGED`, `EXPIRED`,
`DISPOSED`) — no fifth value invented. See
[examples/void-example.json](examples/void-example.json),
[examples/refund-regression-monday-friday.json](examples/refund-regression-monday-friday.json)
(the Monday-sale/Friday-refund scenario, proving the processing-context
distinction end-to-end), and
[examples/auth-and-barcode-lookup.json](examples/auth-and-barcode-lookup.json)'s
`refund_with_non_cash_settlement` (proving a non-CASH settlement doesn't
touch drawer cash accounting).

### 12.1 Void/Refund approval workflow (added remediation pass 3 — closes a real contract gap)

On owner review of remediation pass 2, a genuine gap was confirmed, not
merely documented: the canonical capability catalog has carried
`SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` since Stage 2, and the role
mapping in §4 has always given Manager/Admin those approval capabilities
while Cashier gets only the request-side `SALE_VOID`/`SALE_REFUND` — but
until this pass, **no operation in the contract ever exercised the
approval capabilities**. `POST /sales/{saleId}/void` and `POST
/sales/{saleId}/refunds` only ever executed immediately (when the
requester already held the approve capability) or left the row in
`REQUESTED` with no path back out for a *different*, later actor. This
was verified directly against the frozen state machines before writing
any fix, per the instruction to stop and report rather than invent a
lifecycle Stage 2 doesn't actually contain — and Stage 2 **does** contain
one: `state-machines.md` §2 and §3 both define real `REQUESTED →
APPROVED`/`REQUESTED → REJECTED` transitions, driven by "manager/admin
approves"/"manager/admin rejects," distinct from the "immediate" case
already handled inline. `sale.status` stays `COMPLETED` while a void sits
`REQUESTED` (it only becomes `VOIDED` at execution), so a `REQUESTED` void
was **structurally undiscoverable** through any existing endpoint —
`GET /sales`'s own `status` filter can never surface it. This exactly
matches a screen Stage 1's own `sitemap.md` already planned but Stage 4
never backed: `/back-office/void-refund/approvals` (pending queue) and
`/back-office/void-refund/:id` (detail).

**Approval-is-execution determination (remediation pass 4 — verified,
not inferred).** Before tagging the baseline, the owner asked one precise
question: does `REQUESTED → APPROVED` mean the fiscal adjustment is
finalized/effective, or is `APPROVED` only authorization for a later,
separate execution step? Quoting the frozen text directly rather than
inferring:

- Void (`state-machines.md` §2): `REQUESTED --> APPROVED: manager/admin
  approves (...)` is immediately followed, in the very next line of the
  same diagram, by `APPROVED --> VOIDED: system executes reversal (...)`.
  Nothing in Stage 2 describes a human or a separate call triggering that
  second transition — it says "**system** executes," not "an operator
  later executes." There is no field, no query, no note anywhere in
  Stage 2 describing an `APPROVED`-but-not-yet-`VOIDED` void as something
  that can be observed or acted upon.
- Refund (`state-machines.md` §3): identical shape —
  `REQUESTED --> APPROVED: manager/admin approves (...)` immediately
  followed by `APPROVED --> COMPLETED: system executes (...)`.
- The strongest textual evidence is `invariants.md` #24: **"Every void
  request, approval, rejection, and completion writes an `audit_event`
  (`SALE_VOID_REQUESTED`, `SALE_VOIDED`, or `SALE_VOID_REJECTED`)."** Four
  named lifecycle moments (request, approval, rejection, completion) map
  to exactly **three** audit event names — there is no
  `SALE_VOID_APPROVED` event at all. Stage 2 does not treat "approval" as
  its own independently-loggable, fiscally-observable moment; it folds
  approval into the same event as completion (`SALE_VOIDED`).

**Determination: `APPROVED` is not a separately-effective resting state
in Stage 2 — it is a pass-through node collapsed into execution.** The
fiscally effective, final states are `VOIDED`/`COMPLETED`, and Stage 2's
own model reaches them in the same breath as approval, with no
observable gap. Consequently `voidApprove`/`refundApprove` **are fiscal
execution commands, not permission grants** — approving a request and
finalizing the fiscal adjustment are the same call, because Stage 2 never
separates them. This confirms pass 3's atomic design was already correct;
this pass tightens the contract's own language (below) so this is stated
explicitly rather than left to be inferred from the state diagram, and
corrects two behaviors pass 3 described accurately in outline but not
completely: request-time vs. execution-time context, and a
journal-entry claim that was wrong for the rejection path.

**Eight new operations, added symmetrically for Void and Refund:**

| Operation | Method/Path | Capability | Idemp.? | Term. enrolled? |
|---|---|---|---|---|
| `voidList` | GET `/voids` | — (any session, mirrors `GET /sales`) | no | false |
| `voidGet` | GET `/voids/{voidId}` | — | no | false |
| `voidApprove` | POST `/voids/{voidId}/approve` | `SALE_VOID_APPROVE` | **yes** | **true** |
| `voidReject` | POST `/voids/{voidId}/reject` | `SALE_VOID_APPROVE` | **yes** | false |
| `refundList` | GET `/refunds` | — | no | false |
| `refundGet` | GET `/refunds/{refundId}` | — | no | false |
| `refundApprove` | POST `/refunds/{refundId}/approve` | `SALE_REFUND_APPROVE` | **yes** | **true** |
| `refundReject` | POST `/refunds/{refundId}/reject` | `SALE_REFUND_APPROVE` | **yes** | false |

`voidList`/`refundList` are the *only* way to discover a pending
`REQUESTED` item — filterable by `status` (e.g.
`GET /voids?status=REQUESTED` for the approvals queue) and `sale_id`.
`voidGet`/`refundGet` back the sitemap's detail route directly.

**Why approve is atomic (REQUESTED→APPROVED→VOIDED/COMPLETED in one
call), not two separate steps:** Stage 2's own state diagrams show
`APPROVED` immediately followed by system-executed reversal/completion
("system executes reversal", "system executes"), with no separately
observable resting state between them — the frozen model never describes
a human triggering execution as a distinct action from approval. So
`voidApprove`/`refundApprove` perform both transitions in one database
transaction, exactly mirroring what already happens inline in the
original request endpoint when the requester holds the approve
capability from the start. This is not a new lifecycle invented for the
API — it is the same execution semantics already frozen in Stage 2,
now reachable by a second, later actor.

**Terminal enrollment and processing context:** approve requires an
enrolled terminal because execution populates
`terminal_id`/`fiscal_day_id`/`shift_id` from the *approving* terminal's
own currently-open fiscal day/shift (Stage 2 amendment pass 3;
invariants #67/#69) — independent of the original requester's terminal.
Reject never executes and never populates those fields, so it does not
require terminal enrollment — a deliberate, disclosed asymmetry between
the two new commands, not an oversight.

**Correction (pass 5 / Stage 2 amendment pass 4): failed approval is not
automatic rejection.** Pass 4's text above (now corrected) treated a
failed execution-time eligibility recheck as an automatic
`REQUESTED → REJECTED` transition, citing `state-machines.md` §2's then-
wording: "the transition fails and the void row moves to REJECTED
(system-rejected, with a reason recorded) rather than proceeding." On
further review, this was wrong — not as a misreading of the frozen text
(that quote was accurate), but because the frozen text itself conflated
two different things: **"this attempt to authorize the fiscal adjustment
didn't succeed"** and **"an authorized decision has been made that this
request should never proceed."** RMO 24-2023 constrains *when* a fiscal
adjustment may take effect (Z-Reading closes a business day; nothing
dated to it may execute afterward) — it does not prescribe that a
failed *attempt* to execute one must convert the underlying request into
a permanent, business-level rejection. That is a distinct TindaFlow
design decision, and treating it as an automatic side effect of a failed
`POST .../approve` call was a real defect, not a defensible reading of
BIR policy.

**`state-machines.md` §2 has been corrected** (Stage 2 amendment, same
disclosed pattern as the capability-catalog sync): a failed
execution-time eligibility recheck now leaves the void `REQUESTED`,
unchanged, and the API surfaces a stable `409` instead of a `200` with
`status: REJECTED`. This applies uniformly to **every** execution-time
failure mode for both Void and Refund — the original five/six
eligibility conditions, the executing terminal's own open shift/fiscal_day,
and Refund's cumulative quantity/amount re-validation — none of them
auto-reject. **`REQUESTED → REJECTED` is now reached exclusively through
the explicit `POST .../reject` commands** — a human, capability-holding
decision, never a side effect of a failed execution attempt. This also
retires the "documented, deliberate asymmetry between Void and Refund"
this section previously described: with the correction, Void and Refund
now share the *same* failure-handling shape (fail safely, leave
`REQUESTED`, reuse the same Idempotency-Key to retry) — the only
remaining difference between them is *which* conditions each re-checks,
covered next.

**Void's stale-day rule vs. Refund's stale-day rule — explicitly
different, and both correct:**

- **Void** requires the *originating* sale's own `fiscal_day` to still be
  `OPEN` (one of the five request-time conditions, re-checked at
  execution — `domain-model.md` §2.8, `state-machines.md` §1/§2). Once
  that specific fiscal_day Z-closes, this condition can **never** become
  true again for that void — every future approval attempt against it
  fails identically (`409 SALE_NOT_VOIDABLE`), forever, regardless of
  which fiscal_day the *executing* terminal currently has open. The void
  is never attributed to a later fiscal_day merely to make it
  executable; only an explicit reject can resolve it after that point.
- **Refund** has **no such condition** — `state-machines.md` §3 states
  this explicitly: *"Unlike Void, Refund has no eligibility condition
  requiring the original sale's fiscal day to still be open."* A refund
  may be approved and executed under a fiscal_day entirely different
  from (and later than) the one the original sale belongs to — exactly
  the Monday-sale/Friday-refund pattern, now shown applying equally to
  Monday-*request*/Friday-*approval*. The refund permanently references
  the original Sale/SaleItems from the old fiscal_day while its
  *processing context* (terminal/shift/fiscal_day) is entirely the
  executing terminal's current one — both stay visible, distinctly, in
  the response.
- **Both** Void and Refund separately re-check the *executing terminal's
  own* currently-open shift/fiscal_day at approval time (Stage 2
  amendment pass 3; invariants #67/#69) — this condition **is** transient
  and operational for both: opening a shift and retrying resolves it,
  unlike Void's originating-fiscal_day condition above, which is
  permanent once triggered.

Conflating these three checks — as the pre-pass-5 examples partly did —
understated a real domain distinction: Void's originating-fiscal_day
rule is a permanent eligibility gate BIR policy motivates (a closed
business day's transactions cannot be adjusted); the executing-terminal
check is a purely operational precondition either Void or Refund can
clear by opening a shift; and Refund's design deliberately has no
originating-fiscal_day gate at all.

**Approve/reject never re-solicit refund detail.** `refundApprove` takes
no request body — the `disposition`/`quantity`/`payment_method`/`amount`
detail was already captured in the original `RefundRequest` at
`REQUESTED` time and is executed unchanged at `APPROVED → COMPLETED`
(`refund_item`/`refund_settlement` rows do not exist before that moment,
per `state-machines.md` §3, so a `REQUESTED`-state `RefundResult`
correctly shows `items: []`/`settlements: []`). `voidReject`/
`refundReject` require `{reason}` (the decliner's own reason, distinct
from the original requester's `reason`); `voidApprove`/`refundApprove`
take no body at all.

**New error codes** (error-catalog.md): `VOID_NOT_FOUND`,
`VOID_NOT_PENDING_APPROVAL`, `REFUND_NOT_FOUND`,
`REFUND_NOT_PENDING_APPROVAL` — all `409` except the two `_NOT_FOUND`
codes (`404`), covering exactly "this void/refund is no longer
`REQUESTED`" for a concurrent or repeated approve/reject attempt.
Execution-time failures reuse *existing* codes rather than adding new
ones — `409 SALE_NOT_VOIDABLE`/`FISCAL_DAY_CLOSED`/`SHIFT_NOT_OPEN` and
`422 REFUND_EXCEEDS_REMAINING_QUANTITY`/`REFUND_EXCEEDS_REMAINING_AMOUNT`
— the same codes their respective request-time checks already use (see
"Stale-request scenarios" below); no `*_SYSTEM_REJECTED`-style code was
ever needed once the auto-reject behavior was removed.

**Correction (pass 4, still valid after pass 5): reject never writes an
`electronic_journal_entry`.** Pass 3's operation descriptions said reject
writes to "`audit_event`/`electronic_journal_entry`" — wrong.
`domain-model.md` §2.10 is explicit that `electronic_journal_entry` is a
projection of **"every fiscally-journalable event"**, enforced by a
uniqueness constraint on `(source_type, source_id, event_type)`: exactly
one `VOID`-type (or `REFUND`-type) journal row can ever exist for a given
void/refund, and a `REJECTED` outcome is never a fiscal fact — nothing
was voided or refunded. `voidReject`/`refundReject` write **`audit_event`
only**. A **failed approve attempt** (pass 5's correction) writes
**neither** — no `audit_event`, no `electronic_journal_entry` — since
nothing about the request changed at all; only a successful execution
(`VOIDED`/`COMPLETED`) or an explicit reject ever produces a new
audit/journal entry.

**Correction (pass 4): `RefundSummary` was missing the actor/timestamp
fields needed to represent what reject/approve now explicitly do.**
`VoidSummary` has always had `requested_by`/`approved_by`/`requested_at`/
`resolved_at`; `RefundSummary` had none of them — a pre-existing pass-1
asymmetry that only became a real problem once `refundReject` needed to
state explicitly *who* rejected a request and *when*, per the "resolve
authenticated rejecting actor; persist reason/rejected_at/rejected_by"
requirement. Added `requested_by`, `approved_by` (named for symmetry
with `VoidSummary` even when the actual resolution was a rejection),
`requested_at`, and `resolved_at` to `RefundSummary` — additive only, no
existing field removed or renamed. `refunded_at` is kept as its own
field, distinct from `resolved_at`, specifically so a `REJECTED` refund
is never described as having a "refunded" moment it never had.

**Stale-request scenarios (pass 4, corrected in pass 5 — contract-level
examples, not just prose):** five scenarios in
[examples/approval-stale-context-scenarios.json](examples/approval-stale-context-scenarios.json)
prove the processing context and eligibility used at approval are always
the ones that exist **now**, never the ones captured at request time —
and that a failed attempt never silently becomes a rejection:

| Scenario | What happens | Outcome |
|---|---|---|
| A — No current OPEN shift at approval (executing-terminal condition) | Cashier requests a void at 17:55 on shift-A; shift-A closes at 18:00; approval attempted at 18:10 | **If no shift has reopened:** `409 SHIFT_NOT_OPEN`, void stays `REQUESTED`, unchanged — retry with the same key once a shift opens. **If a new shift-B has since opened (same fiscal_day):** `200 VOIDED`, using shift-B — never shift-A |
| B — Void's *originating* FiscalDay closed (permanent) | Void requested on FiscalDay A; FiscalDay A Z-closes; approval attempted on FiscalDay B (a valid, currently-open day) | `409 SALE_NOT_VOIDABLE` — FiscalDay B being open does not help; this check is about the *original sale's own* fiscal_day, not the executing terminal's. Void stays `REQUESTED` **permanently** — every future approve attempt fails identically; only an explicit reject resolves it |
| C — Refund against an old Sale, approved in a later valid FiscalDay | Refund requested Monday evening; Monday's FiscalDay closes that night; approved Tuesday morning under Tuesday's FiscalDay | `200 COMPLETED` — Refund has no eligibility tie to the *original* sale's fiscal day (§3), unlike Void (scenario B). The refund permanently references Monday's Sale/SaleItems while its processing context is Tuesday's terminal/shift/fiscal_day |
| D — A concurrent refund consumes the quantity first | Two refunds requested independently against the same `sale_item` (neither rejected at request time, since no `refund_item` rows existed yet for either check to see); the first is approved and consumes the shared quantity | The first: `200 COMPLETED`. The second: `422 REFUND_EXCEEDS_REMAINING_QUANTITY` — re-validated against what exists **now**; refund stays `REQUESTED`, unchanged, never silently over-completed |
| E — A refund completes before a pending void is approved | Void requested against a sale; a separate refund against the same sale is then approved and completed; void approval attempted afterward | `409 SALE_NOT_VOIDABLE` — the same eligibility re-check as scenario B, just a different one of the three original conditions ("no COMPLETED refund exists against the sale"). Void stays `REQUESTED` **permanently** (a completed refund never un-completes) — only an explicit reject resolves it |

Scenarios B and E are deliberately **permanent** failures (once
triggered, retrying never helps) while scenario A is deliberately
**transient** (retrying after opening a shift succeeds) — this
distinction is real and load-bearing, not just an implementation detail:
conflating them would either block a legitimately retryable Void
approval forever, or silently let a permanently-ineligible Void keep
being retried as if it might someday succeed. Every outcome above is
either a genuine success using fresh context, or a stable domain error
that leaves the request completely unchanged — never a silent write into
a closed Shift/FiscalDay, and never a silent, automatic rejection.

**Idempotency, confirmed explicitly, refined in pass 5 for the failure
path:** all four operations were already among the 14 idempotent
operations (§5). For a **successful** execution: same key + same request
→ the original approval/finalization result, verbatim; a retry after an
ambiguous network timeout **must not** execute the Void/Refund a second
time, and does not — ADR-010's `(terminal_id, key)` uniqueness
constraint makes a second execution structurally impossible for the same
key. For a **failed** execution (409/422, no fiscal mutation committed —
pass 5's correction): no idempotency_record is durably resolved for an
attempt that committed nothing, so **the same Idempotency-Key may be
reused for a fresh attempt** once the caller corrects the underlying
condition (opens a shift, etc.) — this is treated as a new attempt of
the same logical operation, not a replay of a cached failure, and a new
key is **not** required. This is a Stage 4 clarification of how ADR-010
applies to these specific operations, not a Stage 3 amendment — ADR-010
itself never says a failed, non-committing attempt permanently consumes
the key; it only defines behavior for hash-mismatch reuse and
successful-result replay, both of which are unaffected. For reject: same
key + same request → the original rejection result, verbatim; no second
`audit_event` is written.

See
[examples/void-approval-workflow.json](examples/void-approval-workflow.json),
[examples/refund-approval-workflow.json](examples/refund-approval-workflow.json),
and
[examples/approval-stale-context-scenarios.json](examples/approval-stale-context-scenarios.json)
for the full request→discover→approve/reject/failed-approval/stale-context
sequences, schema-validated like every other example in this contract.

## 13. X-Reading / Z-Reading contracts

**Cardinality verified against the frozen Stage 2 baseline before writing
anything else in this section** (gating check for this remediation pass):
`domain-model.md` §2.5 states "an X-Reading can be pulled on demand, any
number of times during an open shift... and is also generated
automatically at shift close"; `invariants.md` #43 ("X-Readings do not
require shift closure") and the aggregate table ("`shift` | `cash_movement`,
`x_reading` (zero or more)") confirm it; `erd.md` states
`SHIFT ||--o{ X_READING : "generates (0..N)"` explicitly. **Stage 2 already
supports multiple XReading records per shift — there is no frozen-domain
contradiction to resolve.** The semantics below are a direct, explicit
statement of what was already true, not a change to it:

- An `XReading` is an **immutable, generated accountability snapshot**
  (Stage 2 invariant #41 — append-only, never updated or deleted).
- A `Shift` may have **multiple management-requested `XReading`s** in
  addition to the one automatically generated at close
  (`is_closing_reading: true` distinguishes it).
- `POST /shifts/{shiftId}/close` generates the **final/end-of-shift**
  `XReading` as part of the same transaction that closes the shift.
- **Generating an interim `XReading` does NOT close or reset the shift** —
  `shift.status` is unaffected, and no total is reset (Stage 2 invariant
  #40: readings are reproducible, never authoritative inputs).
- **Reprinting an `XReading` does not generate a new reading** — re-viewing
  a previously generated one is a plain `GET
  /shifts/{shiftId}/x-readings` retrieval of the same immutable row.
  X-Readings carry no BIR-mandated reprint-audit requirement of their own
  (unlike Invoice reprint, which does — ADR-006/§17 below); there is
  deliberately no `POST .../x-readings/{id}/reprints` operation, since it
  would create an audit event for an action (re-displaying already-public
  internal data) that RMO 24-2023 does not require one for.

`POST /fiscal-days/{fiscalDayId}/close` generates **exactly one**
`z_reading`, rejecting with `FISCAL_DAY_HAS_OPEN_SHIFT` if any shift
referencing that fiscal day is still open (Stage 2 invariant #36).
Historical `GET /shifts/{shiftId}/x-readings` and `GET
/fiscal-days/{fiscalDayId}/z-reading` retrieval never regenerates values
from today's configuration — both return the stored `totals_snapshot`
exactly as generated, now via the structured `XReadingTotalsSnapshot`/
`ZReadingTotalsSnapshot` schemas (replacing the original draft's loose
`additionalProperties: true`, closed this pass — see the full field-by-
field BIR crosswalks in
[operation-inventory.md](operation-inventory.md)'s X-Reading and Z-Reading
crosswalk tables). See
[examples/shift-and-fiscalday-examples.json](examples/shift-and-fiscalday-examples.json)
for a fully worked, schema-validated example of both.

## 14. Tax representation

`TaxSummary` (`taxable_sales`, `vat_exempt_sales`, `zero_rated_sales`,
`vat_amount`) is exposed as a first-class object on every `SaleDetail` and
every relevant report, preserving the frozen Stage 2 sum-then-decompose
figures separately — never inferring historical tax classification from
current `Product` configuration (`SaleItem.tax_classification_snapshot`/
`tax_rate_snapshot` are immutable per-line facts, Stage 2 §2.7). Current
BIR invoicing guidance (RMC 77-2024) expects mixed VATable/VAT-exempt/
zero-rated components to be identified appropriately on a VAT invoice; the
mixed-tax example
([examples/checkout-mixed-tax-and-payment.json](examples/checkout-mixed-tax-and-payment.json))
demonstrates the contract carries exactly this breakdown, reconciling
exactly to the grand total.

## 15. Store Settings and effective-dated tax registration

`GET`/`PATCH /store-settings` covers current business identity fields
only — updating them **never** mutates any historical
`invoice_snapshot_json` (ADR-006). Tax-registration changes use their own
resource (`GET`/`POST /tax-registrations`), never a field on
`store-settings` itself, preserving Stage 2's effective-dated history
(invariant #53) — a `POST` creates a new registration row and implicitly
closes the previous one; nothing is overwritten.

## 16. Fiscal Installation

**HTTP shape closed this remediation pass — no longer ambiguous, even
though the database realization stays deferred.** `Accreditation` and
`PermitToUse` are two distinct, independently-nullable nested schema
objects on `FiscalInstallation` (`accreditation: {number, date,
effective_from, effective_to}`, `permit_to_use: {number, min, date,
effective_from, effective_to}`) — **never** collapsed into one generic
`compliance_status` field, per ADR-009's independent-lifecycles decision
(confirmed by RMC 72-2025/BIR-012: an expiring Certificate of
Accreditation does not itself expire the taxpayer's PTU). `min` (Machine
Identification Number) is carried under `permit_to_use`, not
`accreditation`, matching Stage 2 domain-model.md §2.1's grouping. Stage 5
still decides whether the database stores this as two effective-dated
column-pairs or two child tables (ADR-009) — the HTTP contract does not
need that decision made first, and would not need to change shape
regardless of which way Stage 5 goes. **No endpoint claims or implies BIR
accreditation** — the description text on every operation in this tag
explicitly states this is an administrative data-entry surface, not a
compliance mechanism, and no endpoint is named anything resembling
`/make-bir-compliant`.

## 17. Invoice and printing

`GET /invoices/{invoiceId}` (opaque UUID, never `invoice_number`, per the
identifier rule — search by invoice number happens via `GET
/sales?invoice_number=...`). **`invoice_number` is confirmed a STRING
schema, not integer/number** (it was already modeled this way in the
original draft; pass 1 added an explicit `pattern` and description citing
RMO 24-2023's minimum-six-digit, leading-zeroes-preserved requirement
directly, with examples `"000001"`/`"000103"` — a JSON number would
silently drop leading zeroes, which a string never does).

**Pattern tightened in pass 2:** pass 1's pattern was `^\S*\d{6,}$` —
technically correct on the six-digit/leading-zero requirement, but the
leading `\S*` accepted an arbitrary non-whitespace prefix of any shape
(e.g. `@@@000103`), which is broader than anything TindaFlow has actually
decided. Stage 2 `domain-model.md` §2.6 already models `invoice_series`
with `prefix`/`series_code` as fields **distinct from** `current_number`
— so a prefix, if ever surfaced, belongs on its own field, never
concatenated into `invoice_number`. The pattern is now `^[0-9]{6,}$`
(digits only, still leading-zero-preserving, matching the schema's own
`"000103"` example exactly). This also fixed three example files
(`checkout-cash-sale.json`, `checkout-mixed-tax-and-payment.json`,
`refund-regression-monday-friday.json`, `void-example.json`,
`idempotency-example.md`) that had been using an `"INV-000218"`-style
value for `invoice_number` — a value that actually belongs on
`transaction_number`'s namespace (which already carried a `"T-000482"`
prefix in the same examples), not on the fiscal serial.
`transaction_number` remains a structurally separate field, drawn from a
distinct internal namespace and free to carry its own prefix, and is
never treated as the accountable fiscal serial (RMO 24-2023's
series-separation requirement, restated explicitly on both fields'
schema descriptions).

**Reprint contract, verified field-by-field (revised in remediation pass
2 — resource semantics correction):** `POST /invoices/{invoiceId}/reprints`
requires `Idempotency-Key` and returns `InvoiceReprintResult`, **not**
`InvoiceDetail`. This is a deliberate resource-shape change from pass 1:
an Invoice is immutable and may legitimately be reprinted many times
(there is no cardinality limit), so "is this a reprint, and when, and by
whom" describes one reprint *occurrence* — never a property of the
Invoice resource itself. Pass 1 had modeled `is_reprint`/`reprinted_at`
directly on `InvoiceDetail`, which would have made `GET
/invoices/{invoiceId}` and `POST .../reprints` return the same schema
with observer-dependent meaning (a second reprint would silently
overwrite what the first reprint's timestamp "meant" on the shared
resource). `InvoiceReprintResult` wraps the unchanged Invoice instead:

- `reprint_event_id` — this occurrence's own id (the `INVOICE_REPRINTED`
  `audit_event` row's id; no new Stage 2 entity was introduced for this);
- `invoice` — the complete, unchanged `InvoiceDetail`: same
  `invoice_number`, same `invoice_snapshot_json`-derived content (via the
  unchanged `schema_version`-dispatched renderer, ADR-006), same
  `sale_id`. Byte-for-byte identical to what `GET /invoices/{invoiceId}`
  returns for the same invoice, before or after any number of reprints;
- `is_reprint` — always `true` on this schema (it only ever describes a
  reprint occurrence); `InvoiceDetail` itself carries no such field at
  all now, so there is nothing for `GET /invoices/{invoiceId}` to get
  wrong;
- `reprinted_at` — this specific reprint event's timestamp, distinct from
  the unchanged `invoice.issued_at`;
- `requested_by` — the authenticated user who performed this reprint
  (session-resolved, never client-supplied).

Reprinting never allocates another invoice number, never alters `Sale`
totals, never touches `invoice_series.current_number`, and never
increments a Z-Reading counter merely because a reprint occurred
(reprinting has no relationship to fiscal-day closure at all).
`render_html` inside `invoice` carries a visible "REPRINT"/"COPY"
watermark whenever it was produced via this endpoint (ADR-007), even
though the underlying `invoice` payload is otherwise identical to the
original. The operation still produces its required
`INVOICE_REPRINTED` `audit_event` and `electronic_journal_entry` pair,
unchanged from the original draft — `reprint_event_id` is exactly that
audit event's id, so every reprint occurrence remains independently
auditable no matter how many times a given Invoice is reprinted.
Printing transport itself (the browser's print dialog, per ADR-007)
remains out of the HTTP contract's scope — the API's job ends at handing
back renderable content.

## 18. Audit and Electronic Journal API boundary

Both are **read-only through HTTP** — `GET /audit-events`, `GET
/audit-events/{id}`, `GET /electronic-journal-entries`, `GET
/electronic-journal-entries/{id}`. No `POST`/`PATCH`/`DELETE` exists for
either resource anywhere in the contract (verified) — both arise
exclusively from authoritative domain operations (ADR-005), never from a
direct API write.

## 19. Data exposure review

Reviewed every schema for unnecessary internal exposure:

- **No password hashes** — `UserInput.password` is `writeOnly`;
  `UserSummary` has no password/credential field at all.
- **No terminal credentials** — `TerminalSummary`/`TerminalEnrollmentToken`
  expose only `terminal_code`/`status`/`activated_at` and the one-time
  `token`/`expires_at` (the token itself is necessarily returned once, at
  creation, to be relayed to the workstation — but never re-exposed by any
  `GET` afterward; `terminalGet`/`terminalList` never return a token
  field).
- **No internal lock/version implementation details** — `invoice_series`,
  row-lock mechanics, and the Global Lock Order (architecture.md) are
  Stage 3 implementation concerns with no corresponding field anywhere in
  the contract (§20 below expands on this).
- **UUIDs, not raw database IDs, are the public identifiers** throughout
  (every `id` field is `format: uuid`).
- **No secret enrollment tokens survive past issuance** in any list/get
  response.

## 20. Concurrency conflicts are represented as domain conflicts, not lock leakage

A `sale` changed because a concurrent void/refund completed, a fiscal day
closed while a request was waiting, or a shift closed concurrently are all
surfaced as `409` with a **stable domain code**
(`SALE_ALREADY_VOIDED`, `FISCAL_DAY_CLOSED`, `SHIFT_ALREADY_CLOSED`,
`REFUND_EXCEEDS_REMAINING_QUANTITY`, or the generic
`CONCURRENCY_CONFLICT` when no more specific code applies) — never a raw
PostgreSQL error message or lock-timeout detail. The client vocabulary is
"refund this item" / "void this sale," never "lock this sale_item" —
locking is entirely an architecture.md/Global-Lock-Order implementation
concern with zero surface in the request schemas (verified — no schema in
`components/schemas` mentions a lock, version-for-optimistic-concurrency,
or similar field).

## 21. Contract traceability matrix

| Operation | Stage 2 invariant(s) | Stage 3 ADR(s) |
|---|---|---|
| Sale finalization (`saleFinalize`) | #1–#9 (Sale), DISC-001–006 (discount/tax allocation) | ADR-003 (transaction boundary), ADR-004 (invoice concurrency), ADR-010 (idempotency), ADR-012 (financial calculator) |
| Void (`saleVoid`) | #20–#25, #67–#69 (processing context) | ADR-003, ADR-011 (terminal identity for processing context) |
| Refund (`saleRefund`) | #26–#31, #67–#72 (processing context + settlement) | ADR-003, ADR-012, ADR-011 |
| Shift close (`shiftClose`) | #33–#38, #40, #43 | Global Lock Order (architecture.md), ADR-005 (X-Reading journal entry) |
| FiscalDay close (`fiscalDayClose`) | #32, #36, #40, #42 | Global Lock Order, ADR-005 (Z-Reading journal entry) |
| Stock adjustment (`inventoryAdjustmentCreate`) | #44–#47 | ADR-010 (idempotency) |
| Cash movement (`shiftCashMovementCreate`) | #39 | ADR-010 |
| Invoice reprint (`invoiceReprint`) | #15, #16 | ADR-006 (versioned snapshot), ADR-010 |
| Void approve/reject (`voidApprove`/`voidReject`) — **new pass 3** | #20–#25, #67–#69, `state-machines.md` §2 | ADR-003, ADR-011, ADR-010 |
| Refund approve/reject (`refundApprove`/`refundReject`) — **new pass 3** | #26–#31, #67–#72, `state-machines.md` §3 | ADR-003, ADR-012, ADR-011, ADR-010 |

This is a traceability index, not a restatement of the ADRs/invariants
themselves — follow the links into [invariants.md](../02-domain/invariants.md)
and [decisions/](../03-architecture/decisions/) for full text.

## 22. Security-contract review

Checked explicitly against the risk list:

| Risk | Finding |
|---|---|
| Mass assignment | Every mutating endpoint uses a purpose-built `*Input`/`*Request` schema, never the full resource schema as the request body — a client cannot set a field (e.g. `status`, `id`) that isn't in the narrower input schema. |
| Privilege-bearing fields supplied by browser | None found — `role`/`capabilities` are never client-settable on `UserInput` beyond `role` itself, which is gated by `STORE_SETTINGS_MANAGE`/Admin-only capability at the endpoint level, not by the schema alone. |
| Arbitrary terminal spoofing | Structurally impossible — no request schema in the entire spec has a `terminal_id` property (verified by the same programmatic check as §"OpenAPI validation results"). |
| Arbitrary cashier spoofing | Same — no request schema exposes `cashier_id`; it is always the authenticated user. |
| Client-supplied totals | Verified absent from `SaleFinalizeRequest`/`RefundRequest` (§"OpenAPI validation results"); also absent from `ShiftOpenResult`'s request (`opening_cash` is the only shift-open input, never `expected_cash`) and `FiscalDayClose` (no request body at all). |
| Historical-record mutation | No `PATCH`/`PUT` exists for `Sale`, `Invoice`, `Void`, `Refund`, `AuditEvent`, or `ElectronicJournalEntry` anywhere — **including the new Void/Refund approval commands (pass 3), deliberately modeled as named `POST .../approve`/`POST .../reject` business commands, never a generic `PATCH .../{id} {status: APPROVED}`**, per the explicit instruction that approval is an auditable business command, not CRUD state editing. |
| Dangerous DELETE operations | Zero `DELETE` operations exist in the entire contract. |
| Credential leakage | See §19. |
| Overly broad report access | All 15 report endpoints require `REPORT_VIEW`; none is unauthenticated. |

No findings required a contract change beyond what's already reflected
above — this review passed clean on the first draft, which is recorded
honestly rather than implying issues were found and silently fixed.

## 23. BIR-related contract boundary

The contract preserves every field a future accreditation effort would
need (MIN, PTU, accreditation metadata, sequential invoice numbers,
X/Z-Reading structure, tax breakdown) but **makes no claim that this HTTP
schema itself satisfies accreditation** — no endpoint is named
`/make-bir-compliant` or anything implying that. Future BIR/EIS
transmission remains entirely behind the Stage 3
`SalesTransmissionProvider` boundary and has **no** corresponding endpoint
in this contract — V1 does not invent a BIR-facing API. Unresolved
regulatory interpretations remain marked `BIR-REVIEW-REQUIRED` in
[bir-reference-register.md](../01-research/bir-reference-register.md); none
were newly introduced by Stage 4 (see §26).

## 24. Contract metrics

| Metric | Count | Change this pass |
|---|---|---|
| Total path templates | 67 (pass 1–2) → **75 (pass 3)** | pass 3: +8 (`/voids`, `/voids/{voidId}`, `/voids/{voidId}/approve`, `/voids/{voidId}/reject`, and the four Refund equivalents) |
| Total operations | 78 (pass 1–2) → **86 (pass 3)** | pass 3: +8 (`voidList`, `voidGet`, `voidApprove`, `voidReject`, `refundList`, `refundGet`, `refundApprove`, `refundReject`) — the first endpoint additions since the original draft; every prior pass changed only schemas/descriptions/metadata |
| GET | 49 → **53** | +4 (`voidList`, `voidGet`, `refundList`, `refundGet`) |
| POST | 26 → **30** | +4 (`voidApprove`, `voidReject`, `refundApprove`, `refundReject`) |
| PATCH | 3 | unchanged |
| PUT | 0 | unchanged |
| DELETE | 0 | unchanged |
| Total schemas (`components/schemas`) | 66 (pass 1) → 67 (pass 2) → 67 (pass 3, unchanged) → **67 (pass 4, unchanged)** | pass 3 reused `VoidSummary`/`VoidResult`/`RefundSummary`/`RefundResult`/`PaginatedResponse`/`Error` — no new schema for the approval workflow; pass 4 added 4 *fields* to the existing `RefundSummary` (`requested_by`/`approved_by`/`requested_at`/`resolved_at`), not a new schema |
| Total `$ref` occurrences | 540 (pass 1) → 541 (pass 2) → 594 (pass 3) → **594 (pass 4, unchanged)** | pass 3: +53 (eight new operations' parameter/response `$ref`s); pass 4 added inline fields, no new `$ref`s |
| Operations carrying `x-capability` | 46 (pass 1–2) → **50 (pass 3, unchanged in pass 4)** | +4: `SALE_VOID_APPROVE` on `voidApprove`/`voidReject`, `SALE_REFUND_APPROVE` on `refundApprove`/`refundReject` — closing the exact capability-to-operation gap found on review (§4, §12.1) |

**By module:** Auth 3, Terminal 6, Catalog 13, Inventory 5, **Sales 13**
(5 → 13, +8 for Void/Refund approval), Invoices 2, Shifts 8, FiscalDay 5,
Reports 15, StoreSettings 4, FiscalInstallation 3, Audit 2,
ElectronicJournal 2, Users 5. Passes 1–2 changed only schemas, field
descriptions, and `x-capability`/example content; **pass 3 is the first
pass to add endpoints**, and it does so only because reverse
capability-coverage checking proved the frozen Stage 2 `REQUESTED →
APPROVED`/`REJECTED` lifecycle had no HTTP surface at all (§12.1) — not
as a general expansion. **Pass 4 adds no endpoints** — it verifies a
semantic (§12.1's "approval-is-execution determination"), corrects two
description-accuracy issues (journal-entry claim on rejection paths,
missing `RefundSummary` actor/timestamp fields), and adds stale-request
example coverage.

## 25. OpenAPI validation results

Ran the full structural + semantic validation pass again after every
change, in both remediation passes (PyYAML + `jsonschema`/`referencing`-
based; no network-dependent OpenAPI-service validator was available in
this environment):

**Structural (Pass A) — re-confirmed after pass 3:** YAML parses; all
594 `$ref`s resolve (540 after pass 1, 541 after pass 2, +53 in pass 3
for the eight new operations); all **86** `operationId`s unique (78 →
86, +8 in pass 3 — the first operation additions across any pass); every
path-template parameter declared exactly once; all security schemes
resolve; every operation has a documented response.

**A genuine, previously-undetected defect found and fixed during this
pass:** the original draft used `nullable: true` (OpenAPI 3.0 syntax)
throughout, but this document declares `openapi: 3.1.0`, which uses JSON
Schema 2020-12 — a dialect with **no `nullable` keyword at all**. Directly
tested: a `null` value against a field marked `nullable: true` (e.g.
`VoidSummary.approved_by`) was **rejected** by a strict 2020-12 validator,
exactly contradicting the documented intent, across **85 occurrences**.
This was only caught because this remediation pass moved from "the YAML
parses" to actually running instances through a JSON Schema validator
(item 9's requirement) — the original draft's validation never exercised
a null value against a nullable field. **Fixed:** every `nullable: true`
converted to the JSON Schema 2020-12 idiom (`{anyOf: [<original schema>,
{type: "null"}]}`), applied programmatically across all 85 occurrences to
guarantee consistency, then re-verified: null is now accepted where
intended, and an actually-invalid type (e.g. a number where a string was
expected) is still correctly rejected — confirmed with targeted
before/after test cases, not assumed.

**Semantic checks (this pass, superset of the original draft's list):**
- Zero `DELETE` operations anywhere in the spec.
- No `PATCH`/`PUT` on `/sales/{saleId}` (or any Sale/Invoice/Void/Refund/
  AuditEvent/JournalEntry resource).
- `Money` schema is `type: string` with a 2-decimal pattern; `AccumulatedMoney`
  is a distinct string schema, used specifically for
  `ZReadingTotalsSnapshot`'s accumulated-total fields, capped at exactly
  the `NUMERIC(18,2)` maximum (`9999999999999999.99` — corrected pass 3;
  pass 1/2 had described it as having no digit cap at all, which let the
  contract accept values the storage layer cannot hold).
- `invoice_number` (both `InvoiceSummary` and `SaleSummary`) is `type:
  string` with a `^[0-9]{6,}$` pattern (tightened in pass 2 from pass 1's
  `^\S*\d{6,}$`, which had accepted an arbitrary prefix — see §17) and
  `"000103"`-style examples — never integer/number; `transaction_number`
  remains structurally separate on `SaleSummary` and may carry its own
  prefix.
- `InvoiceDetail` carries no `is_reprint`/`reprinted_at` fields (pass 2 —
  moved to `InvoiceReprintResult`, the dedicated reprint-occurrence
  response schema for `POST /invoices/{invoiceId}/reprints`; see §17).
- `ProductInput` carries no stock-quantity field.
- `SaleFinalizeRequest` and `RefundRequest` expose none of
  `{terminal_id, fiscal_day_id, shift_id, cashier_id, invoice_number,
  transaction_number, grand_total, vat_amount, taxable_sales,
  expected_cash, change}`.
- Exactly 14 operations require `Idempotency-Key` (10 through pass 2, +4
  in pass 3 for `voidApprove`/`voidReject`/`refundApprove`/`refundReject`),
  regenerated programmatically from the spec (not asserted narratively) —
  see [operation-inventory.md](operation-inventory.md)'s Idempotency
  inventory table for the exact list and per-operation confirmation of
  all four required properties.
- **New in pass 3:** `voidList`/`voidGet`/`refundList`/`refundGet` exist
  and are the only operations that can return a `REQUESTED` void/refund
  by itself (`GET /sales`'s own `status` filter can never surface one,
  since `sale.status` stays `COMPLETED` until execution) —
  programmatically confirmed by grepping the two new list operations'
  `status` query parameter enums against `VoidSummary`/`RefundSummary`'s
  own `status` enums (identical).
- `RefundSettlement` schema present and referenced from `RefundResult`.
- `Void`/`Refund` response schemas expose `terminal_id`/`fiscal_day_id`/
  `shift_id` processing context.
- `FiscalDayCloseResult` includes `z_reading` (now `ZReadingTotalsSnapshot`-
  typed, with `accumulated_grand_total_sales_before/after`, `z_counter`,
  `reset_counter`, `payment_breakdown`, and the full VATable/exempt/
  zero-rated/VAT-amount breakdown — no longer a loose
  `additionalProperties: true` bag); `ShiftCloseResult` includes
  `x_reading` (now `XReadingTotalsSnapshot`-typed).
- `FiscalInstallation` exposes `accreditation`/`permit_to_use` as
  independently-nullable nested objects — no `compliance_status` field
  exists anywhere in the schema.
- `electronic_journal_entry.event_type` is a closed 10-value enum matching
  Stage 2's catalog exactly (`ElectronicJournalEventType`), reused
  identically on the `journalEntryList` query parameter.
- **Capability coverage, both directions (direction B genuinely new in
  pass 3):**
  - **(A) Forward** — every `x-capability` value on all 50 tagged
    operations (46 through pass 2, +4 in pass 3) is a valid member of the
    `Capability` enum (0 invalid values found), and the enum is exactly
    equal to Stage 2 `domain-model.md` §2.2's canonical catalog (§28).
  - **(B) Reverse** — every one of the 17 canonical capabilities now
    resolves to either a whole-endpoint `x-capability` or a documented
    field-level condition (§4): 14 have a direct `x-capability` (this
    grew by exactly the two capabilities pass 3 gave operations to —
    `SALE_VOID_APPROVE`, `SALE_REFUND_APPROVE`), and `PRICE_OVERRIDE`/
    `DISCOUNT_OVERRIDE`/`CASH_OUT` are pre-existing, documented
    field-level conditions inside `saleFinalize`/`shiftCashMovementCreate`
    (§4). **Direction A alone, run in isolation, would never have caught
    the `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` gap** — it only proves
    the contract invents nothing, never that the contract is complete.
- `AccumulatedMoney` accepts `0.00`, `9999999999.99` (RMO 24-2023's
  12-digit minimum), and `9999999999999999.99` (the actual `NUMERIC(18,2)`
  maximum, corrected in pass 3 — pass 2 had mislabeled
  `99999999999999.99`, a precision-16 value, as the ceiling) — verified
  with boundary tests, not just formatting checks — and correctly rejects
  a negative value, a value exceeding that capacity, and any value not
  matching exactly 2 decimal places.
- Audit/journal resources expose no mutating operation.

**Example schema validation (item 9 — genuinely new in pass 1, extended
every pass since):** the original draft only confirmed examples *parse
as JSON*. Pass 1 built a `jsonschema` (Draft 2020-12)-based validator
that resolves `$ref`s directly against `openapi.yaml` and checks each
example object against its actual component schema, covering 17 example
objects. Pass 2 added an 18th — `invoice-reprint-example.json`
(`InvoiceReprintResult`). Pass 3 added eleven more, across two new files
— `void-approval-workflow.json` (5 objects: request, discover, approve,
reject, 409 conflict) and `refund-approval-workflow.json` (6 objects,
the same shape plus the execution-time-condition case) — demonstrating
the full request→discover→approve/reject sequence end to end. Pass 4
added six more, in a new file, `approval-stale-context-scenarios.json`.
**Pass 5 corrected that file** (removing `void-approval-workflow.json`'s
now-invalid "system-auto-reject" example, since that behavior no longer
exists, and reworking the stale-context file into five explicit
scenarios — A/B/C/D/E, §12.1's table — that distinguish permanent
eligibility failures from transient operational ones and never show an
automatic rejection). **All 36 validate cleanly.** Pass 2 also fixed
five example files that had been using an `"INV-000218"`-style prefixed
value for `invoice_number` — inconsistent with the tightened
`^[0-9]{6,}$` pattern and with `transaction_number`'s own, separate
`"T-000482"`-style prefix namespace (§17).
Two arithmetic inconsistencies were also caught and corrected in the
shift-close/Z-Reading example while building this validation (an
`expected_cash`/`variance` mismatch, and a `gross_sales` figure that
incorrectly included a voided sale) — fixed to reconcile exactly.

**Result: zero errors** across structural validation, the 20+ semantic
checks, all 36 example-schema validations, and the capability-coverage
and `AccumulatedMoney`/`invoice_number` boundary tests. The validation
scripts (structural, example-schema, capability-coverage, plus the
AccumulatedMoney/invoice_number boundary-test scripts) are retained in
this session's scratchpad, not the committed repo — a permanent CI
validation step remains a Stage 6 concern.

## 26. Domain-traceability findings (Pass B)

Walked every operation back to its Stage 2 source again, including the
newly-added X/Z-Reading, FiscalInstallation, and capability schemas (full
detail in [operation-inventory.md](operation-inventory.md)'s enum-
reconciliation table, X/Z crosswalks, and this document's §21
traceability matrix). **No domain contradiction was found.** The gating
check for this entire remediation pass — X-Reading cardinality — was
verified directly against the frozen text of `domain-model.md`,
`invariants.md`, and `erd.md` (quoted in full at the top of §13) and
confirmed already correct; nothing needed to change there, only to be
stated explicitly.

**Pass 3 addendum — the mandatory gating check before adding any
operation:** before writing `voidApprove`/`refundApprove`/etc., the
question was whether Stage 2 actually contains a request/approval
lifecycle at all, per the explicit instruction to stop and report rather
than invent one. Verified directly against the frozen text of
`state-machines.md` §2/§3: both diagrams define real `REQUESTED →
APPROVED`/`REQUESTED → REJECTED` transitions driven by a distinct
"manager/admin approves"/"rejects" actor, separate from the
already-implemented "immediate" case. **The lifecycle already exists in
Stage 2 — nothing was invented.** The eight new operations implement
transitions the frozen state machine already specifies; §12.1 documents
the interpretive calls made along the way, disclosed as judgment calls
rather than presented as frozen text: the atomic
approve-executes-immediately design (pass 3, still valid), and — after
pass 5's correction — the fact that Void and Refund now share the same
failure-handling shape (fail safely, leave `REQUESTED`) rather than the
"Void-vs-Refund execution-time-failure asymmetry" pass 3/4 had
originally described. That asymmetry existed only because pass 3/4
carried forward frozen text (`state-machines.md` §2's auto-reject
wording) that pass 5 itself found to be the actual defect — see §28's
pass 5 entry.

## 27. Architecture-traceability findings (Pass C)

Every financially-sensitive operation's error/conflict behavior re-checked
against architecture.md's transaction boundaries, lock order, and
idempotency design (§21's matrix), now including the finalized capability
model and the AccumulatedMoney/structured-reading schemas. **No
architectural contradiction was found.** The Global Lock Order and the
four Void/Refund-vs-Z-Reading/Shift-Close race resolutions
(architecture.md §24) map cleanly onto this contract's conflict codes
without needing a new lock, constraint, or transaction boundary. **Pass
3 addendum:** `voidApprove`/`refundApprove` execute the same
lock-ordered, terminal-scoped transaction shape as the original
`saleVoid`/`saleRefund` execution path (`shift → fiscal_day → sale →
sale_item → invoice_series`) — they are the *same* execution logic,
reachable from a second call site, not a new one, so no new lock
ordering or race condition is introduced.

## 28. Frozen domain rule compliance

**Pass 1: no edit was made to `domain-model.md`, `invariants.md`,
`state-machines.md`, `erd.md`, `architecture.md`, or any ADR** — confirmed
by re-reading the gating X-Reading check (§13) before touching anything
else. The capability-catalog addition (§4) and the FiscalDay auto-open
policy (§10) were both treated as Stage 4 contract-layer decisions, not
domain edits: the capability catalog's Stage 2 §2.2 text in
`domain-model.md` itself was **not** edited in pass 1 (the six new
capabilities existed only in `openapi.yaml`'s `Capability` enum and this
document), and FiscalDay auto-open was a decision Stage 2/3 explicitly
deferred, not a change to either.

**Pass 2: one disclosed exception.** On owner review, that gap was judged
not acceptable to carry forward — a fixed enum of authorization
vocabulary belongs to exactly one canonical source, and `openapi.yaml`
having six capabilities Stage 2 didn't recognize meant the contract had
silently become the more complete source of truth. `domain-model.md`
§2.2 was amended (see "Capability catalog synchronization" below) to add
the same six names, each with the one-line purpose comment already used
for the original eleven. This is the **only** Stage 2 edit in this
remediation, it is additive-only (no capability removed or renamed, no
role structure changed, no financial invariant touched), and it is
committed as its own commit separate from any Stage 4 file — see the
final report for the commit hash and its effect on `stage-2-baseline`.
The FiscalDay auto-open policy is unaffected by this pass.

**Pass 3: no Stage 2/3 document edited.** The eight new operations
implement transitions `state-machines.md` §2/§3 already define — see §26
for the gating check performed before writing them. `AccumulatedMoney`'s
corrected ceiling is a Stage 4 contract-only fix (the frozen
`NUMERIC(18,2)` storage decision in `architecture.md` §3.1 was already
correct; only the API schema's claim about it was wrong).

**Pass 4: no Stage 2/3 document edited.** The approval-is-execution
determination was a verification against existing frozen text
(`state-machines.md` §2/§3, `invariants.md` #24), not a change to it; the
journal-entry and `RefundSummary` corrections were both Stage 4
contract-only fixes.

**Pass 5: one disclosed exception — a real correction, not additive
vocabulary.** Unlike pass 2's capability-catalog sync (additive-only,
new vocabulary), this amendment changes actual **behavior** described in
frozen text: `state-machines.md` §2's Void lifecycle notes previously
said a failed execution-time eligibility recheck moves the void to
`REJECTED` ("system-rejected, with a reason recorded"). On owner review,
this was judged to conflate two different things — a failed *attempt* to
execute a fiscal adjustment, and an authorized *decision* that the
request should never proceed — and was corrected: the void now stays
`REQUESTED` on a failed recheck, resolved only by an explicit reject or a
later successful approval. This is committed as its own commit, separate
from any Stage 4 file — see the final report for the commit hash. Because
this changes lifecycle *behavior* (not just naming/vocabulary), it is
treated with more caution than pass 2's sync: `stage-2-baseline` is
**not** moved onto this commit automatically — that decision is left
explicitly to the owner, alongside the report of this pass's findings.

## 29. Unresolved questions

1. ~~**Capability catalog: contract vs. domain-doc sync.**~~ **Resolved
   in remediation pass 2.** `domain-model.md` §2.2 now lists all 17
   capabilities, matching `openapi.yaml`'s `Capability` enum and this
   document's role→capability table exactly (see §28).
2. **FiscalInstallation accreditation/PTU database shape** (§16) —
   explicitly and intentionally left to Stage 5 (two column-pairs vs. two
   child tables); the HTTP contract's `accreditation`/`permit_to_use`
   nested-object shape does not foreclose either path and needs no
   further Stage 4 decision.
3. **CSV export beyond the 15 reports + journal** — `productExport`'s CSV
   column contract was not included in
   [csv-export-contract.md](csv-export-contract.md) (it's a full-catalog
   dump matching `ProductInput`'s fields, considered lower-risk than the
   fiscal/report exports); worth a one-line addition at Stage 6 if a store
   owner's tooling ends up depending on its exact column order too.

Session/inactivity timeout duration and the exact FiscalInstallation
database shape are explicitly **not** treated as blocking questions per
the owner's own framing — both are operational/implementation tunables,
not public contract ambiguities.

## 30. BIR-REVIEW-REQUIRED items carried into Stage 4

No **new** `BIR-REVIEW-REQUIRED` item was introduced by this remediation
pass. Existing ones remain unaffected: the unexplained-missing-serial
compliance treatment (ADR-004), and each store's own EIS/e-invoicing
applicability determination (BIR-008), both out of this contract's scope
per §23.

## 31. Risks

- ~~The capability-catalog domain-doc sync gap~~ **Closed in remediation
  pass 2** — `domain-model.md` §2.2 now carries the full 17-capability
  catalog, so Stage 6 has one authoritative source, not two documents
  that must be read together.
- ~~`SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` had no operation~~
  **Closed in remediation pass 3** — see §12.1.
- The Refund-idempotency and Void-idempotency requirements depend on
  Stage 6 actually implementing the generalized idempotency-record shape
  from ADR-010 consistently across all fourteen mandated operations — a
  real implementation-discipline risk to watch during Stage 6, not a
  contract-design gap.
- ~~`AccumulatedMoney`'s "no artificial cap" property~~ **Corrected in
  remediation pass 3** — the schema is no longer uncapped; it's capped at
  exactly `NUMERIC(18,2)`'s own maximum (`9999999999999999.99`), so the
  contract can no longer promise a value the storage layer would reject.
  Boundary-tested: `0.00`, `9999999999.99` (RMO 24-2023 floor), and
  `9999999999999999.99` (the actual ceiling — pass 2 had tested a
  precision-16 value and mislabeled it the ceiling) all accepted; a
  negative value, a value exceeding the ceiling, and a value without
  exactly 2 decimal places all correctly rejected. Stage 5 must still
  actually implement `NUMERIC(18,2)` for the backing columns — this
  confirms the *schema*'s promise is now honest, not that Stage 5's
  columns exist yet.
- ~~The "documented, deliberate asymmetry between Void and Refund at
  execution-time-failure"~~ **Retired in pass 5** — it was never a real
  design asymmetry, it was pass 3/4 faithfully carrying forward a Stage 2
  defect (§28's pass 5 entry). Void and Refund now share the same
  failure-handling shape.
- **New (pass 5):** the retention/lifecycle of a *failed, non-committing*
  `idempotency_record` (does the row get deleted on rollback, or kept
  with a null `result_resource_id` forever?) is a genuine Stage 5/6
  implementation-shape question ADR-010 doesn't explicitly answer for
  this case — this contract's clarification ("the same key may be
  reused after a failed attempt") describes the *required behavior*, not
  a specific storage mechanism; Stage 5/6 must implement whatever
  storage shape actually delivers that behavior.
- **New (pass 5):** Void's originating-fiscal_day permanent-ineligibility
  case (scenario B) currently has no operator-facing signal beyond a
  `409` on each retry attempt — a store owner needs a way to notice a
  permanently-stuck `REQUESTED` void and reject it explicitly; this is a
  Stage 7 (frontend) UX concern, not a Stage 4 contract gap (the
  `voidList?status=REQUESTED` endpoint already exposes exactly this
  list), but worth flagging so it doesn't silently fall through the
  cracks between stages.

## 32. Stage 4 exit criteria

- [x] OpenAPI validates, including the nullable/JSON-Schema-2020-12 fix (§25).
- [x] Every V1 module has its necessary API surface (14 tags, 86 operations — §24, +8 in pass 3 for Void/Refund approval).
- [x] Sale finalization is atomic by contract semantics (§11).
- [x] Terminal identity cannot be spoofed through normal payload fields (§3, §22).
- [x] Idempotency is explicit and its exact 14-operation list cross-checked programmatically (§5, [operation-inventory.md](operation-inventory.md); 10 → 14 in pass 3).
- [x] Money/Quantity are represented safely, plus AccumulatedMoney capped at exactly the `NUMERIC(18,2)` maximum (`9999999999999999.99`) while still clearing the RMO 24-2023 12-digit floor, boundary-tested at `0.00`/`9999999999.99`/`9999999999999999.99` (§8, §25, §31 — ceiling corrected pass 3).
- [x] RefundSettlement is represented (§12).
- [x] Processing context is represented (§12).
- [x] X/Z responsibilities are coherent, cardinality re-verified against frozen Stage 2 text, full BIR field crosswalks published (§13, [operation-inventory.md](operation-inventory.md)).
- [x] Invoice and transaction identifiers are distinct, invoice_number confirmed STRING, digits-only, leading-zero-preserving pattern (`^[0-9]{6,}$`, tightened pass 2 — §17).
- [x] Invoice reprint occurrence state (`is_reprint`/`reprinted_at`/`requested_by`) lives on `InvoiceReprintResult`, never on the immutable `InvoiceDetail` resource (pass 2 correction — §17).
- [x] All domain enums reconcile, including the new `ElectronicJournalEventType` ([operation-inventory.md](operation-inventory.md)).
- [x] No frozen invariant has been silently changed. The capability catalog gap was closed by amending Stage 2 (§28) — disclosed, additive-only, committed separately; `stage-2-baseline` has since been moved onto that amendment (`11d594f`) per explicit owner approval. A second, disclosed Stage 2 amendment in pass 5 corrects `state-machines.md`'s Void auto-rejection wording (see below) — `stage-2-baseline`'s move to that commit is pending explicit owner approval, not yet made automatically.
- [x] `SALE_VOID_APPROVE`/`SALE_REFUND_APPROVE` are now reachable — eight new operations implement the frozen `REQUESTED → APPROVED/REJECTED` state machines exactly, added only after confirming that lifecycle already exists in Stage 2 rather than inventing one (§12.1).
- [x] **Approval-is-execution, verified against frozen text, not inferred (pass 4, §12.1):** `APPROVED` is not a separately-effective resting state — Stage 2's diagrams collapse it into system execution in the same breath, and invariant #24 names no `SALE_VOID_APPROVED` event. `voidApprove`/`refundApprove` are confirmed fiscal execution commands.
- [x] **Failed approval is not automatic rejection (pass 5, §12.1):** a failed execution attempt (any condition, for either Void or Refund) leaves the request `REQUESTED`, unchanged, with a stable `409`/`422` — never an automatic `REQUESTED → REJECTED`. `state-machines.md`'s Void notes, which previously said otherwise, are corrected. `REQUESTED → REJECTED` is reached only through the explicit reject commands. Void's originating-fiscal_day condition is documented as a **permanent** gate; Refund has no such condition; the executing-terminal shift/fiscal_day condition is **transient** for both — proven with five stale-request example scenarios (A–E).
- [x] Capability→operation coverage checked in **both** directions: every `x-capability` ⊆ `Capability` enum, and every canonical capability has an operation or a documented field-level reason (§4).
- [x] Security review is clean (§22).
- [x] Operation inventory matches openapi.yaml (86/86, cross-checked programmatically — §25).
- [x] Examples validate **against their actual schemas** via a JSON Schema validator (36/36, including the new `InvoiceReprintResult`, Void/Refund approval-workflow, and stale-context-scenario examples), not merely parse as JSON (§25).
- [x] CSV export column stability closed for all 15 reports + journal export ([csv-export-contract.md](csv-export-contract.md)).
- [x] Capability catalog closed — 6 new capabilities added to `openapi.yaml` in pass 1 **and synced into Stage 2's canonical catalog** in pass 2; every `x-capability` value verified to belong to both (§4, §28).
- [x] FiscalInstallation HTTP shape unambiguous — Accreditation/PermitToUse as distinct nested objects, database shape correctly left to Stage 5 (§16).

## 33. Proposed Stage 5 plan

Design PostgreSQL migrations and constraints module-by-module, in the same
dependency order as ADR-001's module list: Identity & Authorization →
Catalog → Inventory → Fiscal (tax_registration, fiscal_installation,
terminal_fiscal_installation) → Cashier/Shift (shift, fiscal_day,
x_reading, z_reading, cash_movement) → Checkout/Sales (sale, sale_item,
payment, invoice_series, invoice) → Void/Refund (void, refund,
refund_item, refund_settlement) → Audit (audit_event,
electronic_journal_entry). Prioritize the checkout path's constraints
first (invoice_series row-locking, the idempotency unique index, the
partial unique indexes for shift/fiscal_day/void) since Stage 3's
concurrency guarantees depend entirely on these existing exactly as
specified — a migration review should treat ADR-003/004/010's lock/
constraint list as a literal checklist, not a general guideline.
**Two additions from this remediation pass:** `z_reading.totals_snapshot`'s
`accumulated_grand_total_sales_before`/`_after` columns must be
`NUMERIC(18,2)` (or wider), never `NUMERIC(12,2)`, to actually deliver on
`AccumulatedMoney`'s contract promise; and the `x_reading`/
`z_reading.totals_snapshot` JSONB payloads should be validated at write
time (application-layer, not a DB constraint) against the
`XReadingTotalsSnapshot`/`ZReadingTotalsSnapshot` shapes this contract now
defines, so the stored snapshot and the API response are guaranteed to
agree rather than drifting apart silently.
