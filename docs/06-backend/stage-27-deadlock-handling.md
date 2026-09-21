# Stage 27 — A lost database race is a retryable 409, not a 500

## Status

**Done and tested**, backend and the shared API client. **No frozen file touched, no contract change, no new error code,
no baseline tag moved**; `scripts/validate-baselines.sh` stayed green. This is "option 1" of the checkout-deadlock research
in `docs/06-backend/stage-25-stock-counts-and-transfers.md` section 11. It removes the symptom (a failed sale answered with
a 500) but **not the cause**: see "What this does not fix".

## 1. The problem

`CheckoutService` writes stock balance rows in cart order, and stock rows are outside `GlobalLockOrder`. Two concurrent
sales containing the same two products in opposite order can each hold one row and wait for the other, and PostgreSQL
aborts one of them (SQLSTATE `40P01`). Nothing in the application handled that error: the existing
`DatabaseExceptionTranslator` (Stage 6A, frozen, and not wired into any service) maps only unique, foreign-key and check
violations, so the aborted request surfaced as a **500 at the till**. The transaction was rolled back and every write that
matters carries an `Idempotency-Key`, so sending it again is safe; only the answer was wrong.

## 2. What was built

| Piece | Behaviour |
|---|---|
| `App\Services\Database\ConcurrencyFailure` | Recognises a database error that means "you lost a race": **SQLSTATE `40P01` (deadlock) or `40001` (serialization failure)**, Laravel's `DeadlockException` (raised when a deadlock happens inside a nested transaction, where Laravel cannot retry), and, as a fallback only, Laravel's own message-based detector. The SQLSTATE is checked first because it does not depend on the server's language; Laravel's detector matches the English text "deadlock detected" |
| `bootstrap/app.php` exception handler | Renders such an error as the **existing `409 CONCURRENCY_CONFLICT`** in the standard envelope, with `details.retryable: true` and a `Retry-After: 1` header. Nothing from the driver (SQL, bindings, message, SQLSTATE) reaches the client; the SQLSTATE, request id and path go to the log. **Every other database error is still a 500** |
| `resources/js/api.js` | `apiFetch` retries **once**, after a random 250 to 600 ms pause, a write that carries an `Idempotency-Key` and came back `409 CONCURRENCY_CONFLICT`, **with the same key**. A write without a key is never retried, another 409 code is never retried, and a second conflict is returned to the screen. The POS, void, refund, reprint, stock, count and transfer screens all send their keys through `apiFetch`, so all of them gain it. The same retry also covers "this key is still being processed" (`CONCURRENCY_CONFLICT` from the idempotency service): by the retry the first attempt has usually finished and the repeat returns its result |

`CONCURRENCY_CONFLICT`'s catalog entry says it is a "generic current-state race lost against another request", which
is what this is; `error-catalog.md` is frozen and not edited.

## 3. Verification

- `ConcurrencyFailureTest` (6, unit): 40P01 and 40001 by SQLSTATE, a non-English message still recognised, the English
  message as a fallback, the nested-transaction `DeadlockException`, and unique, foreign-key, check, undefined-table and
  cancelled-query errors **not** mistaken for a lost race.
- `DeadlockResponseTest` (4, feature): a deadlock and a serialization failure return `409` in the envelope with
  `CONCURRENCY_CONFLICT`, `retryable` and `Retry-After`; **no SQL, binding, table name or SQLSTATE appears in the body**; any
  other database error is still a 500.
- `RealDeadlockClassificationTest` (1, real PostgreSQL): two OS processes with their own connections reach for each
  other's row, PostgreSQL aborts one, and the error it raises is a Laravel `QueryException` with SQLSTATE `40P01` that the
  application recognises. This is the check that the hand-built exceptions in the unit tests have the real shape. It passed
  3 of 3 runs, whichever side PostgreSQL chose as the victim (that choice is its own, "difficult to predict").
- The retry in `api.js` was run in Node against a stubbed `fetch`: conflict then success is retried once with the same key,
  a second conflict is returned, a write without a key, another 409 code, and a success are all left alone. The frontend
  builds. **Not driven end to end in a browser against a real conflict**, because a genuine deadlock cannot be provoked from
  the screen.
- Full regression and Pint: see the manifest's Stage 27 section for the counts.

## 4. What this does not fix

The **cycle still exists**. Two sales in opposite order still collide; one now retries and (unless it collides again)
succeeds, instead of failing. The cause is checkout writing stock rows in cart order, and removing it means sorting those
writes by `(location_id, product_id)`, which edits `app/Services/Checkout/`, **frozen under `stage-6c-baseline`**. The
research found direct precedent (Saleor, WooCommerce, ERPNext) and the count and transfer services already do it, so it is
the conventional fix; it needs your approval of a recorded exception for the frozen path, as with the Stage 13 forward
commit. A server-side retry of the whole checkout transaction at its outermost boundary (as Odoo does) would also need a
frozen edit, in `app/Services/Idempotency/`, and is not built.

## 5. Not built

Sorting checkout's stock writes (frozen); a server-side transaction retry (frozen); wiring `DatabaseExceptionTranslator`
into services; retrying non-idempotent requests (deliberately never done).

## 6. Update (2026-09-21, stage 28)

The cycle described in section 4 is now prevented: every stock writer, checkout included, writes its balance rows in one canonical order (`docs/06-backend/stage-28-stock-write-ordering.md`). This stage's 409 and the client retry stay as the fallback. **Correction to section 1:** two checkouts that share an invoice series cannot deadlock on stock rows, because checkout holds the series row lock from numbering to commit; the cycle needs checkouts on different series, or a checkout against a count, transfer, void or refund.
