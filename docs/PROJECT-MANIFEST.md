# TindaFlow POS — Project Manifest

## Purpose

This is the single tracking document for TindaFlow's progress against the
governing project brief's 10-stage delivery process (brief §18). It answers
three questions at a glance: **where is each stage**, **where is each V1
module**, and **what's still open**. Update this file at the end of every
stage/remediation pass — it is the source of truth the HTML dashboard
(`docs/dashboard.html`, or the published Artifact) is generated from, not
the other way around.

**Last updated:** 2026-09-17 — **Stage 6C (Sale Finalization): checkout domain/service layer VERIFIED; `POST /sales` production readiness BLOCKED ON MODULE A.** Four commits on top of the sealed `stage-6b-baseline` (`8af1ca8` → `a0736b7` → `fb757b2` → `6803871` → `d2e8244`): scope/evidence initialization, the `CheckoutService` implementation itself, a dedicated verification pass closing four owner-required gates, and the final `transaction_number` ruling. **Not frozen or tagged** — no `stage-6c-baseline` exists yet; this is implementation in progress on `main`, tracked here per the Stage Baseline Rule's own instruction to keep this manifest current at every meaningful boundary, not only at freezes. See "Stage 6C progress" below for the full record. The Stage 6B linearization/sealing narrative beneath it is unchanged and still accurate for how Stages 1–6B were built.

### Stage 6C progress (2026-09-17, in progress — not frozen)

**Scope**: exactly one HTTP operation (`POST /sales`, `saleFinalize`), orchestrating ADR-003's 9-step transaction script by calling Stage 6A/6B's already-approved components (`FinancialCalculator`, `IdempotencyService`, `GlobalLockOrder`, `InvoiceSeriesAllocator`) — see `docs/06-backend/stage-6c-sale-finalization.md` for the complete operation inventory, invariant list, and evidence trail.

**What exists**: `app/Services/Checkout/CheckoutService.php` (full orchestration) plus three resolution helpers (`FiscalInstallationResolver`, `InventoryLocationResolver`, `TaxRegistrationResolver`, each with zero/one/many rigor — no `.first()` guessing), 9 new `DomainException`/resolution-exception classes, a new migration (`inventory_locations_one_default_per_store`, with a data-preflight abort check), and 34 new tests across `tests/Database/{ModelMassAssignmentRegressionTest,CheckoutResolversTest,CheckoutServiceTest,CheckoutServiceConcurrencyTest}.php` plus a dedicated `checkout_worker.php` for real multi-process concurrency proofs.

**Governance discipline maintained throughout**: two real defects were found in already-frozen Stage 5/6A files while integrating (`Sale`/`InvoiceSeries` `$fillable` gaps, an `ElectronicJournalEntry` ULID-into-`uuid`-column defect) — each fixed as the smallest possible forward correction with a dedicated regression test, disclosed in `stage-6c-sale-finalization.md`, with **no Stage 5/6A baseline retagged**. Five owner rulings were required and recorded (fiscal-installation resolution interval, default-location-only inventory deduction, dual-idempotency confirmation, the two model corrections, and Module A held out of scope) before implementation touched the corresponding code, plus a `transaction_number` format ruling (`T-` + ULID, distinct from `Sale.id`'s UUIDv7 and `Invoice.invoice_number`'s BIR serial) closed with three dedicated tests (shape, 10-worker concurrency stress, idempotent-replay consistency).

**Two independent readiness statuses, tracked separately so one is never mistaken for the other**:
- Checkout domain/service layer: **VERIFIED** (four gates closed in a dedicated second pass: transaction_number semantics, tax-registration resolution rigor, adversarial idempotency/atomicity proof, and full semantic — not just row-count — verification of every written table).
- `POST /sales` production readiness: **BLOCKED ON MODULE A** — no controller, `FormRequest`, or route exists yet, and none will until an authenticated/authorized terminal context exists to supply `CheckoutService`'s `$terminalId`/`$cashierId` from trusted state rather than caller-controlled request fields (this constraint is recorded in `stage-6c-sale-finalization.md` §7 for whoever builds that controller).

**Full regression at this point**: `tests/Unit` 85/196, `tests/Database` 130/466, `tests/Feature` 1/1, migration round-trip (`fresh`/`reset`/`migrate`) clean, Pint clean, `scripts/validate-baselines.sh` all 12 checks pass (no frozen baseline touched by any of this).

