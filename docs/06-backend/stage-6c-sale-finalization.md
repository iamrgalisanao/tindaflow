# Stage 6C — Sale Finalization: Initialization

## Status

**INITIALIZATION — scoping and evidence only. No implementation exists yet.**
Per the owner's explicit instruction, this document establishes the operation
inventory, domain invariants, existing approved evidence, a proposed
file-ownership map, and every gap found that requires an owner ruling —
before any code is written. Nothing here modifies Stages 1–6B's frozen
content. Started from `main` at the post-`stage-6b-baseline` governance
commit (`8af1ca8`), per the Stage Baseline Rule.

---

## 1. Operation inventory

Exactly **one** HTTP operation is in scope for Stage 6C, per the frozen
`docs/05-api/openapi.yaml`:

| Operation | Method/Path | operationId | Contract status |
|---|---|---|---|
| Sale finalization (checkout) | `POST /sales` | `saleFinalize` | Fully frozen: request schema (`SaleFinalizeRequest`), response schema (`SaleDetail`), all error responses (`400/401/403/404/409/422`), and the `Idempotency-Key` header requirement are already specified in Stage 4's contract. Nothing about the wire contract is undecided. |

No other operation is Stage 6C's scope. `docs/06-backend/stage-6a-transaction-foundation.md` and `stage-6b-invoice-series-allocation.md` both explicitly disclaim: "No Sale finalization, no controllers, no printing, no catalog CRUD, no InvoiceSnapshot generation" as Stage 6A/6B scope — confirming those are exactly Stage 6C's (or later) responsibility, and confirming Stage 6C's own scope stops at Sale finalization itself (printing, InvoiceSnapshot generation beyond the one JSON payload ADR-003 already requires, and reprint are separately deferred).

---

## 2. Domain invariants Sale Finalization must enforce

Grouped by area; each must be verifiable, not merely aspired to, once implementation exists.

