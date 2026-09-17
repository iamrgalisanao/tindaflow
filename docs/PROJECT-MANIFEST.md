# TindaFlow POS — Project Manifest

## Purpose

This is the single tracking document for TindaFlow's progress against the
governing project brief's 10-stage delivery process (brief §18). It answers
three questions at a glance: **where is each stage**, **where is each V1
module**, and **what's still open**. Update this file at the end of every
stage/remediation pass — it is the source of truth the HTML dashboard
(`docs/dashboard.html`, or the published Artifact) is generated from, not
the other way around.

**Last updated:** 2026-09-17 — **Full baseline history linearization complete. Stage 6B APPROVED and FROZEN on a genuinely isolated, genuinely ordered chain.**

The owner rejected an earlier two-part reconciliation of this baseline chain because, although its file *content* was correct, its *ancestry* was not: the InvoiceSeries amendment had been inserted after the old Stage 5 boundary (`8c8fe32`), which itself sat downstream of the original Stage 3/4/5 commits — so `stage-2-baseline` still contained Stage 3/4/5 content in its own history. Verification showed this was not new: the project's original `stage-2-baseline` (`6da07bd`, then `b7ef8fd` after the NON_VAT amendment) had always had Stage 3's commit as a git ancestor, because every stage in this project's single-branch history was amended interleaved with later stages' own work. The owner's decision: fully linearize from Stage 1 forward so that for every adjacent pair, `stage-N-baseline` is a genuine git ancestor of `stage-(N+1)-baseline`, **and** no stage's baseline contains a single path owned by a later stage.

### Reconstruction method

Each stage boundary was built by checking out the exact, already-approved file content for that stage's own paths from the previously accepted tip (tagged `backup/pre-final-baseline-reconcile`) directly onto the *previous* stage's already-reconstructed boundary — `git checkout <tip> -- <stage-owned paths>` — rather than replaying the original interleaved commit sequence. This guarantees every commit forward is a strict superset of the one before it, so ancestry and isolation are structural, not merely reviewed. No content was re-authored, redesigned, or reworded: it was relocated in the graph. This was verified at the end by diffing the final tip against the backup and finding zero difference outside `PROJECT-MANIFEST.md` itself.

One genuine gap was found and fixed *during* this process, not assumed away: Stage 1's own tagged baseline (`ac907e1`) turned out not to be Stage 1's final approved content. `docs/01-research/bir-reference-register.md` had been amended (BIR-012/013/014 added, then upgraded to HIGH confidence) during the Stage 2 discount-allocation pass — a Stage-1-owned file amended out of stage order, same pattern as every other stage. This was caught by the same content-diff verification method being used everywhere else, fixed with a dedicated commit as a child of `ac907e1`, and the entire chain was rebased onto the corrected Stage 1 boundary (a clean, zero-conflict rebase, since nothing else touches that file).

### Path-ownership map used throughout

- **Stage 1**: `docs/00-product/`, `docs/01-research/`, `docs/06-ui/`
- **Stage 2**: `docs/02-domain/`, `docs/03-architecture/erd.md` (Stage 2 content per its own status header, despite its directory)
- **Stage 3**: `docs/03-architecture/architecture.md`, `deployment.md`, `offline-strategy.md`, `decisions/ADR-*.md`
- **Stage 4**: `docs/05-api/`
- **Stage 5**: `docs/04-database/`, `database/migrations/`, `database/factories/`, `database/seeders/`, `database/scripts/`, `app/Models/`, 9 named Stage-5-owned files in `tests/Database/`, plus the Laravel application scaffold (first required here — Stages 1–4 are pure documentation)
- **Stage 6A**: `app/Domain/Exceptions/{Concurrency,Domain,FiscalDayClosed,IdempotencyKeyReused,InvalidTaxConfiguration,RefundExceedsRemainingAmount,RefundExceedsRemainingQuantity,SaleNotVoidable,ShiftNotOpen}Exception.php`, `app/Domain/Financial/`, `app/Domain/Money.php`, `app/Domain/Quantity.php`, `app/Services/Idempotency/`, `app/Support/`, `docs/06-backend/stage-6a-transaction-foundation.md`, 3 named files in `tests/Database/` + their worker, `tests/Unit/Domain/`, `tests/Unit/Services/CanonicalRequestHasherTest.php`, `tests/Unit/Support/`
- **Stage 6B**: `app/Domain/Exceptions/InvoiceSeries{Exhausted,Resolution}Exception.php`, `app/Services/InvoiceNumbering/`, `docs/06-backend/stage-6b-invoice-series-allocation.md`, 2 named files in `tests/Database/` + their worker, `tests/Unit/Services/InvoiceNumbering/`

