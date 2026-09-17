# ADR-002: LAN-First Deployment, Internet-Optional Checkout

## Status
Accepted — Stage 3, 2026-09-16.

## Context
The governing brief requires the POS to remain usable if the store loses
internet connectivity, provided the local server/LAN remains operational,
and explicitly forbids making checkout dependent on cloud services. Many
Philippine convenience stores operate with unreliable or intermittent
internet but a stable local network (or a single machine acting as both
terminal and server). The architecture must make "internet-offline"
operation a structural property, not a best-effort fallback.

## Decision
TindaFlow's authoritative components (Laravel application, PostgreSQL
database) run on a machine physically located at the store — an on-premise
mini-PC, or a small local server — reachable by every cashier terminal over
the store's own LAN. Terminals are ordinary web browsers pointed at the
local server's address; they never talk to any cloud service to complete a
sale. See [offline-strategy.md](../offline-strategy.md) for the precise
terminology (internet-offline vs. server-offline) and
[deployment.md](../deployment.md) for the container topology.

The following require **zero** internet connectivity because they never
leave the LAN: login (after the server is reachable), barcode lookup,
cart building, checkout finalization, payment recording, invoice
allocation, invoice printing, inventory deduction, shift open/close, and
fiscal journaling. The **only** things that may ever require internet are
optional, non-authoritative conveniences (e.g., checking for an application
update, or a future cloud-backup upload) — none of which sit in the
checkout path.

A VPS deployment is also supported (per the brief's three target
environments), in which case the "LAN" is the VPS's private network to
itself plus a VPN or the terminals' own internet connection to reach it —
but the architectural property (terminals talk only to the TindaFlow
server, never to a third-party cloud service, for any authoritative
operation) is identical.

## Alternatives Considered
- **Cloud-hosted SaaS model (checkout requests routed to a remote data
  center)** — rejected: this is exactly the model competitor products
  (Loyverse, Odoo Online) use and the one the market-comparison research
  identified as a real gap for this segment (no shared LAN multi-terminal
  operation without internet). It directly contradicts the brief's
  "operation without public internet over local LAN" Definition-of-Done
  requirement.
- **Hybrid local-cache-with-cloud-authority** (terminal caches recent data,
  but the cloud is still the system of record) — rejected for the same
  reason Odoo's own offline POS mode was flagged as a cautionary example in
  the Stage 1 market comparison: it creates a window where the terminal
  believes it completed a sale that the authoritative store never
  confirmed. TindaFlow's authority is the local server, full stop.

## Consequences / Trade-offs
- **Positive:** checkout has zero external dependency; a store with no
  internet at all (only a LAN) still fully operates.
- **Positive:** no data-residency or vendor-lock-in concerns — the store's
  own data lives on the store's own hardware, aligned with the "self-hosted
  and LAN-first" product principle.
- **Trade-off:** the store owner is responsible for backup and hardware
  reliability of the local server (addressed in ADR-008/deployment.md), a
  responsibility a cloud SaaS product would otherwise absorb.
- **Trade-off:** multi-store or centralized reporting across branches
  (a Phase 2 candidate) requires an explicit synchronization design later;
  it is not solved by this architecture and is not attempted in V1.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 3 deployment.md/offline-strategy.md, Stage 9
(deployment checklist).
