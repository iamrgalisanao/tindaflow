# Stage 15 — Sales history, void and refund

## Status

**Done and tested**, backend and admin screens. **No change to the frozen contract**: all eleven
operations were already in `openapi.yaml` and every failure maps onto a code already in
`error-catalog.md` (including `SALE_NOT_FOUND`, `VOID_NOT_FOUND`, `REFUND_NOT_FOUND`), so nothing
was forward-committed and no baseline tag moved. `scripts/validate-baselines.sh` stayed green.

## 1. Scope

| Operation | Route | Who |
|---|---|---|
| `saleList` | `GET /sales` | any session in the store; filters `from`/`to`, `invoice_number`, `transaction_number`, `cashier_id`, `terminal_id`, `product_id`, `payment_method`, `status`, `sort` |
| `saleGet` | `GET /sales/{id}` | any session in the store |
| `saleVoid` | `POST /sales/{id}/void` | `SALE_VOID` + enrolled terminal |
| `saleRefund` | `POST /sales/{id}/refunds` | `SALE_REFUND` + enrolled terminal |
| `voidList` / `voidGet` | `GET /voids`, `/voids/{id}` | any session (`status`, `sale_id`, `from`, `to`) |
| `voidApprove` | `POST /voids/{id}/approve` | `SALE_VOID_APPROVE` + enrolled terminal |
| `voidReject` | `POST /voids/{id}/reject` | `SALE_VOID_APPROVE`, **no terminal** |
| `refundList` / `refundGet` | `GET /refunds`, `/refunds/{id}` | any session |
| `refundApprove` | `POST /refunds/{id}/approve` | `SALE_REFUND_APPROVE` + enrolled terminal |
| `refundReject` | `POST /refunds/{id}/reject` | `SALE_REFUND_APPROVE`, **no terminal** |

Everything is scoped to the actor's store; a record of another store is indistinguishable from a
missing one (`404`). Unknown filter values match nothing rather than being ignored.

## 2. How it works

- **Request, then execute.** A user who already holds the approve capability (ADMIN, MANAGER)
  executes in the same call (`REQUESTED → APPROVED → VOIDED/COMPLETED`); anyone else (CASHIER)
  only records a request that a manager approves later. `sale.status` stays `COMPLETED` while a void
  is `REQUESTED`.