**Sale lifecycle & authority**
- No persisted intermediate status — a `sale` row does not exist until the transaction commits (invariants.md; state-machines.md §1).
- Immutability after completion — no `UPDATE`/`DELETE` on `sale`/`sale_item`/`payment` once committed.
- Server-authoritative totals — every monetary/tax field is recomputed server-side; a client-submitted total (`client_expected_grand_total`) is compared only for a UI mismatch warning, never persisted as-is.
- Idempotent finalization — enforced by `UNIQUE(terminal_id, idempotency_key)` (ADR-010's corrected scope).
- Atomicity — `sale`, `sale_item`, `payment`, invoice allocation + `invoice`, `stock_movement` (one per line), `audit_event`, `electronic_journal_entry` all in one DB transaction.
- Historical snapshot integrity — every `_snapshot` field and the invoice snapshot JSON reflect state *at finalization*, never recomputed later.
- Server-authoritative payment sufficiency — `SUM(payment.amount) >= grand_total`; `change` computed server-side.
- Explicit fiscal attribution — `sale.fiscal_day_id`/`shift_id` persisted from the terminal's currently `OPEN` fiscal day/shift at finalization, never inferred from `sold_at`.
- A sale requires an open shift **and** an open fiscal day, both matching the sale's `terminal_id` (invariant #35).

**Invoice numbering**
- Exactly one invoice per completed sale, same transaction; no reuse/reassignment/renumbering ever; allocation happens only during authoritative finalization, via `SELECT ... FOR UPDATE`; a failed attempt never consumes a number; series exhaustion fails safely (`INVOICE_SERIES_EXHAUSTED`, never auto-recovers).

**Money / discount / tax**
- No floating point anywhere; rounding only at the seven defined materialization points (domain-model.md §3.1).
- DISC-001..006 — exact allocation sum, allocation immutability, no recomputation from current config, deterministic largest-remainder rounding tied by ascending `line_number`, and `SUM(sale_item.net_line_amount) = sale.grand_total` exactly, by construction.
- Invariant #65 — per-line tax allocation is scoped only to `VATABLE` lines; `VAT_EXEMPT`/`ZERO_RATED`/`NON_VAT` lines always have `tax_amount = 0`.
- Invariant #66 — discount eligibility (`order_discount_eligible`) is explicit per line, never inferred.
- TAX-NV-001..005 — a VAT-registered sale never has `non_vat_sales > 0`; a NON_VAT-registered sale has all four VAT buckets `= 0.00` and `non_vat_sales = grand_total`; never both nonzero.
- Invariant #54 — no sale can be finalized while a store has zero active `tax_registration` rows.

**InvoiceSeries (Stage 6B, called not redesigned)**
- INVSERIES-001..004 — bootstrap semantics, mandatory `fiscal_installation_id` binding, at most one `ACTIVE` series per installation, `ending_number >= starting_number`. Stage 6C's obligation here is purely to *resolve* the correct `fiscal_installation_id` and call the existing allocator — see §5 gap 1.

**Concurrency**
- Global Lock Order for Checkout is frozen exactly: `shift → fiscal_day → invoice_series` (architecture.md's per-operation table). No `sale`/`sale_item` lock needed — the row doesn't exist yet.

---

## 3. Existing approved evidence (call, do not redesign)

Everything below is frozen Stage 1–6B content. Stage 6C's job is orchestration — calling these in the order ADR-003 already specifies — not re-deciding any of it.

**The transaction script itself is already fully specified**, ADR-003, in this exact order:
1. Idempotency check (short-circuit on replay).
2. Lock `shift` → `fiscal_day` → `invoice_series` (`SELECT ... FOR UPDATE`, fixed order, deadlock-avoiding).
3. Re-validate under lock: shift `OPEN` + belongs to terminal/cashier; fiscal_day `OPEN`; fetch each product's current snapshot.
4. Run `FinancialCalculator` (authoritative recomputation).
5. Insert `sale` (status `COMPLETED` from creation), `sale_item` rows, `payment` rows.
6. Allocate invoice number (`InvoiceSeriesAllocator`), insert `invoice`.
7. Insert one `SALE`-type `stock_movement` row per `sale_item`.
8. Insert `audit_event` (`SALE_FINALIZED`) + `electronic_journal_entry` (`event_type='INVOICE'`).
9. Commit. Any failure at 2–8 rolls back the whole transaction; the client retries with the *same* idempotency key.

**Already-built components Stage 6C calls directly:**

| Component | Exact API | Source |
|---|---|---|
| `FinancialCalculator::calculateSale(array $lines, Money $orderLevelDiscountAmount, string $taxRegistrationType): SaleCalculationResult` | Sole authority for all monetary/tax arithmetic (ADR-012). `$lines` must be `SaleLineInput[]` in ascending `line_number` order. | `app/Domain/Financial/FinancialCalculator.php` |
| `IdempotencyService::execute(string $terminalId, string $idempotencyKey, IdempotencyOperationType $operationType, string $requestHash, Closure $operation): IdempotencyResult` | Wraps the whole operation; `$operationType` = `IdempotencyOperationType::Checkout`. | `app/Services/Idempotency/IdempotencyService.php` |
| `CanonicalRequestHasher::hash(array $payload): string` | Produces the `$requestHash` above; strips `request_id`/`idempotency_key`; rejects floats — every Money/Quantity value must already be a decimal string. | `app/Services/Idempotency/CanonicalRequestHasher.php` |
| `GlobalLockOrder::acquire(LockableResource $resource, ?int $tieBreaker = null)` | One instance per transaction; enforces `shift(0) → fiscal_day(1) → sale(2) → sale_item(3) → invoice_series(4)`; throws `LogicException` on violation. | `app/Support/GlobalLockOrder.php` |
| `InvoiceSeriesAllocator::allocateForFiscalInstallation(string $storeId, string $fiscalInstallationId, GlobalLockOrder $lockOrder): AllocatedInvoiceNumber` | Never opens/commits its own transaction — the caller's (Stage 6C's) transaction owns the lock's lifetime. | `app/Services/InvoiceNumbering/InvoiceSeriesAllocator.php` |

**Frozen schema Stage 6C writes to** (all already migrated, exact columns/constraints confirmed): `sales`, `sale_items`, `payments`, `invoices`, `invoice_series` (updated via the allocator only), `idempotency_records`, `stock_movements`, `audit_events`, `electronic_journal_entries`. No new migration is anticipated unless a gap below requires one.

**Frozen contract fixtures** (`docs/05-api/examples/checkout-cash-sale.json`, `checkout-mixed-tax-and-payment.json`, `checkout-non-vat-sale.json`) are usable directly as acceptance-test fixtures — they already show exact expected request/response shapes, including the DISC-005 tie-break worked example and the TAX-NV-003 reconciliation check.

---

## 4. Proposed file-ownership map for Stage 6C

Nothing below exists yet (confirmed by direct search: `app/Http/Controllers/` has only the empty base `Controller.php`; no FormRequest exists anywhere in `app/`; `routes/` has no `api.php`). This map defines what Stage 6C will own, for the eventual `scripts/validate-baselines.sh` isolation check once Stage 6C is frozen — **proposed, not yet created, listed here for review before implementation begins**:

- `app/Services/Checkout/CheckoutService.php` — orchestrates ADR-003's 9 steps; the class name `CheckoutService` is used consistently across ADR-003, ADR-004, architecture.md, and both Stage 6A/6B docs as the forward-referenced implementing class.
- `app/Http/Controllers/SaleController.php`
- `app/Http/Requests/SaleFinalizeRequest.php` (or equivalent validated-DTO approach — open question, see §5)
- `routes/api.php` (new file)
- New domain exceptions for error-catalog codes with no class yet: candidates are `InsufficientPaymentException`, `InvalidPaymentTotalException`, `FiscalDayNotOpenException`, `ShiftRequiredException`, `ProductNotFoundException`, `ProductInactiveException`, `IdempotencyKeyRequiredException` — final list depends on which are genuinely domain-level vs. coverable by ordinary Laravel validation/404 (see §5 gap 6).
- Test files under `tests/Database/` (real-transaction, real-Postgres tests, matching this project's established pattern for anything touching locks/constraints) and `tests/Feature/` (HTTP-level, once a route exists).

---

## 5. Unresolved questions — gaps requiring a ruling

Per instruction, nothing below has been resolved by inference. Each is either a genuine open question or a defect discovered in already-frozen content.

1. **Terminal → FiscalInstallation resolution is explicitly unbuilt, and its exact query is not written down anywhere as a frozen rule.** `stage-6b-invoice-series-allocation.md` states resolving `fiscalInstallationId` from `terminal_fiscal_installation`'s effective-dated mapping "at `sold_at`" is Stage 6C's job. The schema (`terminal_fiscal_installation` with `effective_from`/`effective_to`, partial unique `WHERE effective_to IS NULL`) supports exactly one query (`WHERE terminal_id = ? AND effective_from <= sold_at AND (effective_to IS NULL OR effective_to > sold_at)`), and this appears to be the only reasonable reading — but no ADR or invariant states it explicitly as a rule for checkout to follow, and no such lookup exists in code yet. **Requesting confirmation this reading is correct** before writing it, since it's the resolution query for a value that feeds directly into invoice numbering.

2. **`stock_movements.location_id` is `NOT NULL`, and Sale Finalization must pick one — but no frozen document says which.** `inventory_locations` has a per-store `is_default` boolean, which strongly suggests "deduct from the store's default location," but this is a schema hint, not a stated rule; no invariant, ADR, or domain-model text confirms it, and the schema has no constraint guaranteeing exactly one default location per store. **Requires a ruling**: is it always the store's `is_default` location, or does the request need to (eventually) carry a location, or is multi-location checkout out of scope for V1 entirely (in which case the rule should still be written down, not just assumed)?

3. **Two overlapping idempotency mechanisms exist for checkout specifically, and their exact interaction isn't spelled out end-to-end.** The generic `idempotency_records` table (used via `IdempotencyService::execute()`, covering all 14 operation types) coexists with `sales.idempotency_key` + a dedicated `UNIQUE(terminal_id, idempotency_key)` constraint on `sales` itself. A migration comment states `sales.idempotency_key` "remains authoritative for 'does this sale already exist for this key'" — which reads as intentional (belt-and-suspenders: the generic mechanism protects the whole transaction attempt, the sales-table constraint is the last-line database guarantee against a duplicate `sale` row specifically), not contradictory. **Requesting confirmation** of this reading, since implementing it the other way (e.g., treating one as redundant and skipping it) would silently narrow the guarantee.

4. **Two real defects in already-frozen Stage 5/6A model files, discovered during this initialization, not by inference:**
   - `app/Models/Sale.php`'s `$fillable`/`casts()` omit `non_vat_sales`, even though the column exists on the table (Stage 5 NON_VAT amendment) and is required output of `FinancialCalculator::calculateSale()`. As written, mass-assigning a `Sale` with `FinancialCalculator`'s own output would silently drop `non_vat_sales`.
   - `app/Models/InvoiceSeries.php`'s `$fillable` omits `fiscal_installation_id`, even though the column is `NOT NULL` since the Stage 2/5 InvoiceSeries amendment.
   Per the frozen-corpus rule, this looks like exactly the class of "genuine contradiction that cannot be implemented against the frozen corpus as written" the STOP-and-propose-smallest-amendment procedure exists for — **flagging for a ruling on whether to treat this as an authorized amendment to the Sale/InvoiceSeries model files** (the smallest possible fix: add the two missing entries) before Stage 6C's `CheckoutService` can safely persist through these models.

5. **`AllocatedInvoiceNumber.php`'s own docblock cites a nonexistent method (`allocateForStore()`)** — a stale comment only, the real method (`allocateForFiscalInstallation()`) is unaffected and this doesn't block implementation, but it's a small, disclosed defect in frozen content worth a ruling on whether to correct the comment as part of Stage 6C's work or handle separately.

6. **Several error-catalog codes have no domain exception class yet, and it's not decided whether they should get one, or be handled by ordinary Laravel mechanisms.** For example `PRODUCT_NOT_FOUND`/`PRODUCT_INACTIVE` could be a `ModelNotFoundException`/simple `422` check rather than a bespoke `DomainException` subclass; `AUTHENTICATION_REQUIRED`/`AUTHORIZATION_DENIED`/`TERMINAL_NOT_ENROLLED`/`TERMINAL_REVOKED` likely belong to auth middleware (not yet built — Module A's backend is also ⬜) rather than `CheckoutService` itself. **Requesting a ruling on scope**: does Stage 6C's own scope include building terminal-credential authentication/authorization middleware, or is that assumed to already exist by the time Sale Finalization ships (i.e., a separate, unstarted Module-A backend dependency)? The frozen module tracker shows Module A's backend as ⬜ (not started), same as Module F/G — nothing states Stage 6C depends on Module A finishing first, but nothing states it doesn't either.

7. **Request-shape decision, not a business rule but affects the file-ownership map**: should validation use a Laravel `FormRequest` class (project convention elsewhere is unclear — none exist yet anywhere in the app) or inline `$request->validate()` in the controller? Low-stakes, but affects §4's file list; flagging rather than silently picking one.

8. **`database-schema.md` has no narrative section for `sales`/`sale_items`/`payments`** the way it does for invoice numbering/reprint/idempotency (§11–14) — not a blocker (the migrations and constraint-register.md fully specify the schema), but noting the gap in the docs themselves in case the owner wants it filled in as part of Stage 6C's own documentation deliverable.

None of the above have been resolved by assumption in this document. Section 6's acceptance criteria are written only against what is already frozen; items 1, 2, 3, 4, and 6 above must be ruled on before the corresponding piece of `CheckoutService` can be implemented.

---

## 6. Acceptance criteria (against already-frozen evidence only)

A `CheckoutService`/`POST /sales` implementation is acceptance-complete when, at minimum:

1. A request without an `Idempotency-Key` header is rejected before any transaction opens.
2. A replayed request (same terminal, same key, same request hash) returns the original result, `200`-equivalent, with no new rows of any kind created.
3. A reused key with a different request hash returns `409 IDEMPOTENCY_KEY_REUSED`, and no `sale` row exists for it.
4. Every monetary/tax field in the response is the server's own `FinancialCalculator` output — never the client's submitted values — verified against all three frozen example fixtures byte-for-byte on the financial fields.
5. A sale cannot be created against a shift/fiscal_day that is not `OPEN` for that terminal (`SHIFT_NOT_OPEN`/`FISCAL_DAY_NOT_OPEN`), and the check happens *after* the locks are acquired, not before (ADR-003 step 3, race-safety).
6. Insufficient payment (`SUM(payments) < grand_total`) is rejected without creating a `sale` row.
7. A successful finalization produces, in one transaction: exactly one `sale`, its `sale_item`s (financial fields reconciling per DISC-006/TAX-NV-003), its `payment`s, exactly one `invoice` with a correctly-allocated `invoice_number`, one `stock_movement` per `sale_item`, one `audit_event`, and one `electronic_journal_entry` — verified by direct row count, not by trusting the HTTP response alone.
8. A forced failure injected after step 6 (invoice allocation) but before commit leaves the `invoice_series.current_number` at its pre-attempt value and no partial rows of any kind (full ADR-003 rollback guarantee) — the concurrency test pattern already established for `InvoiceSeriesAllocatorConcurrencyTest` is the template.
9. Two terminals under the same `fiscal_installation` finalizing concurrently receive strictly increasing, non-duplicate invoice numbers (reuses `InvoiceSeriesAllocatorConcurrencyTest`'s same-installation stress pattern, now driven through the real `CheckoutService`, not the allocator directly).
10. `scripts/validate-baselines.sh` still passes after Stage 6C's own boundary is eventually tagged, with a Stage 6C entry added to its path-ownership map.
