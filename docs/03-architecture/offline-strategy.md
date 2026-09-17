# Offline Strategy — TindaFlow POS

## Status
DRAFT — Stage 3. Clarifies terminology the Stage 0/1 brief used loosely
("works without internet") into precise, architecturally-testable claims.
Supersedes no Stage 2 domain content; purely a Stage 3 architectural
clarification, referenced from [architecture.md](architecture.md) §15.

---

## 1. Terminology — three distinct claims, only one of which V1 makes

It's easy to conflate "works offline" across three genuinely different
capabilities. TindaFlow V1 makes exactly one of them:

| Claim | What it means | Does TindaFlow V1 support this? |
|---|---|---|
| **Internet-offline operation** | The store has no public internet connection, but the local TindaFlow server (and the LAN connecting terminals to it) is up and reachable. Checkout, printing, inventory, shifts, and fiscal journaling all continue normally. | **Yes — this is a core V1 requirement (ADR-002).** |
| **Server-offline checkout** | The local TindaFlow server (or its PostgreSQL database) is itself unreachable or down, but a terminal continues accepting and recording sales anyway (e.g., queued locally for later sync). | **No.** Not promised, not built, in V1. |
| **Full browser-offline transaction sync** | A terminal's browser can operate as a fully independent, disconnected node (e.g., via a service worker + IndexedDB), building a local transaction ledger that later reconciles/syncs with the server when connectivity returns. | **No.** Explicitly out of scope for V1 (project brief §9, "Full browser-offline transaction synchronization is NOT required for initial V1"). |

**Why this distinction matters:** "internet-offline" is a solved,
architecturally-guaranteed property of V1 (the checkout path never calls
out to the public internet — ADR-002). "Server-offline checkout" and
"full browser-offline sync" are fundamentally different, much harder
problems (they require a second, eventually-consistent financial ledger
living in the browser) that Stage 2's entire domain model — single
authoritative PostgreSQL instance, atomic invoice allocation, one
`invoice_series` per store — is not designed to support, and building them
would mean solving invoice-numbering, tax-registration-effective-dating,
and fiscal-day-closure conflict resolution *twice*: once for the
server-authoritative path, and once for whatever a disconnected browser
independently decided while offline. That is a materially different
product, not a Stage 3 refinement of this one.

## 2. What happens when the local server is unreachable

If a terminal's browser cannot reach the TindaFlow server (LAN failure,
server crash, PostgreSQL down — see architecture.md §23's disaster table
for the full scenario list), **authoritative checkout stops safely**:

- The POS UI detects the failed request/health check and shows a clear,
  unambiguous "cannot reach the server — checkout is paused" state — never
  a false "processing" spinner, and never a silent fallback that pretends
  the sale succeeded.
- **No second financial ledger is created.** TindaFlow does not write a
  provisional sale into IndexedDB, `localStorage`, or any other
  browser-side store with the intent of syncing it later. If it did, every
  Stage 2 invariant that depends on server-side, transactionally-locked
  state (invoice-series allocation, fiscal-day-open checks, shift
  concurrency, idempotency) would need a second, independent
  implementation for the "was offline, now reconciling" path — precisely
  the complexity Stage 2 and ADR-002 exist to avoid.
- Once the server becomes reachable again, the terminal simply resumes
  normal operation — there is no reconciliation step, no sync conflict to
  resolve, because nothing was ever recorded anywhere except the server.

This is a deliberate scope boundary, not an oversight: a store whose local
server itself fails needs a *hardware reliability and backup* answer
(ADR-008, and the store's own operational continuity planning — e.g., a
paper fallback process is an operational decision outside this software's
scope), not a second ledger inside the browser.

## 3. What browser-local storage IS used for in V1

`localStorage`/React component state may hold **non-authoritative
convenience state only**:

- The contents of an **in-progress cart before checkout finalization**
  (Stage 2 §2.11's explicitly-deferred `CheckoutSession`/`CartSession`
  concept) — so a cashier doesn't lose a half-built cart to an accidental
  tab refresh. This is convenience, not a financial record: it consumes no
  invoice number, affects no inventory, appears in no report, and is
  simply discarded/rebuilt if lost.
- UI preferences (e.g., a remembered filter on a back-office screen).

**Security note (per architecture.md §16):** cart contents in
`localStorage` may include product names/prices — not typically sensitive,
but the same-origin protections `localStorage` already provides are
sufficient for this data class. No authentication credential, session
token, or terminal-identity credential is ever placed in `localStorage`
(those use `HttpOnly` cookies specifically because they must be
inaccessible to JavaScript — see ADR-011) — this boundary is a hard rule,
not a preference.

## 4. Future options (explicitly not decided now)

If a future phase genuinely needs server-offline resilience (e.g., a store
whose local server hardware is unreliable enough that internet-offline
alone isn't sufficient), the options below exist but are **not** designed,
scoped, or committed to in V1:

- **A local standby/failover server** on the same LAN, with PostgreSQL
  streaming replication, promoted automatically or manually if the primary
  fails — this preserves "one authoritative database" (no second ledger
  to reconcile) while adding hardware resilience. Compatible with Stage
  2's model without any domain change; purely an infrastructure addition.
- **A genuinely offline-capable terminal mode**, should it ever be
  required, would need its own dedicated domain design (an explicit,
  bounded "offline sale" concept with its own reconciliation rules,
  analogous to how Stage 2 deliberately kept `CheckoutSession` a
  non-authoritative concept specifically so it *could* be extended later)
  — this is a multi-stage design effort in its own right, not a checkbox
  to enable, and is explicitly deferred rather than half-built.

Neither option is scoped for V1; they are recorded here only so a future
phase doesn't need to rediscover the trade-offs from scratch.
