# Stage 6C — Sale Finalization: Initialization and Implementation

## Status

**Service/domain layer implemented and tested. HTTP layer (controller, route,
FormRequest) and Module A auth integration are not yet built — not started
in this pass, per the "Gap 6" ruling below.** Not yet frozen/tagged as a
Stage 6C baseline; that happens only on explicit owner review, matching
every prior stage's pattern in this project.

Sections 1–4 below are the original initialization (scoping only, before
any code existed). Section 5 records the owner's rulings on every flagged
gap, plus two further defects discovered *during* implementation (not by
inference) and how they were resolved. Section 6 is the corrected/expanded
acceptance criteria. Started from `main` at the post-`stage-6b-baseline`
governance commit (`8af1ca8`), per the Stage Baseline Rule.

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

## 4. File-ownership map for Stage 6C

Updated to reflect what's actually built after this implementation pass (originally proposed in §4 before any code existed; now the record of what exists).

**Built:**
- `app/Services/Checkout/CheckoutService.php` — orchestrates ADR-003's 9 steps.
- `app/Services/Checkout/FiscalInstallationResolver.php`, `app/Services/Checkout/InventoryLocationResolver.php` — Gap 1/2 resolution helpers.
- `app/Domain/Exceptions/{ShiftRequired,FiscalDayNotOpen,InsufficientPayment,InvalidPaymentTotal,ProductNotFound,ProductInactive}Exception.php` — `DomainException` subclasses mapping to their frozen error-catalog codes.
- `app/Domain/Exceptions/{FiscalInstallationResolution,InventoryLocationResolution,NoActiveTaxRegistration}Exception.php` — non-`DomainException` resolution/setup-defect exceptions (see §5's disclosed contract gaps).
- `database/migrations/2026_09_17_120000_add_one_default_location_per_store_constraint.php`, `database/factories/InventoryLocationFactory.php`.
- `tests/Database/{ModelMassAssignmentRegressionTest,CheckoutResolversTest,CheckoutServiceTest}.php` — 20 tests total.
- Corrections to pre-existing Stage 5/6A files: `app/Models/Sale.php`, `app/Models/InvoiceSeries.php`, `app/Models/InventoryLocation.php` (added `HasFactory`), `app/Models/ElectronicJournalEntry.php`, `app/Services/InvoiceNumbering/AllocatedInvoiceNumber.php` (docblock only).

**Not yet built** (confirmed by direct search before this pass: `app/Http/Controllers/` had only the empty base `Controller.php`; no FormRequest existed anywhere in `app/`; `routes/` had no `api.php` — none of that changed in this pass, per the Gap 6/7 rulings holding the HTTP layer and Module A integration out of scope for now):
- `app/Http/Controllers/SaleController.php`
- `app/Http/Requests/SaleFinalizeRequest.php`
- `routes/api.php`
- `tests/Feature/` HTTP-level tests (blocked on the above)

---

## 5. Rulings and implementation record

Every gap from the original initialization was ruled on by the owner before implementation began. These are new Stage 6C architectural decisions, not claims that the rules were already present in the frozen corpus.

1. **Terminal → FiscalInstallation resolution — APPROVED with explicit interval semantics.** Effective-dated mappings use an inclusive-start, exclusive-end interval: `[effective_from, effective_to)`. Resolution requires exactly one matching row; zero or more than one aborts (never "latest wins"). No new lock class was introduced — this is a plain read inside the transaction, and the frozen checkout lock order (`shift → fiscal_day → invoice_series`) is unchanged. **Implemented**: `App\Services\Checkout\FiscalInstallationResolver`, throwing `App\Domain\Exceptions\FiscalInstallationResolutionException` (zero/ambiguous). Tested: `tests/Database/CheckoutResolversTest.php` (inclusive-start boundary, exclusive-end boundary, no-mapping, and a deliberately-bad-data overlapping-rows case).

2. **Inventory-location resolution — RULED: V1 always deducts from the store's single default location.** No location-selection field exists in the frozen `SaleFinalizeRequest` contract, so this is not a per-request choice; multi-location checkout is deferred to a future product/API change. Hardened with a new migration adding `UNIQUE(store_id) WHERE is_default = true` — guarded by a preflight query that aborts (rather than silently picking a winner) if any store already has more than one default. It does not, and cannot, guarantee every store *has* a default; zero defaults is a runtime rejection. **No new wire-level error code was invented** for "no default configured" — disclosed as a contract gap (see below). **Implemented**: `database/migrations/2026_09_17_120000_add_one_default_location_per_store_constraint.php`, `App\Services\Checkout\InventoryLocationResolver`, `App\Domain\Exceptions\InventoryLocationResolutionException`. Tested: `tests/Database/CheckoutResolversTest.php` (happy path, zero-default rejection, DB-level rejection of a second default, and per-store independence).

3. **Dual idempotency — CONFIRMED intentional defense-in-depth.** `IdempotencyService` remains the primary request/replay mechanism (same key + same hash → replay; same key + different hash → `409`); `sales.(terminal_id, idempotency_key)` remains the database-level uniqueness invariant on the `sale` row itself. `CheckoutService` always calls `IdempotencyService::execute()` and always persists the key onto the successful `sale` — neither mechanism was replaced or bypassed. **Implemented** as designed: `CheckoutService::finalize()` never queries `sales` directly to short-circuit; `sales.idempotency_key` is set from the same key passed to the idempotency service. Tested: `CheckoutServiceTest::test_replayed_request_returns_the_same_sale_without_creating_another`.

4. **Frozen model defects — AUTHORIZED, smallest forward correction, Stage 5/6A tags unmoved.** `Sale::$fillable`/`casts()` now include `non_vat_sales` (same convention as its sibling monetary fields); `InvoiceSeries::$fillable` now includes `fiscal_installation_id`. Documented here, per the ruling's exact wording, as *Stage 6C integration corrections to pre-existing model metadata discovered while consuming the frozen schema* — not a Stage 5/6A redesign, and no baseline was retagged. **Implemented and tested**: `tests/Database/ModelMassAssignmentRegressionTest.php` proves both survive real `Model::create()` mass assignment (not `factory()->create()`, which bypasses `$fillable` via `forceFill()` and would never have caught either defect).

5. **Stale `AllocatedInvoiceNumber` docblock — corrected.** Now cites `allocateForFiscalInstallation()`. Documentation-only, no behavioral change.

6. **Module A dependency — HELD FIRMLY. Not Stage 6C scope.** `CheckoutService` accepts an already-resolved `$terminalId`/`$cashierId` — it does not authenticate, enroll, or authorize anything. `401`/`403`/`TERMINAL_NOT_ENROLLED`/`TERMINAL_REVOKED` remain frozen contract requirements, but their HTTP-layer enforcement is explicitly **BLOCKED ON MODULE A**, not implemented in this pass. This document does not claim those paths are verified merely because the OpenAPI contract declares them.

7. **FormRequest boundary — RULED.** `FormRequest` (once built) is transport/shape validation only (required fields, primitive types); every business invariant (open shift, payment sufficiency, tax registration, product state, fiscal-installation/location resolution) lives in `CheckoutService`. Not yet built in this pass — `CheckoutService::finalize()` currently accepts an already-shape-valid `array` directly, exercised by tests that construct valid payloads. The Controller/FormRequest/route layer remains open work, tracked in §4's file-ownership map.

8. **`database-schema.md` narrative gap — deferred to Stage 6C closure**, not expanded now, per the ruling.

### Two further defects found during implementation (not by inference)

9. **`ElectronicJournalEntry` (`app/Models/ElectronicJournalEntry.php`) used `HasUlids` against a native PostgreSQL `uuid` column, and the trait's default `newUniqueId()` emits a ULID's Base32/Crockford string** (e.g. `01m2q3s4nffdab3sc7zzrk4kvb`), which Postgres's `uuid` type rejects outright (`SQLSTATE[22P02]`). This was never exercised before Stage 6C — nothing wrote to `electronic_journal_entries` until `CheckoutService`. Fixed with the smallest possible correction, matching gap 4's treatment: overrode `newUniqueId()` to return `Str::ulid()->toRfc4122()` (the *same* 128 bits, re-encoded as a standard hyphenated UUID string — this is what the original migration comment's "a ULID IS a valid 128-bit UUID-format value" claim actually depends on) and `isValidUniqueId()` to check UUID format instead of ULID format. No Stage 5 baseline retagged; disclosed here, not silently patched.

10. **No frozen error-catalog code exists for "store has zero active `tax_registrations`" either**, even though the Stage 5 migration comment for `tax_registrations` explicitly assigns this check to "Stage 6" by name ("no active row = finalization must fail, a Stage 6 application check, not a schema one"). Treated the same as gap 2: implemented as `NoActiveTaxRegistrationException` (non-`DomainException`, not yet mapped to a wire error code), disclosed as a further contract gap rather than inventing a code.

### Disclosed contract gaps (for a future Stage 4 amendment, not resolved here)

The frozen error catalog has no code for: "no default inventory location configured" (`InventoryLocationResolutionException`), "no active tax registration" (`NoActiveTaxRegistrationException`), or the terminal/fiscal-installation setup-defect cases (`FiscalInstallationResolutionException`). All three currently surface as unhandled `RuntimeException`s rather than stable HTTP error envelopes — intentional per the rulings above (no new code invented unilaterally), but a real gap the eventual HTTP layer and/or a Stage 4 amendment must close before production use.

---

## 6. Acceptance criteria

Corrected per the owner's rulings (three ACs adjusted, four added). Each line notes its test status.

1. A request without an `Idempotency-Key` header is rejected before any transaction opens. *(Not yet testable — no FormRequest/route exists; the header itself is HTTP-layer, not `CheckoutService`'s concern.)*
2. **(corrected)** A replayed request with the same terminal, key, and canonical request hash returns the previously recorded operation result using the replay semantics defined by the frozen API/idempotency contract, without creating any additional rows. *(Avoids asserting a `200`-equivalent response-status decision that isn't Stage 6C's to make.)* — **Tested**: `CheckoutServiceTest::test_replayed_request_returns_the_same_sale_without_creating_another`.
3. **(corrected)** A reused idempotency key with a different request hash returns `409 IDEMPOTENCY_KEY_REUSED`, and no additional sale or dependent rows are created by the conflicting request. *(The original sale from the first, genuine request may already exist — the corrected wording no longer implies otherwise.)* — **Tested** at the `IdempotencyService` layer already (Stage 6A); `CheckoutService` inherits this behavior by construction, not independently re-verified in this pass.
4. Every monetary/tax field in the response is the server's own `FinancialCalculator` output — never the client's submitted values. — **Tested**: `CheckoutServiceTest::test_checkout_recomputes_totals_server_side_ignoring_client_hints`.
5. A sale cannot be created against a shift/fiscal_day that is not `OPEN` for that terminal, and the check happens *after* the locks are acquired (a single `WHERE status = 'OPEN' ... FOR UPDATE` query is race-safe by construction under Postgres READ COMMITTED — see `CheckoutService`'s own docblock). — **Tested**: `test_shift_required_when_none_is_open`, `test_fiscal_day_not_open_is_rejected`.
6. Insufficient or non-positive payment is rejected without creating a `sale` row. — **Tested**: `test_insufficient_payment_is_rejected_and_creates_no_sale`, `test_non_positive_payment_amount_is_rejected`.
7. A successful finalization produces, in one transaction, exactly one `sale`/`sale_item`(s)/`payment`(s)/`invoice`/`stock_movement`(s) (one per line)/`audit_event`/`electronic_journal_entry`, verified by direct row count. — **Tested**: `test_successful_checkout_writes_every_required_row_in_one_transaction`.
8. A forced failure before commit leaves `invoice_series.current_number` at its pre-attempt value and creates no `invoice`. — **Tested**: `test_a_failed_checkout_does_not_advance_the_invoice_counter`.
9. **(corrected)** Two successful concurrent sales under the same fiscal installation receive distinct invoice numbers from the same series; the committed numbers form the expected sequential allocation with no duplicates, reuse, or unexplained gaps, and `current_number` advances exactly by the number of committed allocations. *(Replaces "strictly increasing", which would have implied an arrival-order guarantee concurrency cannot make.)* — **Not yet independently tested through `CheckoutService`**; `InvoiceSeriesAllocatorConcurrencyTest` already proves this at the allocator layer directly (Stage 6B), and `CheckoutService` calls that same allocator with no additional locking logic of its own, but a dedicated multi-process `CheckoutService`-level test has not been written in this pass.
10. `scripts/validate-baselines.sh` still passes after Stage 6C's own boundary is eventually tagged, with a Stage 6C entry added to its path-ownership map. *(Not applicable yet — no Stage 6C baseline exists.)*
11. **(new)** Fiscal-installation resolution: `sold_at` resolves against `[effective_from, effective_to)`, requires exactly one mapping, boundary times tested. — **Tested**: `CheckoutResolversTest` (4 dedicated cases).
12. **(new)** Inventory-location resolution: checkout writes all stock movements to exactly one store default location; zero/multiple defaults abort without partial writes. — **Tested**: `CheckoutResolversTest` (happy path + zero-default + DB-level multiple-default rejection).
13. **(new)** Dual idempotency: successful checkout produces both the generic idempotency outcome and a `sale` carrying the same key; replay creates neither another sale nor another business write-set. — **Tested**: same test as AC #2.
14. **(new)** Model corrections: `non_vat_sales` and `fiscal_installation_id` survive the normal mass-assignment persistence path through their Eloquent models. — **Tested**: `ModelMassAssignmentRegressionTest`.

**Full regression at the time of this writing**: `tests/Unit` 85/196, `tests/Database` 118/341 (up from 98/305 — the +20 are this section's new tests), `tests/Feature` 1/1, migration round-trip (`fresh`/`reset`/`migrate`) clean, Pint clean.
