# ADR-008: Independent, Encrypted, Regularly-Tested Backups — a Docker Volume Is Not a Backup

## Status
Accepted — Stage 3, 2026-09-16.

## Context
TindaFlow is a self-hosted financial system holding immutable, statutorily-
retained (5-year floor, [BIR-009](../../01-research/bir-reference-register.md))
transaction and audit data, running on hardware the store owner operates
without a platform team. A single local disk failure must not be able to
destroy the store's entire sales history. The governing brief requires
"recoverable PostgreSQL data" as part of V1's Definition of Done and an
explicit backup/restore procedure at deployment time (Stage 9).

## Decision
**Mechanism:** a scheduled `pg_dump` (custom format, `-Fc`) of the
PostgreSQL database, run from a container with access to the database
network but not the database's data volume directly — i.e., logical
backup via the database's own client protocol, not a filesystem-level copy
of the Postgres data directory (which would risk capturing an
inconsistent, mid-write state without extra coordination `pg_dump` already
handles correctly via its own transactional snapshot).

**Frequency:** daily, at a low-traffic hour (configurable; default outside
typical store operating hours), plus **on-demand before every production
migration** (see ADR/architecture.md §22).

**Retention:** a rolling window — proposed default **30 daily backups
retained locally, plus 12 monthly backups retained for one year** — these
are **proposed operations targets pending owner approval**, not statutory
requirements; the underlying transaction data itself is never deleted by
this policy (backup retention governs how many *copies* of the dump are
kept, not the source data's retention, which remains indefinite per Stage
2 invariant #51).

**Storage destination — independence from the live volume is mandatory:**
at least one backup copy is written somewhere other than the same Docker
volume/disk the live PostgreSQL data lives on — e.g., a separate physical
disk on the same machine, a network share, an external USB drive rotated
off-site, or (if the store has any internet connectivity at all) an
encrypted upload to object storage. **A snapshot of the same disk the
database lives on, or a copy left in a Docker volume on that same host, is
explicitly not accepted as satisfying this requirement** — it protects
against neither disk failure nor site-level disaster (fire, theft, flood).

**Encryption:** backup archives are encrypted at rest (e.g., `gpg` symmetric
or a KMS-backed key, per the eventual Stage 9 implementation) whenever they
leave the local machine, given they contain customer TINs, transaction
detail, and business financial data.

**Restore procedure (documented, not just assumed):** a written,
version-controlled runbook (finalized in Stage 9) covering: stop the
application, restore the `pg_dump` archive into a fresh PostgreSQL
instance (`pg_restore`), run pending migrations if the backup predates the
current schema version (see architecture.md §22's schema-compatibility
note), verify row counts/checksums against a pre-restore expectation,
restart the application.

**Restore verification is not optional:** the runbook is exercised at
least once during Stage 9 (a real restore into a scratch environment,
verified against the source), and a periodic (e.g., quarterly) restore
drill is documented as an operational recommendation — a backup no one has
ever successfully restored from is not a backup, it's an unverified
hypothesis.

**RPO/RTO — proposed product/operations targets, not statutory claims,
pending owner approval:**
- **RPO (Recovery Point Objective): ≤ 24 hours** — bounded by the daily
  backup cadence above; a store willing to accept a tighter RPO can
  increase backup frequency (e.g., hourly) at the cost of more storage and
  a small, periodic performance dip during the dump.
- **RTO (Recovery Time Objective): ≤ 4 hours** for a single-server restore
  onto replacement/repaired hardware, assuming the backup archive and a
  working Docker Compose environment are both available — this is a
  target to validate empirically during the Stage 9 restore drill, not a
  guarantee.

## Alternatives Considered
- **Relying on the Docker volume alone** — rejected explicitly and by
  name in this ADR's title, per the owner's own framing: a volume is
  where live data lives, not a second, independent copy of it.
- **Continuous physical replication (streaming WAL to a standby)** —
  considered and deferred: this is the right tool for near-zero-RPO
  disaster recovery at a scale (multiple servers, dedicated ops capacity)
  V1 does not have. A single-store deployment with a daily `pg_dump` and a
  documented restore procedure meets the brief's "recoverable PostgreSQL
  data" requirement without the operational overhead of running and
  monitoring a standby replica on hardware a local IT technician
  maintains. Revisit if/when a future multi-store or high-availability
  phase justifies it.
- **Application-level "export to CSV" as the backup mechanism** —
  rejected as insufficient: CSV exports of individual reports are not a
  complete, restorable database backup (they lose referential integrity,
  constraints, and any table not covered by an existing report), and are
  a separate, already-existing V1 feature (Module L) with a different
  purpose (analysis/accounting handoff, not disaster recovery).

## Consequences / Trade-offs
- **Positive:** the store's sales/audit history survives a single-disk or
  single-machine failure, satisfying the brief's Definition-of-Done
  requirement.
- **Positive:** encryption addresses the real sensitivity of the data
  (TINs, financial detail) once a copy leaves the physical premises.
- **Trade-off:** off-site/off-machine backup requires *some* mechanism
  (USB rotation, network share, or internet upload) that the store owner
  or IT technician must actually operate — this is an unavoidable
  consequence of "self-hosted," not something software alone can fully
  automate away, though the scheduling/encryption/retention logic itself
  is automated.
- **Trade-off:** the specific 30-day/12-month retention numbers and the
  RPO/RTO targets above are proposed defaults requiring explicit owner
  sign-off before Stage 9 finalizes them — flagged, not assumed.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 9 (concrete backup container/cron implementation,
restore runbook, restore drill), ongoing operations (periodic restore
verification).