- **Approval is execution, in the approver's context.** `voidApprove`/`refundApprove` (and the
  immediate path) resolve the *executing* terminal's own open fiscal day and the *executing user's*
  own open shift at that terminal, never the requester's or the sale's (invariants #67–#69). A
  missing one is `409 SHIFT_NOT_OPEN` / `FISCAL_DAY_CLOSED` (the codes the contract names for these
  operations; checkout's `SHIFT_REQUIRED` is a different operation's code).
- **A failed attempt changes nothing.** Everything runs inside `IdempotencyService`'s transaction, so
  any refusal rolls back the void/refund, its stock movements, audit and journal rows and the
  idempotency reservation together. The record stays `REQUESTED` (never auto-rejected) and the same
  key may be reused once the blocking condition is fixed. Tests prove this per cause.
- **Void eligibility** (domain-model §2.8), re-checked at execution: sale is `COMPLETED`, the sale's
  *own* fiscal day is still `OPEN`, no `COMPLETED` refund exists, a reason is given. A voided sale is
  `409 SALE_ALREADY_VOIDED`; the rest is `409 SALE_NOT_VOIDABLE` with a plain-language reason.
- **Void reverses everything**: one `SALE_RETURN` movement per line for the full quantity, and
  `sale.status → VOIDED`; no original row is edited (invariants #22/#23).
- **Refunds derive their own amounts.** No amount is accepted per line. Each line's amount is the
  cumulative-recompute rule over the sale line's frozen `net_line_amount` (`RefundCalculator`), so a
  line returned across several refunds always sums to exactly `net_line_amount` (DISC-005; proven with
  a discounted line refunded one unit at a time: 50.00, 49.99, 50.00). The settlements must add up to
  that derived total (`REFUND_SETTLEMENT_MISMATCH`), and quantities are capped by every `COMPLETED`
  refund of the line (`REFUND_EXCEEDS_REMAINING_QUANTITY/AMOUNT`).
- **Approval re-derives everything** against refunds completed since the request; if a sibling
  refund took the quantity first it fails `422`, stays `REQUESTED`, and a human can reject it.
- **Stock**: only `RETURN_TO_STOCK` lines put stock back (invariant #30); `DAMAGED`/`EXPIRED`/
  `DISPOSED` do not. Returns go back to the location the original `SALE` movement came out of (so
  per-location balances stay equal to the ledger even if the default location changed), falling back
  to the default location for a sale with no recorded movement. Movements are written through
  `StockLedger`, so `stock_balances` moves in the same transaction.
- **`sale.status` after a refund** is recomputed from the completed refunds every time:
  `REFUNDED` when every line's quantity has been returned, otherwise `PARTIALLY_REFUNDED`.
- **Audit and journal**: `SALE_VOID_REQUESTED`, `SALE_VOIDED`, `SALE_VOID_REJECTED`,
  `REFUND_REQUESTED`, `REFUND_CREATED`, `REFUND_REJECTED` (all in the frozen event catalog), and one
  `VOID`/`REFUND` journal entry per execution. Rejections write an audit event only, never a journal
  entry. No `…_APPROVED` event is written, consistent with the contract's statement that approval is
  not its own fiscal moment.
- **Concurrency**: the frozen lock order (shift → fiscal day → sale → sale items ascending) is
  followed. A void also has to see the *original* sale's fiscal day, which may be another row than the
  executing terminal's; both are locked together in ascending id order so two voids executed from
  different terminals against each other's days cannot deadlock. `SalesReversalConcurrencyTest` races
  real OS processes: two approvals for the same line never refund more than was sold, and a void and a
  refund racing for one sale never both take effect.

## 3. Decisions the frozen documents left open

1. **Where a pending refund's lines live.** `refund_item`/`refund_settlement` rows are append-only and
   invariant #72 forbids settlement rows for a refund that is not `COMPLETED`, yet the contract says
   the requested lines are "recorded" at request time and created at approval. The schema has no place
   for a pending payload, so it is kept on the immutable **`REFUND_REQUESTED` audit event**
   (`after_metadata`), read back at approval (`Refund::requestedPayload()`). `refunds.refund_total`
   holds the request-time derived total. While pending or rejected, `RefundResult.items` and
   `.settlements` are shown from that record with `id` (and `processed_at`) `null`, so an approver can
   see what is being asked for; they become real rows on completion.
2. **Reject operations and idempotency.** `voidReject`/`refundReject` require an `Idempotency-Key`, but
   idempotency is scoped `(terminal_id, key)` and the same operations are defined as needing **no
   terminal**, so the two frozen statements cannot both hold. The key is still required (`400` when
   missing), but a retry is made safe by state instead: repeating the identical rejection (same user,
   same reason) returns the recorded result and writes nothing further; any other call against a
   decided request is `409 …_NOT_PENDING_APPROVAL`. Known limit: the same key with a *different* body
   cannot be detected as `IDEMPOTENCY_KEY_REUSED` (there is nowhere to store it). No schema change was
   made (it would be a reconstruction).
3. **`VoidSummary.sale_id`** is emitted although the schema omits it (`RefundSummary` has it). Without
   it the approvals queue cannot say which sale a pending void is about without one request per row. An
   additive response field; `openapi.yaml` is untouched and should gain it at the next contract change.
4. **`SaleDetail.void`** is the void that took effect, otherwise the one still waiting; rejected
   attempts are history, reachable through `GET /voids?sale_id=`.
5. **Duplicate pending requests are allowed** (nothing forbids them). Approving one that can no longer
   proceed (e.g. a second void after the first succeeded) fails and can be rejected.
6. **`REFUND_NOT_ALLOWED`** also stops approving a pending refund whose sale has since been voided.
7. Voids and refunds restore stock for untracked products too, mirroring checkout, which records a
   `SALE` movement for every line.

## 4. Admin screens

New **Sales** sidebar section (`SALE_VOID`, which all three roles hold; an **Approvals** link only for
`SALE_VOID_APPROVE`) and Dashboard link:

- `/admin/sales` — history with every contract filter, sort, server-side pagination, table on `md+`
  and cards on phones.
- `/admin/sales/:saleId` — items, totals with the tax breakdown, payments, refunds, any void, and
  **Refund** / **Void sale** buttons. Both open a slide-over and say up front whether the action takes
  effect now or goes to a manager. A void asks only for a reason. The refund panel shows what is left on
  each line (from earlier refunds), asks for a quantity and an explicit disposition per line (nothing
  defaults to "back on the shelf"), previews the amount with the same cumulative rule in exact integer
  arithmetic, pre-fills the settlement, and supports splitting across methods. A dropped connection
  retries with the same idempotency key; editing anything issues a new one.
- `/admin/sales/approvals` — Voids / Refunds, Waiting / All. A review panel shows the sale, the reason,
  the requested lines by product name and the money split, then **Approve** (carried out at this
  browser's terminal) or **Reject** with a reason. A refused approval explains why and that the request
  is still waiting.
- The shell now supports a capability per sidebar link.

No Stitch mockup was generated: the screens reuse the established admin patterns. The sitemap's
`/back-office/void-refund/:id` and `approvals` routes are realised as the panel and page above.

## 5. Verification

- New tests: `RefundCalculatorTest` (9), `SaleVoidHttpTest` (20), `SaleRefundHttpTest` (21),
  `SalesHistoryHttpTest` (6), `SalesReversalConcurrencyTest` (2, real processes). They cover both
  paths (immediate and request-then-approve), execution in the *approver's* terminal/shift/fiscal
  day, full and partial reversal, dispositions, stock and balances, audit and journal rows, every
  refusal leaving the record untouched and the key reusable, idempotency (missing, replay, conflict),
  validation, cross-store isolation, access rules and read filters.
- Full regression: Unit 111 + Feature 1 = 112 (103 before), Database 421 (372 before),
  Pint clean, baseline invariants hold.
- Browser (desktop and phone 375): history list and detail; a void refused because the sale's fiscal day
  had closed (with the explanation); a refund of that sale after closure (allowed); an immediate void;
  a partial refund and reopening the panel ("2 left"); approving and rejecting pending voids and
  refunds filed by a cashier; the duplicate request refused after its sibling completed; no horizontal
  overflow. `inventory:rebuild-balances --dry-run` on the dev database reported every balance equal to
  its ledger after all of it. The verification cashier was deactivated; the dev database keeps the
  test sales, voids and refunds.
- Not exercised in a browser: signing in as a cashier (the app does not type passwords; a cashier's
  requests were filed through the services), and the not-enrolled banners.

## 6. Not built

Invoice reprint and the POS-side "find the receipt" flow, names instead of short ids for cashier and
requester (a manager cannot list users), a pending-approvals count on the dashboard, audit log and
electronic journal screens, product CSV import/export, barcode lookup, and the deferred shift and
fiscal-day read endpoints.
