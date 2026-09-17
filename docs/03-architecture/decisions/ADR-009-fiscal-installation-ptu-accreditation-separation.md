# ADR-009: Accreditation and Permit-to-Use Are Independently Effective-Dated Within FiscalInstallation

## Status
Accepted — Stage 3, 2026-09-16.

## Context
Stage 2 froze `fiscal_installation` as a `store`-scoped entity (associated
to one or more `terminal`s via `terminal_fiscal_installation`) carrying
flat fields for `min`, `ptu_number`/`ptu_date`, `accreditation_number`/
`accreditation_date`, `software_version`, and `machine_serial_number`, with
a single `installed_at`/`superseded_at` effective-dating pair for the whole
row. [BIR-012](../../01-research/bir-reference-register.md) (RMC 72-2025,
independently verified by the owner) confirms that a taxpayer's Permit to
Use does **not** expire merely because the software's Certificate of
Accreditation expires — these are genuinely independent lifecycles that
can change on different schedules and for different reasons (e.g., a
software vendor re-accredits a new version while a specific store's PTU,
tied to their earlier-accredited installation, remains valid until they
choose to upgrade). Stage 3 must decide whether Stage 2's single flat
field set (with one shared effective-dating pair) can represent this, or
whether a refinement is needed.

## Decision
**This is a genuine architectural refinement, not a Stage 2 contradiction**
— per the process the owner specified, it is documented here rather than
silently applied, but it does not require reopening the Stage 2 domain
model, because Stage 2 already kept `fiscal_installation` as its own
entity separate from `store`/`terminal`/`shift` specifically to allow this
kind of refinement additively (Stage 2's own §2.1 rationale, and
[BIR-012](../../01-research/bir-reference-register.md)'s system-implication
note explicitly anticipated this exact review).

TindaFlow's architecture represents accreditation and PTU as two
independently effective-dated sub-histories **within** the `fiscal_installation`
concept, rather than one shared `installed_at`/`superseded_at` pair
governing both:

- **Accreditation identity/status/validity** — `accreditation_number`,
  `accreditation_date`, `software_version`, `machine_serial_number`,
  `deployment_model` — changes when the *software* is re-accredited (a new
  Certificate of Accreditation, e.g., following a major-enhancement
  re-accreditation per RMO 24-2023) or upgraded to a newer accredited
  version.
- **Permit to Use identity/status** — `ptu_number`, `ptu_date`, `min` —
  changes when the *taxpayer's* permit for a specific installation is
  issued, renewed, or reissued, independent of whether the underlying
  software's accreditation has since changed.

Concretely: `fiscal_installation` retains its accreditation-related fields
with their own `accreditation_effective_from`/`accreditation_effective_to`
pair, and gains a **second**, independent effective-dating pair
(`ptu_effective_from`/`ptu_effective_to`) governing the PTU-related fields.
A new accreditation event inserts a new "accreditation history" state
without necessarily closing out the current PTU state, and vice versa —
the two no longer share one lifecycle. (Whether this is implemented as two
columns-pairs on one table, versus two child history tables under
`fiscal_installation`, is a Stage 5 schema-design decision; the Stage 3
commitment is only that the two lifecycles are independently trackable,
not collapsed into one.)

`invoice.invoice_snapshot_json` (ADR-006) continues to snapshot whichever
accreditation *and* PTU state was in effect at issuance — this requires no
change, since the snapshot was already designed to capture "fiscal
metadata as of issuance" generically, not tied to a specific field
structure.

## Alternatives Considered
- **Leave Stage 2's single shared effective-dating pair as-is** — rejected:
  it would force an artificial choice at the moment either lifecycle
  changes (do we treat a PTU renewal as "superseding" the whole
  installation row, even though the accreditation didn't change? Do we
  lose the prior PTU's effective history?) that BIR-012 confirms does not
  reflect how these two statuses actually behave in practice.
- **Model Accreditation and PTU as two entirely separate top-level
  entities** (not sub-histories within `fiscal_installation`) — considered
  and rejected as premature normalization: both concepts are properties of
  the same underlying "installation" (the specific software+hardware
  combination in use at a terminal or set of terminals), and splitting
  them into unrelated top-level tables would lose that shared context
  (e.g., "which accreditation and which PTU apply to this terminal right
  now" becomes a join across three tables instead of one lookup). Two
  effective-dated sub-histories within one entity captures the
  independence BIR-012 requires without over-normalizing.
- **Wait until Phase 3 (formal accreditation prep) to model this at all**
  — rejected: Stage 2 already committed to keeping `fiscal_installation`
  as a distinct entity precisely to make this kind of refinement possible
  without a rewrite. Deferring the refinement itself (rather than deferring
  its *use*, which remains deferred — V1 is still not accredited and these
  fields remain mostly empty) would let a future Stage 5 migration default
  to the simpler, now-known-to-be-wrong single-lifecycle shape by
  omission.

## Consequences / Trade-offs
- **Positive:** the schema can correctly represent a real, BIR-confirmed
  scenario (PTU outliving a specific accreditation, or accreditation
  changing independent of PTU) without a future migration that splits an
  already-populated field set — safer to decide now, while the table is
  still empty, than after Phase 3 has real data in it.
- **Positive:** does not touch any Stage 2 aggregate boundary, invariant,
  or other entity — `fiscal_installation` remains exactly where Stage 2
  put it in the domain model.
- **Trade-off:** slightly more schema complexity than the single-pair
  version, for a distinction that has zero V1 user-facing effect (no V1
  screen exposes these fields as editable beyond Store/Terminal Settings
  placeholders). Accepted because the cost of adding it now (a wider
  migration, still on an empty table) is far lower than the cost of
  retrofitting it during Phase 3 accreditation prep against live data.

## Stage / Scope Affected
Stage 3 (this ADR — architectural refinement, not a Stage 2 domain change),
Stage 5 (concrete schema for the two effective-dating pairs), Phase 3
(BIR accreditation preparation — this is exactly the refinement that phase
was expected to need, delivered early while cheap).