---

## At a glance

| | |
|---|---|
| **Current stage** | **Stage 6 — Backend Implementation** (✅ Stage 6A and Stage 6B both frozen on a fully linearized, isolation-verified corpus. Stages 1–6B are closed. Stage 6C — Sale Finalization — is next but has not been started) |
| **Frozen baselines** | Stage 1 (`stage-1-baseline` @ `d93a813`), Stage 2 (`stage-2-baseline` @ `5a7382a`; substantive `6289588`), Stage 3 (`stage-3-baseline` @ `78a190c`; substantive `680ac1b`), Stage 4 (`stage-4-baseline` @ `2313a16`; substantive `9adc30b`), Stage 5 (`stage-5-baseline` @ `4c16ee0`; substantive `9de98ce`), Stage 6A (`stage-6a-baseline` @ `47fa84d`; substantive `823c032`), Stage 6B (`stage-6b-baseline` @ *this manifest commit*; substantive `2f5e6e8`) |
| **Next action** | None until the owner authorizes Stage 6C (Sale Finalization). Per explicit instruction, Stage 6C does not begin automatically. |
| **Total ADRs** | 12 |
| **Open `BIR-REVIEW-REQUIRED` items** | 4 (unchanged) |
| **Lines of implementation code written** | 41 migrations, 34 Eloquent models, 14 factories, 3 seeders (Stage 5); Money/Quantity, FinancialCalculator, IdempotencyService, GlobalLockOrder, domain exception hierarchy (Stage 6A); InvoiceSeriesAllocator, AllocatedInvoiceNumber, 2 exceptions (Stage 6B) — all frozen |

---

## Frozen-corpus rule for Stage 6 onward (owner instruction, 2026-09-16)

Stages 1–6B are closed. **Do not modify any frozen document, ADR,
migration, or schema decision belonging to Stages 1–6B** unless Stage 6C
discovers a genuine contradiction that cannot be implemented against the
frozen corpus as written. If that happens, the required procedure is: (1)
document the contradiction, (2) identify the exact affected invariant/
ADR/API/schema decision, (3) propose the smallest amendment, (4) STOP
and wait for explicit owner approval before changing anything. No frozen
baseline (`stage-1-baseline` through `stage-6b-baseline`) is ever to be
silently moved or its content silently changed.

**Amendment governance note (2026-09-17, baseline linearization):** when an
approved amendment changes an ancestor of stages that are already frozen
and tagged, the correct remedy — while the repository remains
local/unpublished — is to rebuild history so the amendment is a genuine
ancestor of the later baselines, **and** so no earlier baseline contains
any content owned by a later stage. Ancestry alone is not sufficient
evidence that a stage boundary is correct: verify both
`git merge-base --is-ancestor stage-N-baseline stage-(N+1)-baseline`
(ordering) and that `git ls-tree -r --name-only stage-N-baseline` contains
nothing from a later stage's path-ownership (isolation). Stage 6C may not
silently modify: InvoiceSeries ownership by FiscalInstallation, counter
bootstrap semantics, range constraints, series resolution, row-lock
allocation, or rollback behavior (Stage 6B); nor Money/FinancialCalculator,
idempotency, or global lock ordering (Stage 6A).

---

## Stage tracker

Stages follow the governing brief §18. Status legend: ✅ Approved & frozen
(tagged) · 🚧 In progress · ⬜ Not started.