### Where the Stage 6B boundary actually is (and why this matters)

After the independent verification pass below, a follow-up commit added `scripts/validate-baselines.sh`, formalized the Stage Baseline Rule, and corrected a stale claim in `docs/06-backend/stage-6b-invoice-series-allocation.md`. That commit sat *after* the then-current `stage-6b-baseline` tag — which raised a real question: does "a stage baseline contains all approved content through that stage" mean all of that belongs inside the seal, or is some of it legitimately stage-neutral content that comes after?

The answer is **it was mixed, and treating it as one commit was itself the mistake.** Splitting by this reconstruction's own path-ownership map:

- **`docs/06-backend/stage-6b-invoice-series-allocation.md` is Stage 6B's own artifact** (it is literally named in the path-ownership map below). Its correction — fixing a "not yet retagged" claim that was no longer true — is Stage 6B content and belongs **inside** the seal. This was pulled forward into a new Stage 6B substantive commit, and `stage-6b-baseline` was moved to the manifest commit built on top of it.
- **`scripts/validate-baselines.sh` and the Stage Baseline Rule/verification narrative are not owned by any stage.** They document and enforce properties of the *whole reconstruction* (Stages 1 through 6B), not deliverable content of Stage 6B specifically — the same way `PROJECT-MANIFEST.md` itself has never been treated as any one stage's owned content. This is genuinely **post-boundary, stage-neutral governance**:

```text
stage-6b-baseline  (all approved Stage 1-6B content, including the corrected report)
      |
      v
this commit  (repository-wide governance: validator script, Stage Baseline Rule, this note)
      |
      v
Stage 6C work starts here (from main, not from checking out stage-6b-baseline directly)
```

**Consequence for Stage 6C**: work must branch from `main` (which carries this governance commit forward), never from `git checkout stage-6b-baseline` directly — checking out the tag alone would silently drop the validator script and the Stage Baseline Rule documentation from the tree Stage 6C builds on. `stage-6b-baseline` marks *what was approved*, not *where to start coding*.

### Independent verification pass (2026-09-17, after the linearization report)

The owner accepted the linearization's evidence but withheld final sign-off pending one independent re-verification, since the operation moved every baseline tag. Performed fresh, not by re-reading the prior report:

1. **Ancestry, re-run independently**: all six `git merge-base --is-ancestor` checks re-executed from scratch — all PASS.
2. **Tag resolution, re-verified**: all seven `stage-N-baseline^{commit}` lookups re-run — all resolve as expected.
3. **Isolation, re-run independently and extended to Stage 1** (the original report only checked the five *internal* boundaries — this pass added Stage 1, which the original omitted): all checks PASS, including a fixed false positive in the validator script written for this pass (see below).
4. **Content-equivalence, re-run independently for Stage 3/4/5/6A/6B** against their respective pre-linearization reference commits (`c8a8bbe`, `515e2db`, `f036da9`, `570dd75`, `228e033`): all five diffs empty.
5. **Stage 1 documentary basis — the owner's key question, answered from evidence, not inference.** The question was whether BIR-012/013/014 were *formally determined* to be Stage 1 amendments, not merely content that happened to land there. Evidence found: (a) the commit that introduced BIR-012/013 (`b5f270b`, a Stage-2-labeled commit) explicitly states in its own message and in the document text it added — *"Added BIR-012... and BIR-013... **to the compliance register**... informational only, **no Stage 2 domain behavior changed on this basis**"* — a contemporaneous, explicit disclaimer that this was a Stage 1 (compliance register) addition, not a Stage 2 domain change; (b) `bir-reference-register.md` has been classified as Stage 1 (`docs/01-research/`) content in every version of this manifest since the very first one; (c) the first manifest commit (`5cac9f1`, 17:46) was written *after* BIR-012/013 already existed (`b5f270b`, 13:25) and still classified the file purely as Stage 1's own deliverable, with no Stage 2 caveat — the classification is contemporaneous, not a retroactive judgment made during this reconstruction. Conclusion: the Stage 1 fix is sound.
6. **Stage 5 equivalence, explicitly captured** in the Git baseline history table below, matching the treatment already given to Stage 3/4/6A/6B.
7. **Full suite re-run from an independent worktree checkout of the tag itself** (`git worktree add`, detached HEAD at `stage-6b-baseline`, not the branch used to build the reconstruction): `migrate:fresh` clean, `tests/Unit` 85/196, `tests/Database` 98/305, `tests/Feature` 1/1, Pint clean — identical to every prior run. Worktree removed after verification.
8. **Git state**: `git status --porcelain` empty; only `main` remains.
9. **External/stale-reference sweep**: no CI/CD configuration exists in this repository, so no automated pipeline depends on any baseline hash. Searched all of `docs/` for every superseded hash. Found two categories: (a) `openapi.yaml`, `api-design.md`, and `domain-model.md` cite specific stage-baseline commits in their own status headers as "built against" provenance (e.g. `api-design.md` already cited `11d594f` for `stage-2-baseline`, stale by dozens of commits *before this session ever began*) — a pre-existing, project-wide point-in-time citation convention, left unchanged and flagged here rather than silently judged out of scope; (b) `docs/06-backend/stage-6b-invoice-series-allocation.md`'s own Status header said "Not yet retagged... all five still unmoved," which was no longer true and actively misrepresented the current state — **this one was fixed, and because that file is Stage 6B's own artifact, the fix was folded into the sealed Stage 6B boundary itself** (see "Where the Stage 6B boundary actually is" above), not left in this stage-neutral commit.
10. **Backup retained**: `backup/pre-final-baseline-reconcile` is kept, per the owner's explicit instruction, at least through the start of Stage 6C work.

