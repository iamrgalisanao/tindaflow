# Stage 28 — Stock writes in one canonical order

## Status

**Done and tested.** A **recorded exception to the stage-6c freeze**, approved by the owner (2026-09-21): this stage edits
`app/Services/Checkout/CheckoutService.php`. No `stage-*-baseline` tag moved (the tags still point at their frozen commits;
`scripts/validate-baselines.sh` stayed green), no contract change, no new error code. It follows the Stage 13 precedent of a
forward commit to a frozen area with the reason on record. It builds on stage 27 (a lost race is a retryable 409), which
stays as the fallback.

## 1. The invariant

> Every transaction that writes more than one stock balance row must write them in the canonical stock order: by
> `location_id`, then `product_id`, then the order the caller listed them in. All of a transaction's stock movements are
> handed to `StockLedger::recordMany()` in one call.

Each stock write holds its `(product, location)` balance row's lock until the transaction ends, so two transactions that
take the same two rows in opposite orders deadlock and PostgreSQL aborts one. PostgreSQL's own documentation names a
consistent lock order as the primary defence and a retry as the fallback (stage 25 doc, section 11). The order is by stable
database identity, never by name, barcode, scan order or anything a person can edit.

The ordering lives in **one place**. Before this stage the count and transfer services each sorted privately and checkout,
void and refund did not sort at all.

| Writer | Now |
|---|---|
| Checkout (SALE, one per sale line) | one `recordMany` call (**the frozen-file edit**) |
| Void (SALE_RETURN, one per line) | one `recordMany` call |
| Refund (SALE_RETURN for RETURN_TO_STOCK lines) | lines are created first, then one `recordMany` call |
| Stock transfer (OUT and IN legs) | one `recordMany` call (replaces its private sort) |
| Stock count post (one adjustment per line) | one `recordMany` call (replaces its private sort) |
| Receipt, adjustment | a single row each, so they cannot form a cycle on their own; they use `record()` |

`record()` stays for a single movement and its docblock says so; a caller that loops over it in its own order breaks the
invariant. `RebuildStockBalances` is a maintenance repair that rewrites balances from the ledger and is out of scope.

## 2. Duplicate lines

The owner asked that duplicate stock lines be aggregated before locking (scan Coke, Chips, Coke, Coke becomes Coke x3, Chips
x1). The **movements are not merged**: each belongs to one sale line, refund line or count line, and a void or refund
restores exactly what each line took, so merging would lose that traceability. What the aggregation is for, taking each row's
lock once and in order, is achieved another way: after sorting, movements of the same row sit next to each other, the first
write takes the row lock and the later ones reuse it. If a merged, one-movement-per-product ledger is wanted, that is a
separate decision about the ledger's granularity.

## 3. What the tests found, and a correction to earlier stages

`StockWriteOrderConcurrencyTest` drives the real `CheckoutService` and `StockTransferService` in separate OS processes
released together:

- **Two terminals on different fiscal installations and invoice series**, four checkouts scanning A then B against four
  scanning B then A: all eight succeed with the ordering, and the test **fails on 3 of 3 runs with the sort disabled**.
- **A checkout against a transfer** that take the same shelf rows in opposite orders: all eight succeed with the ordering,
  and the test **fails on 3 of 3 runs with the sort disabled**.
- **Control:** with the sort still disabled, checkouts that **share one invoice series** did **not** deadlock in 3 of 3 runs.
  Checkout takes the invoice series row lock during numbering and holds it to commit, before it writes stock, so checkouts
  on one series run one at a time.

So the risk I described in stages 25 and 27 ("two concurrent sales with the same products in opposite order can deadlock")
was **overstated for checkout against checkout**. It needs checkouts that do **not** share a series (each terminal on its
own fiscal installation, which is likely when every machine holds its own permit to use), or a checkout against any other
stock writer: a count, transfer, void or refund, none of which hold the series lock. That is exactly why the invariant
covers every writer and not checkout alone. The stage 25 and 27 documents are corrected by this section.

`StockLedgerOrderingTest` proves the ordering at the ledger itself: the write order follows location then product whatever
the input order, every permutation gives the same order, the movements come back in the order given, same-row movements are
not merged, and the balances still equal the ledger (it fails with the sort disabled).

## 4. What did not change

The movements written, their references, the audit events, the journal entries, the balances and every response are
identical; only the order in which a transaction touches the balance rows changed. The 117 existing checkout, void, refund,
sale, count, transfer and inventory tests pass unchanged. Stage 27's `409 CONCURRENCY_CONFLICT` and the client's single retry
stay as the fallback for any cycle ordering cannot prevent. Automatic retries still require an `Idempotency-Key`, so a retry
after a network failure that follows a commit returns the first sale instead of creating a second.

## 5. Not built

Merging movements per product; a server-side retry of the whole checkout transaction (a frozen edit in
`app/Services/Idempotency/`); other multi-row locks that are not stock rows (they are covered by `GlobalLockOrder`).