| # | Stage | Status | Baseline | Key deliverables | Notes |
|---|---|---|---|---|---|
| 0 | Repository Inspection | ✅ | — | Confirmed empty repo, nothing to preserve | Instant — no prior code existed |
| 1 | Product Baseline | ✅ | `stage-1-baseline` @ `d93a813` | product-vision.md, scope.md, personas.md, market-comparison.md, bir-reference-register.md, sitemap.md | **Reconstructed during the full linearization** to include BIR-012/013/014 (added later, during Stage 2's discount-allocation pass, but Stage-1-owned content) — the original `ac907e1` tag was a point-in-time snapshot, not Stage 1's final approved state. Content-equivalence verified via empty diff against the previously accepted tip. |
| 2 | Domain Model | ✅ | `stage-2-baseline` @ `5a7382a` (substantive `6289588`) | domain-model.md, invariants.md (81 invariants), state-machines.md, erd.md | Final content includes every approved amendment: the original domain model, Void/Refund processing context, capability-catalog sync, Void wording fix, NON_VAT tax-summary model, and InvoiceSeries counter-bootstrap/FiscalInstallation-binding. Now a genuine ancestor of Stage 3, containing nothing from Stage 3 onward — verified by direct tree inspection, not assumed. |
| 3 | Architecture | ✅ | `stage-3-baseline` @ `78a190c` (substantive `680ac1b`) | architecture.md, deployment.md, offline-strategy.md, erd.md (Stage 2 content, retained here), 12 ADRs | **Semantically unchanged** — content-equivalence to the original `c8a8bbe` verified via empty diff. Hash changed only because it now correctly descends from the amended Stage 2, instead of Stage 2 being amended on top of it. No new Stage 3 design decision introduced. |
| 4 | API Contract | ✅ | `stage-4-baseline` @ `2313a16` (substantive `9adc30b`) | openapi.yaml (86 ops, 67 schemas), api-design.md, error-catalog.md, operation-inventory.md, csv-export-contract.md, 13 example files | **Semantically unchanged** — content-equivalence to the previously approved, NON_VAT-amended `515e2db` verified via empty diff. InvoiceSeries's amendment never touched Stage 4 at all. |
| 5 | Database Schema | ✅ | `stage-5-baseline` @ `4c16ee0` (substantive `9de98ce`) | 41 migrations, 34 models, database-schema.md, constraint-register.md, context-integrity-matrix.md, index-strategy.md, migration-plan.md, schema-validation.md, stage-5-report.md, 14 factories, 3 seeders, Stage 5's 9 test files | Content-equivalence to the previously accepted reconciled `f036da9` verified via empty diff. Migration round-trip (`fresh`/`reset`/`migrate`) re-verified clean at this exact boundary before advancing. |
| 6A | Backend — Transaction Foundation | ✅ | `stage-6a-baseline` @ `47fa84d` (substantive `823c032`) | Money/Quantity, FinancialCalculator, CanonicalRequestHasher/IdempotencyService, GlobalLockOrder, domain exception hierarchy | Content-equivalence to `570dd75` verified via empty diff. Full regression re-verified clean at this boundary before advancing. |
| 6B | Backend — Invoice Series Allocation | ✅ | `stage-6b-baseline` @ *this manifest commit* (substantive `2f5e6e8`) | InvoiceSeriesAllocator (row-lock allocation per ADR-004), AllocatedInvoiceNumber DTO, InvoiceSeriesExhaustedException/InvoiceSeriesResolutionException | Content-equivalence to the previously accepted `228e033` verified via empty diff. **Stage 6C (Sale Finalization) is next, not yet started.** |
| 7 | Frontend | ⬜ | — | React SPA, cashier screen first | sitemap.md (Stage 1) is the only frontend artifact so far |
| 8 | Testing | ⬜ | — | Unit/feature/invariant test suite | invariants.md's 81 invariants are the test-case source list once this starts |
| 9 | Deployment | 🚧 | — | Docker Compose, backup/restore, deployment checklist | deployment.md was already produced ahead of schedule as part of Stage 3 |

---

## Module tracker (governing brief §3, Modules A–L)

Legend: ✅ Covered · 🚧 Partial/implicit · ⬜ Not started.

| Module | Domain (St.2) | Architecture (St.3) | API Contract (St.4) | DB (St.5) | Backend (St.6) | Frontend (St.7) | Tests (St.8) |
|---|---|---|---|---|---|---|---|
| A — Authentication & Users | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| B — Store Settings | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| C — Product Catalog | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| D — POS Cashier Screen | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| E — Payment | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| F — Sales Transaction | ✅ | ✅ | ✅ | ✅ | 🚧 (Stage 6A frozen; Sale finalization is Stage 6C) | ⬜ | ⬜ |
| G — Invoice | ✅ | ✅ | ✅ | ✅ | 🚧 (Stage 6B allocator frozen; InvoiceSnapshot generation is Stage 6C) | ⬜ | ⬜ |
| H — Cashier Shift | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| I — Inventory | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| J — Sales History | ✅ | 🚧 | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| K — Void and Refund | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| L — Reports | 🚧 | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |

---

## Documentation inventory

Unchanged from before the linearization — see the path-ownership map above,
which is definitional: every file listed for a stage there is exactly what
that stage's baseline commit contains, verified by direct tree inspection
rather than by convention alone.

---

## Git baseline history

| Commit | What it represents |
|---|---|
| `ac907e1` | Original Stage 1 tag — later found not to be Stage 1's final content (see below) |
| `d93a813` — **`stage-1-baseline`** | **Reconstructed Stage 1.** Adds BIR-012/013/014 to `bir-reference-register.md`, folding in a Stage-1-owned amendment that had been committed out of order during Stage 2's discount-allocation pass. Content-equivalence to the final approved tip verified via empty diff. |
| `6289588` | Stage 2 substantive content (final, all amendments included) |
| `5a7382a` — **`stage-2-baseline`** | Stage 2 manifest boundary. Genuine ancestor of Stage 3; contains nothing from Stage 3 onward. |
| `680ac1b` | Stage 3 substantive content — content-identical to the original `c8a8bbe` |
| `78a190c` — **`stage-3-baseline`** | Stage 3 manifest boundary. |
| `9adc30b` | Stage 4 substantive content — content-identical to the previously approved `515e2db` |
| `2313a16` — **`stage-4-baseline`** | Stage 4 manifest boundary. |
| `9de98ce` | Stage 5 substantive content — content-identical to the previously accepted `f036da9` |
| `4c16ee0` — **`stage-5-baseline`** | Stage 5 manifest boundary. Migration round-trip re-verified clean here. |
| `823c032` | Stage 6A substantive content — content-identical to the previously accepted `570dd75`'s substantive commit |
| `47fa84d` — **`stage-6a-baseline`** | Stage 6A manifest boundary. Full regression re-verified clean here. |
| `2f5e6e8` | Stage 6B substantive content — content-identical to the previously accepted `228e033` |
| *(this commit)* — **`stage-6b-baseline`** | Stage 6B manifest boundary — closes the full linearization. |

**Superseded (all previous reconciliation attempts, reachable via `git reflog`/`backup/pre-final-baseline-reconcile`, no longer referenced by any tag):** the original interleaved history (`6da07bd`, `b7ef8fd`, `c8a8bbe` (as a Stage-2-ancestor-containing tag target), `40aaebd`/`515e2db`, `21bf0c2`/`8c8fe32`, `47baddf`/`48d173e`), the rejected first InvoiceSeries reconciliation (`d1bfaa7`, `f4ca1d9`), and the rejected two-part reconciliation (`e1fb82a`, `f036da9`, `570dd75`, `228e033`, `ceab7d4`). `c8a8bbe` and `515e2db` remain historically meaningful as "the commit whose *content* Stage 3/4 were verified against," but are no longer any tag's target.

**Verification performed before any tag was moved:**
1. **Ancestry** — `git merge-base --is-ancestor` for every adjacent pair (`stage-1-baseline`→`stage-2-baseline`, …, `stage-6a-baseline`→`stage-6b-baseline`): all six return true.
2. **Isolation** — `git ls-tree -r --name-only <stage-N-baseline>` grepped for every later stage's path-ownership at each of the five internal boundaries: all empty.
3. **Content-completeness** — `git diff backup/pre-final-baseline-reconcile <final tip> -- . ':!docs/PROJECT-MANIFEST.md'` is empty: every file that existed in the previously accepted (content-correct) state still exists, nothing was duplicated, nothing was dropped.
4. **Behavioral regression** — full migration round-trip, full Unit/Database/Feature suites, and every named critical test, all re-run at the final tip and matching the pre-linearization counts exactly.

---

## Open items

### `BIR-REVIEW-REQUIRED` (4 remaining — see [bir-reference-register.md](01-research/bir-reference-register.md))
1. **BIR-006** — full required invoice-field list needs re-confirmation against RR 16-2018/6-2022/11-2004 directly.
2. **BIR-007** — PTU status for CRM/POS should be re-confirmed against current RMO 24-2023 text before any real filing.
3. **BIR-008** — no fixed EIS activation date for standalone POS users yet.
4. **BIR-010** — exact date ORUS gained CAS/POS registration functionality is unverified.

### Standing risks to watch
- The 14-operation idempotency contract needs consistent Stage 6C implementation, not just per-operation ad hoc handling.
- Void-eligibility policy and the FiscalDay auto-open policy are TindaFlow product decisions, not BIR mandates.

---

## Revision log

- **2026-09-16/17** — Stages 1–6B built up through many remediation and amendment passes on a single interleaved branch (full detail preserved in git history and in `docs/06-backend/stage-6b-invoice-series-allocation.md` §8–9). Two internal reconciliation attempts (NON_VAT amendment placement, then a two-part InvoiceSeries reconciliation) each corrected a real governance defect but the second was itself found to be structurally insufficient.
- **2026-09-17** — **Owner rejected the two-part reconciliation** (`e1fb82a`/`f036da9`/`570dd75`/`ceab7d4`): although its resulting file content was correct, `stage-2-baseline` (`e1fb82a`) still had Stage 3/4/5's original commits as git ancestors, because the reconstruction started from the old Stage 5 tip rather than the true Stage 2 boundary. The owner further identified that this pattern was not new — the project's original `stage-2-baseline` had always postdated Stage 3 in the commit graph — and required a full linearization rather than a second narrow patch, with an explicit backup (`backup/pre-final-baseline-reconcile` @ `ceab7d4`) before any further rewriting.
- **2026-09-17** — **Full baseline linearization performed and validated.** Built each stage boundary by checking out that stage's exact final-approved content from the backup tip onto the previous boundary (Stage 1 → 2 → 3 → 4 → 5 → 6A → 6B), verifying content-equivalence via empty diffs at every step and re-running the relevant test suite before advancing. **Found and fixed one genuine gap along the way**: Stage 1's own `ac907e1` tag was not Stage 1's final content (missing the later BIR-012/013/014 amendment) — fixed with a dedicated commit and the whole chain rebased onto it (zero conflicts). **Final verification**: all six ancestry checks pass; all five internal isolation checks are empty; the final tip's full tree is diff-identical to the pre-linearization backup outside this manifest; migration round-trip, full Unit (85/196)/Database (98/305)/Feature (1/1) suites, every named critical test, and Pint all re-verified clean and matching pre-linearization counts exactly. `main` fast-forwarded to the final tip; `stage-1-baseline` through `stage-6b-baseline` all moved to their reconstructed hashes; scratch branches deleted. **Stages 1 through 6B are now closed on a chain that is both correctly ordered and correctly isolated.** Per the owner's explicit instruction: **Stage 6C (Sale Finalization) does not begin now that this freeze has succeeded — it requires a separate, explicit go-ahead.**