### Permanent repository controls added

- **[`scripts/validate-baselines.sh`](../scripts/validate-baselines.sh)** — a read-only validator checking both required invariants (ordering + isolation) for every `stage-N-baseline` pair, driven by the same path-ownership map documented below. Run it after moving any baseline tag and before creating a new one. Building it caught one real bug in the map itself: `.gitignore` had been listed as Stage 5's own path, but it actually first exists at Stage 1 (a minimal file) and is only *expanded* at Stage 5 — a path that already exists is not "introduced" by a later stage merely because that stage edits its content, the same way `domain-model.md` stays Stage-2-owned across many amendment passes. Fixed in the script; documented here so the distinction isn't relitigated.
- **Stage Baseline Rule (formalized, binding from Stage 6C onward):** *A stage baseline is an immutable repository boundary containing all approved content through that stage and no content owned by a later stage. Work for Stage N+1 must branch or continue only from the canonical Stage N baseline (in practice: from `main`, which always carries the baseline forward plus any stage-neutral governance layered after it — see above). Amendments to an earlier stage must be formally classified (which stage actually owns the changed content — not which stage's work was in progress when it was written) and, where a canonical baseline must be reconstructed as a result, every downstream baseline must be regenerated and revalidated with `scripts/validate-baselines.sh`, not spot-checked.*
- **Commit convention (formalized):** each stage boundary from Stage 2 onward is exactly two commits — one substantive (the stage's own content) and one manifest/boundary commit recording verification evidence — with the `stage-N-baseline` tag always on the manifest commit. Stage-neutral governance content (tooling, cross-cutting rules, this manifest's own narrative sections) is never folded into a stage's substantive commit merely because of when it was written; it gets its own commit, explicitly identified as sitting after the relevant boundary, exactly as this commit does.

**Disposition: Stage 6B — PERMANENTLY SEALED.** All items in the owner's verification table are satisfied, including the boundary-placement question this commit itself resolves. Stage 6C (Sale Finalization) may now begin, but only on separate, explicit instruction — this manifest update does not itself authorize starting it.

---

### Linearization narrative (2026-09-17, prior to the independent verification pass above)

The owner rejected an earlier two-part reconciliation of this baseline chain because, although its file *content* was correct, its *ancestry* was not: the InvoiceSeries amendment had been inserted after the old Stage 5 boundary (`8c8fe32`), which itself sat downstream of the original Stage 3/4/5 commits — so `stage-2-baseline` still contained Stage 3/4/5 content in its own history. Verification showed this was not new: the project's original `stage-2-baseline` (`6da07bd`, then `b7ef8fd` after the NON_VAT amendment) had always had Stage 3's commit as a git ancestor, because every stage in this project's single-branch history was amended interleaved with later stages' own work. The owner's decision: fully linearize from Stage 1 forward so that for every adjacent pair, `stage-N-baseline` is a genuine git ancestor of `stage-(N+1)-baseline`, **and** no stage's baseline contains a single path owned by a later stage.

### Reconstruction method

Each stage boundary was built by checking out the exact, already-approved file content for that stage's own paths from the previously accepted tip (tagged `backup/pre-final-baseline-reconcile`) directly onto the *previous* stage's already-reconstructed boundary — `git checkout <tip> -- <stage-owned paths>` — rather than replaying the original interleaved commit sequence. This guarantees every commit forward is a strict superset of the one before it, so ancestry and isolation are structural, not merely reviewed. No content was re-authored, redesigned, or reworded: it was relocated in the graph. This was verified at the end by diffing the final tip against the backup and finding zero difference outside `PROJECT-MANIFEST.md` itself.

One genuine gap was found and fixed *during* this process, not assumed away: Stage 1's own tagged baseline (`ac907e1`) turned out not to be Stage 1's final approved content. `docs/01-research/bir-reference-register.md` had been amended (BIR-012/013/014 added, then upgraded to HIGH confidence) during the Stage 2 discount-allocation pass — a Stage-1-owned file amended out of stage order, same pattern as every other stage. This was caught by the same content-diff verification method being used everywhere else, fixed with a dedicated commit as a child of `ac907e1`, and the entire chain was rebased onto the corrected Stage 1 boundary (a clean, zero-conflict rebase, since nothing else touches that file).

### Path-ownership map used throughout

- **Stage 1**: `docs/00-product/`, `docs/01-research/`, `docs/06-ui/`
- **Stage 2**: `docs/02-domain/`, `docs/03-architecture/erd.md` (Stage 2 content per its own status header, despite its directory)
- **Stage 3**: `docs/03-architecture/architecture.md`, `deployment.md`, `offline-strategy.md`, `decisions/ADR-*.md`
- **Stage 4**: `docs/05-api/`
- **Stage 5**: `docs/04-database/`, `database/migrations/`, `database/factories/`, `database/seeders/`, `database/scripts/`, `app/Models/`, 10 named Stage-5-owned files in `tests/Database/` (including `TerminalCredentialHashUpgradeTest.php`, added by the A0 reconstruction to complete Ruling 3's credential_hash uniqueness intent), plus the Laravel application scaffold (first required here — Stages 1–4 are pure documentation)
- **Stage 6A**: `app/Domain/Exceptions/{Concurrency,Domain,FiscalDayClosed,IdempotencyKeyReused,InvalidTaxConfiguration,RefundExceedsRemainingAmount,RefundExceedsRemainingQuantity,SaleNotVoidable,ShiftNotOpen}Exception.php`, `app/Domain/Financial/`, `app/Domain/Money.php`, `app/Domain/Quantity.php`, `app/Services/Idempotency/`, `app/Support/`, `docs/06-backend/stage-6a-transaction-foundation.md`, 3 named files in `tests/Database/` + their worker, `tests/Unit/Domain/`, `tests/Unit/Services/CanonicalRequestHasherTest.php`, `tests/Unit/Support/`
- **Stage 6B**: `app/Domain/Exceptions/InvoiceSeries{Exhausted,Resolution}Exception.php`, `app/Services/InvoiceNumbering/`, `docs/06-backend/stage-6b-invoice-series-allocation.md`, 2 named files in `tests/Database/` + their worker, `tests/Unit/Services/InvoiceNumbering/`

---

## At a glance

| | |
|---|---|
| **Current stage** | **Stage 6 — Backend Implementation** (✅ Stage 6A/6B frozen on a fully linearized, isolation-verified corpus. 🚧 Stage 6C — Sale Finalization — checkout domain/service layer VERIFIED, `POST /sales` production readiness BLOCKED ON MODULE A, not frozen/tagged) |
| **Frozen baselines** | Stage 1 (`stage-1-baseline` @ `d93a813`), Stage 2 (`stage-2-baseline` @ `5a7382a`; substantive `6289588`), Stage 3 (`stage-3-baseline` @ `78a190c`; substantive `680ac1b`), Stage 4 (`stage-4-baseline` @ `2313a16`; substantive `9adc30b`), Stage 5 (`stage-5-baseline` @ `4c16ee0`; substantive `9de98ce`), Stage 6A (`stage-6a-baseline` @ `47fa84d`; substantive `823c032`), Stage 6B (`stage-6b-baseline` @ `a667edc`; substantive `2f5e6e8` + doc-fix `a64111e`) — `main` is 4 commits ahead of `stage-6b-baseline`'s governance tip (`8af1ca8`), all Stage 6C work-in-progress, none of it tagged |
| **Next action** | Build the HTTP layer (`SaleController`/`FormRequest`/`routes/api.php`) once Module A (terminal authentication/authorization) exists to supply a trusted `$terminalId`/`$cashierId`; only then can Stage 6C be frozen and reviewed for `stage-6c-baseline`. |
| **Total ADRs** | 12 |
| **Open `BIR-REVIEW-REQUIRED` items** | 4 (unchanged) |
| **Lines of implementation code written** | 41 migrations, 34 Eloquent models, 14 factories, 3 seeders (Stage 5); Money/Quantity, FinancialCalculator, IdempotencyService, GlobalLockOrder, domain exception hierarchy (Stage 6A); InvoiceSeriesAllocator, AllocatedInvoiceNumber, 2 exceptions (Stage 6B) — all frozen. Stage 6C (uncommitted to any baseline): CheckoutService + 3 resolvers + 9 exceptions + 1 migration, 34 tests. |

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
| 6B | Backend — Invoice Series Allocation | ✅ | `stage-6b-baseline` @ `a667edc` (substantive `2f5e6e8`; boundary-owned doc fix `a64111e`) | InvoiceSeriesAllocator (row-lock allocation per ADR-004), AllocatedInvoiceNumber DTO, InvoiceSeriesExhaustedException/InvoiceSeriesResolutionException | Content-equivalence to the previously accepted `228e033` verified via empty diff. |
| 6C | Backend — Sale Finalization | 🚧 | *(not frozen — `main` @ `d2e8244`, 4 commits past `stage-6b-baseline`'s governance tip)* | `CheckoutService` (ADR-003 orchestration), `FiscalInstallationResolver`/`InventoryLocationResolver`/`TaxRegistrationResolver`, 9 new exceptions, `inventory_locations` default-uniqueness migration | **Checkout domain/service layer VERIFIED** (34 tests, full regression green) — see `docs/06-backend/stage-6c-sale-finalization.md`. **`POST /sales` production readiness BLOCKED ON MODULE A**: no controller/route/`FormRequest` exists; `CheckoutService` must receive `$terminalId`/`$cashierId` from a trusted authenticated context, not caller-controlled fields, once that controller is built. Not frozen or tagged. |
| 7 | Frontend | ⬜ | — | React SPA, cashier screen first | sitemap.md (Stage 1) is the only frontend artifact so far |
| 8 | Testing | ⬜ | — | Unit/feature/invariant test suite | invariants.md's 81 invariants are the test-case source list once this starts |
| 9 | Deployment | 🚧 | — | Docker Compose, backup/restore, deployment checklist | deployment.md was already produced ahead of schedule as part of Stage 3 |

---

## Module tracker (governing brief §3, Modules A–L)

Legend: ✅ Covered · 🚧 Partial/implicit · ⬜ Not started.

| Module | Domain (St.2) | Architecture (St.3) | API Contract (St.4) | DB (St.5) | Backend (St.6) | Frontend (St.7) | Tests (St.8) |
|---|---|---|---|---|---|---|---|
| A — Authentication & Users | ✅ | ✅ | ✅ | ✅ | ⬜ (Module A itself — now the explicit blocker for Stage 6C's HTTP layer) | ⬜ | ⬜ |
| B — Store Settings | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| C — Product Catalog | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| D — POS Cashier Screen | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ | ⬜ |
| E — Payment | ✅ | ✅ | ✅ | ✅ | 🚧 (payment sufficiency/recording implemented inside `CheckoutService`, not a standalone module yet) | ⬜ | ⬜ |
| F — Sales Transaction | ✅ | ✅ | ✅ | ✅ | 🚧 (Stage 6A frozen; `CheckoutService` orchestration VERIFIED, blocked on Module A for HTTP exposure) | ⬜ | ⬜ |
| G — Invoice | ✅ | ✅ | ✅ | ✅ | 🚧 (Stage 6B allocator frozen; `CheckoutService` now writes `invoice`/`invoice_snapshot_json`, blocked on Module A for HTTP exposure) | ⬜ | ⬜ |
| H — Cashier Shift | ✅ | ✅ | ✅ | ✅ | 🚧 (`CheckoutService` resolves/locks the terminal's open shift+fiscal_day; shift/fiscal-day lifecycle operations themselves still ⬜) | ⬜ | ⬜ |
| I — Inventory | ✅ | ✅ | ✅ | ✅ | 🚧 (`CheckoutService` writes `SALE`-type `stock_movements` against the store's default location; catalog/receiving/adjustment operations still ⬜) | ⬜ | ⬜ |
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
| `2f5e6e8` | Stage 6B substantive content (allocator) — content-identical to the previously accepted `228e033` |
| `a64111e` | Stage 6B substantive content (doc correction) — fixes `stage-6b-invoice-series-allocation.md`'s stale "not yet retagged" claim; folded into the boundary because that file is Stage 6B's own artifact, not stage-neutral |
| `a667edc` — **`stage-6b-baseline`** | Stage 6B manifest boundary — closes the full linearization. **This is the sealed boundary; it is not `main`'s tip.** |
| `3fb7c70`-equivalent → split into `a64111e`/`a667edc` above, plus the stage-neutral governance commit that follows | The original single "governance-hardening" commit mixed Stage-6B-owned content (the doc fix) with stage-neutral content (validator script, Stage Baseline Rule). Split after the owner identified the ambiguity; see "Where the Stage 6B boundary actually is" above. |
| `8af1ca8` | **Stage-neutral governance** — `scripts/validate-baselines.sh`, the Stage Baseline Rule, the commit convention, and the manifest section recording them. Sits on `main` after `stage-6b-baseline`, deliberately untagged as any stage's boundary. Stage 6C branches from here, not from the tag. |
| `a0736b7` | **Stage 6C initialization** — scope, evidence, operation inventory, proposed file-ownership map, and every gap requiring an owner ruling, written up in `docs/06-backend/stage-6c-sale-finalization.md` before any implementation code. |
| `fb757b2` | **Stage 6C substantive implementation, round one** — `CheckoutService`, `FiscalInstallationResolver`/`InventoryLocationResolver`, 8 new exceptions, the default-location migration, 20 tests, and forward corrections to `Sale`/`InvoiceSeries`/`ElectronicJournalEntry` (round-one fix). |
| `6803871` | **Stage 6C verification pass** — closed four owner-required gates: `TaxRegistrationResolver` (replacing an inline `.first()` query), an adversarial atomicity test, a `CheckoutService`-level concurrency test, and full semantic (not row-count-only) verification of the happy-path test. Also switched `ElectronicJournalEntry` to plain `HasUuids` (cleaner than round one's override). |
| `d2e8244` — *(main, not yet tagged)* | **`transaction_number` ruling finalized** — `T-` + ULID, confirmed distinct from `Sale.id` (UUIDv7) and `Invoice.invoice_number`, closed with three dedicated tests (shape, 10-worker concurrency, idempotent-replay consistency). No open items remain in any of Stage 6C's four verification gates. **No `stage-6c-baseline` tag exists — Stage 6C is not frozen.** |

**Superseded (all previous reconciliation attempts, reachable via `git reflog`/`backup/pre-final-baseline-reconcile`, no longer referenced by any tag):** the original interleaved history (`6da07bd`, `b7ef8fd`, `c8a8bbe` (as a Stage-2-ancestor-containing tag target), `40aaebd`/`515e2db`, `21bf0c2`/`8c8fe32`, `47baddf`/`48d173e`), the rejected first InvoiceSeries reconciliation (`d1bfaa7`, `f4ca1d9`), the rejected two-part reconciliation (`e1fb82a`, `f036da9`, `570dd75`, `228e033`, `ceab7d4`), and — from this same linearization — `b335a7a` (the first `stage-6b-baseline` candidate, superseded because it didn't yet include the boundary-owned doc fix) and `3fb7c70` (the original ungrouped governance commit, superseded by the `a64111e`/`a667edc`/this-commit split). `c8a8bbe` and `515e2db` remain historically meaningful as "the commit whose *content* Stage 3/4 were verified against," but are no longer any tag's target.

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
- **Disclosed Stage 6C contract gaps**: the frozen Stage 4 error catalog has no code for "no default inventory location configured," "no/ambiguous active tax registration," or the terminal/fiscal-installation setup-defect cases — `InventoryLocationResolutionException`/`TaxRegistrationResolutionException`/`FiscalInstallationResolutionException` currently surface as unhandled `RuntimeException`s rather than stable HTTP error envelopes. Deliberate (no new code invented unilaterally per the Stage 6C rulings) but a real gap a future Stage 4 amendment or the eventual HTTP layer must close before production use. See `docs/06-backend/stage-6c-sale-finalization.md` §5.
- **`POST /sales` cannot go to production until Module A exists**: `CheckoutService` accepts `$terminalId`/`$cashierId` as plain parameters by design; the future controller must resolve these from an authenticated/authorized terminal context, never from caller-controlled request fields. This is documented as a binding constraint on that not-yet-built controller, not merely a suggestion.

---

## Revision log

- **2026-09-16/17** — Stages 1–6B built up through many remediation and amendment passes on a single interleaved branch (full detail preserved in git history and in `docs/06-backend/stage-6b-invoice-series-allocation.md` §8–9). Two internal reconciliation attempts (NON_VAT amendment placement, then a two-part InvoiceSeries reconciliation) each corrected a real governance defect but the second was itself found to be structurally insufficient.
- **2026-09-17** — **Owner rejected the two-part reconciliation** (`e1fb82a`/`f036da9`/`570dd75`/`ceab7d4`): although its resulting file content was correct, `stage-2-baseline` (`e1fb82a`) still had Stage 3/4/5's original commits as git ancestors, because the reconstruction started from the old Stage 5 tip rather than the true Stage 2 boundary. The owner further identified that this pattern was not new — the project's original `stage-2-baseline` had always postdated Stage 3 in the commit graph — and required a full linearization rather than a second narrow patch, with an explicit backup (`backup/pre-final-baseline-reconcile` @ `ceab7d4`) before any further rewriting.
- **2026-09-17** — **Full baseline linearization performed and validated.** Built each stage boundary by checking out that stage's exact final-approved content from the backup tip onto the previous boundary (Stage 1 → 2 → 3 → 4 → 5 → 6A → 6B), verifying content-equivalence via empty diffs at every step and re-running the relevant test suite before advancing. **Found and fixed one genuine gap along the way**: Stage 1's own `ac907e1` tag was not Stage 1's final content (missing the later BIR-012/013/014 amendment) — fixed with a dedicated commit and the whole chain rebased onto it (zero conflicts). **Verification**: all six ancestry checks pass; all five internal isolation checks are empty; the final tip's full tree is diff-identical to the pre-linearization backup outside this manifest; migration round-trip, full Unit (85/196)/Database (98/305)/Feature (1/1) suites, every named critical test, and Pint all re-verified clean and matching pre-linearization counts exactly. `main` fast-forwarded to the final tip (`b335a7a`); `stage-1-baseline` through `stage-6b-baseline` all moved to their reconstructed hashes; scratch branches deleted.
- **2026-09-17** — **Owner withheld final sign-off pending independent re-verification** (the operation moved every baseline tag) and raised the key question precisely: were BIR-012/013/014 *formally determined* to be Stage 1 amendments, or merely content that happened to land there? Independent pass performed (ancestry, tag resolution, isolation extended to Stage 1, content-equivalence for 3/4/5/6A/6B, a full suite run from a separate `git worktree` checkout of the tag itself, git-state cleanliness, a stale-reference sweep) — full results in "Independent verification pass" above. The Stage 1 question was answered from contemporaneous evidence (the introducing commit's own disclaimer plus the first manifest's own contemporaneous classification), not inference. Added `scripts/validate-baselines.sh` and formalized the Stage Baseline Rule and the two-commit-per-stage convention, initially as one follow-up commit (`3fb7c70`) on top of `stage-6b-baseline` (`b335a7a`).
- **2026-09-17** — **Owner identified that `3fb7c70` mixed Stage-6B-owned content with stage-neutral governance content**, and that committing them together made "where the Stage 6B boundary actually is" ambiguous. Resolved by splitting: the correction to `docs/06-backend/stage-6b-invoice-series-allocation.md` (Stage 6B's own artifact per the path-ownership map) was pulled into a new Stage 6B substantive commit (`a64111e`), and `stage-6b-baseline` was moved to a new manifest commit (`a667edc`) built on top of it — superseding `b335a7a`. `scripts/validate-baselines.sh` and the Stage Baseline Rule/verification narrative, which are not owned by any single stage, remain in a stage-neutral commit (`8af1ca8`) that sits after `stage-6b-baseline` on `main`, explicitly documented as such so a future contributor branches Stage 6C from `main` (which carries the governance forward) rather than from the tag alone. Re-verified after the split: `git merge-base --is-ancestor stage-6a-baseline stage-6b-baseline` still true; `scripts/validate-baselines.sh` passes all twelve checks. **Stages 1 through 6B are now closed on a chain that is correctly ordered, correctly isolated, and has its governance boundary explicitly documented rather than left implicit.**
- **2026-09-17** — **Owner authorized Stage 6C (Sale Finalization) to begin, with an explicit first step: scope/evidence initialization before any implementation.** `docs/06-backend/stage-6c-sale-finalization.md` was written (commit `a0736b7`) covering the operation inventory (`POST /sales` only), every domain invariant Sale Finalization must enforce, the exact already-approved APIs it must call (ADR-003's transaction script, `FinancialCalculator`, `IdempotencyService`, `GlobalLockOrder`, `InvoiceSeriesAllocator`), a proposed file-ownership map, and eight flagged gaps requiring an owner ruling — including two real defects discovered in already-frozen Stage 5/6A model files (`Sale`/`InvoiceSeries` missing `$fillable` entries).
- **2026-09-17** — **Owner ruled on every flagged gap** (fiscal-installation resolution uses `[effective_from, effective_to)` semantics with exactly-one-match required; V1 checkout always deducts from the store's single default inventory location, hardened by a new partial-unique migration; the dual idempotency mechanism — `IdempotencyService` + `sales.UNIQUE(terminal_id, idempotency_key)` — is intentional defense-in-depth, not redundant; the two model defects are authorized smallest-forward-corrections, no Stage 5/6A baseline retagged; Module A/authentication is explicitly NOT Stage 6C's scope). `CheckoutService` (commit `fb757b2`) was implemented against these rulings: full ADR-003 orchestration, `FiscalInstallationResolver`/`InventoryLocationResolver`, 8 new domain exceptions, the default-location migration, and 20 tests. A further pre-existing defect was found during integration (not by inference): `ElectronicJournalEntry` used `HasUlids` against a native `uuid` column, generating Base32 strings PostgreSQL rejects — fixed as a Stage 6C forward correction.
- **2026-09-17** — **Owner withheld "complete" for the checkout service/domain layer pending four verification gates**, having identified real gaps in round one: `transaction_number`'s generation algorithm was never actually settled (only its per-store uniqueness scope was frozen, by Stage 5); the tax-registration resolution reused a naive `.first()` query instead of the same zero/one/many rigor already applied to fiscal-installation resolution; no adversarial test proved the two-layer idempotency guarantee end to end through `CheckoutService`; and the happy-path acceptance test verified row counts, not field-level semantics. All four closed (commit `6803871`): a dedicated `TaxRegistrationResolver` (with inclusive-end interval semantics determined from the table's own CHECK constraint, not copied by analogy); a sequential adversarial test forcing failure after business rows are staged, proving full rollback including the idempotency reservation itself, then a same-key retry succeeding exactly once; a new `checkout_worker.php` + `CheckoutServiceConcurrencyTest` proving the two-layer guarantee through real multi-process `CheckoutService` calls; and the happy-path test expanded to 59 field-level assertions across every written table. `ElectronicJournalEntry` was also switched from the round-one `HasUlids`-plus-override fix to plain `HasUuids` (Laravel's own UUIDv7 generator is already time-sortable, needing no custom code) per the owner's suggestion.
- **2026-09-17** — **Owner confirmed the `transaction_number` ruling** (commit `d2e8244`): `T-` + a 26-character ULID, a deliberately separate operational identifier from `Sale.id` (UUIDv7 via `HasUuids`) and `Invoice.invoice_number` (the BIR accountable-document serial, `InvoiceSeries`-allocated) — never derived from either, generated server-side only inside the authoritative checkout/idempotency execution path, never manufactured again on replay. Closed with three required tests: shape/regex validation, a 10-worker high-volume concurrency stress (matching `InvoiceSeriesAllocatorConcurrencyTest`'s established pattern), and an idempotent-replay assertion. **This closes all four verification gates with no open items.** Current disposition: **checkout domain/service layer VERIFIED; `POST /sales` production readiness remains BLOCKED ON MODULE A, not on any of these four gates.** Full regression: `tests/Unit` 85/196, `tests/Database` 130/466, `tests/Feature` 1/1, migration round-trip clean, Pint clean, `scripts/validate-baselines.sh` all 12 checks pass. **No `stage-6c-baseline` tag exists yet — Stage 6C is not frozen, and per the owner's standing instruction, Stage 6D does not begin until it is.**
