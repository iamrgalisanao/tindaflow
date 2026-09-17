# ADR-012: One Authoritative Financial Calculator, No Duplicated Money/Tax Logic

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2 defines the Money model, Quantity value object, the Deterministic
Proportional Allocation algorithm (discount and tax allocation), and the
refund-basis rule in detail (domain-model.md §2.7a, §2.9, §3). Multiple
layers of the application — React's cart preview, the checkout
finalization path, the refund workflow, and reporting — all touch
monetary figures. Stage 3 must ensure these Stage 2 rules are implemented
exactly once, not re-derived independently in each layer, since
independent reimplementations are the single most common source of
"the preview said ₱120.00 but the receipt says ₱120.05" bugs.

## Decision
A single backend PHP component (module: shared kernel, consumed by
Checkout/Sales and Payments) — the **`FinancialCalculator`** — is the only
code in the system authorized to perform monetary arithmetic beyond
trivial display formatting. It composes: a `Money` value object (§3.1),
a `Quantity` value object (§3.2), a `DiscountAllocator` (the largest-
remainder algorithm, §2.7a), and a `TaxCalculator` (sum-then-decompose +
per-line allocation, §3.1). Every write path that produces a
`sale`/`sale_item`/`refund`/`refund_item` monetary field calls into this
component; none reimplements rounding, allocation, or decomposition
locally.

- **React** may compute a **preview** for cart responsiveness (running
  total as items are scanned), using the same *display* rounding
  convention, but this preview is explicitly non-authoritative: the
  checkout transaction always recomputes from scratch server-side (Stage 2
  invariant #4), and any client/server mismatch beyond a documented
  tolerance is rejected, never silently reconciled by trusting the client.
- **Controllers** never touch `Money`/`Quantity` arithmetic directly —
  they receive already-computed `FinancialCalculator` output and persist
  it.
- **Reports** read already-persisted relational fields (`sale.grand_total`,
  `sale_item.tax_amount`) — they never re-run tax/discount logic over raw
  inputs to "recompute" a report figure.
- **Refunds** call the calculator's refund-basis method (the cumulative-
  recompute-then-subtract rule, domain-model.md §2.9) rather than
  reimplementing proration inline in the refund service.

**Versioning, without a rules engine:** a future tax-rate change is a
single, centrally-configured value inside this one component (or, if
genuinely needed later, an effective-dated lookup analogous to
`tax_registration`'s pattern) — not a change scattered across multiple
call sites. V1 does not build a generalized pluggable tax-rules engine;
Stage 2 already rejected overbuilding a promotions/discount engine for the
same reason, and this ADR applies the identical discipline to tax-rate
evolution.

## Alternatives Considered
- **Let each consumer (React, refund service, reports) implement its own
  rounding/allocation logic, calibrated to match** — rejected: "calibrated
  to match" is precisely the failure mode this ADR prevents; any future
  change to the Stage 2 algorithm (e.g., a rounding-mode adjustment) would
  need to be found and updated in every duplicate, and a missed one
  produces exactly the kind of discrepancy Stage 2's reconciliation
  invariants (DISC-006) exist to make impossible.
- **A shared calculation library duplicated between PHP and TypeScript**
  (so the frontend preview and backend authority use "the same" code,
  transpiled or hand-ported) — considered and rejected for V1: this adds
  build-pipeline complexity (maintaining two runtimes' worth of the same
  logic, or a cross-compilation step) for a preview whose only job is
  rough real-time feedback, not correctness. The backend remains the sole
  source of truth regardless; a perfectly-synchronized preview is a nice-
  to-have, not a requirement, since the server's recomputation is what
  actually gets charged and printed.
- **A generic, configurable tax-rules engine** (data-driven rate tables,
  pluggable strategies) — rejected as premature for a single-currency,
  single-jurisdiction (Philippines), two-registration-type (VAT/NON_VAT)
  domain with no near-term second tax regime in scope. Revisit only if a
  concrete second regime is actually required.

## Consequences / Trade-offs
- **Positive:** one place to test (Stage 8's DISC-001–006 test suite
  targets this component directly), one place to fix if a rounding bug is
  ever found, no drift between preview and authoritative figures beyond
  the expected "preview is approximate, receipt is exact" UX difference.
- **Trade-off:** the frontend preview, being a separate (simpler)
  implementation, can in principle diverge from the authoritative result
  in edge cases (e.g., a discount-allocation residual centavo landing on a
  different line in the preview than in the final receipt) — acceptable
  because the preview is explicitly labeled non-authoritative and the
  final, printed, charged figures always come from the one authoritative
  component.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 6 (`FinancialCalculator` implementation), Stage 7
(React preview calibrated to, but not sharing code with, the backend),
Stage 8 (DISC-001–006 test coverage targets this component).
