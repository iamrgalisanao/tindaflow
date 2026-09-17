# ADR-011: Server-Issued Terminal Credential via One-Time Enrollment Token

## Status
Accepted — Stage 3, 2026-09-16.

## Context
`terminal_id` is a load-bearing dimension throughout the frozen Stage 2
model — it gates shift concurrency, fiscal-day attribution, invoice
allocation context, stock movement attribution, and audit/journal
entries. If a browser could simply assert its own `terminal_id`, every one
of those controls would rest on an unverified client claim. Stage 3 must
define how a browser/workstation legitimately becomes "Terminal 2" rather
than trusting a request parameter.

## Decision
**Enrollment flow:**

1. An `ADMIN` (or a `MANAGER` holding the relevant capability) creates a
   `terminal` record in the back office and generates a one-time
   enrollment token for it — a high-entropy random secret with a short
   validity window (e.g., 15 minutes) and single-use semantics (invalidated
   immediately on first successful use, regardless of outcome).
2. On the physical workstation intended to become that terminal, an
   administrator navigates to an enrollment page and submits that token.
3. The server verifies the token (exists, unexpired, unused), issues a
   long-lived, terminal-scoped credential (a signed, `HttpOnly`,
   `Secure`, `SameSite=Strict` cookie is the default V1 mechanism; a
   dedicated local credential store is an equivalent Stage 6
   implementation choice), and permanently binds that credential to the
   `terminal` record's `id`.
4. Every subsequent request from that browser carries the credential; the
   server resolves `terminal_id` **exclusively** from it — never from a
   request body/query parameter/header the client sets directly. Any
   endpoint that needs "which terminal is this" reads it from the
   authenticated request context, the same way it reads "which user is
   this" from the session.

**Revocation:** an administrator can mark a `terminal`'s credential
revoked at any time (e.g., lost/replaced hardware), which immediately
invalidates that browser's ability to act as the terminal; a fresh
enrollment (new one-time token) is required to re-bind, either to the same
`terminal` record or a new one.

## Alternatives Considered
- **Trust a client-supplied `terminal_id`** — rejected outright: this is
  precisely the unverified-claim problem this ADR exists to close. Any
  cashier (or a malicious script running in a compromised browser tab)
  could then claim to be any terminal, defeating every concurrency and
  attribution control built on `terminal_id`.
- **Full client-certificate PKI (mutual TLS per terminal)** — considered
  and rejected as disproportionate: PKI adds real operational burden
  (certificate issuance, rotation, revocation-list distribution) that is
  justified for large fleets of untrusted or remotely-managed devices, not
  for a handful of terminals inside one physically-secured store where an
  administrator can walk up to the machine to enroll or revoke it.
- **Device fingerprinting** (infer terminal identity from browser/OS
  characteristics without an explicit enrollment step) — rejected:
  fingerprints are heuristic, not authoritative, spoofable, and would
  require a genuinely new "is this really terminal 2" ambiguity every time
  a workstation's fingerprint drifted (OS update, browser update) — a
  worse security and reliability posture than an explicit, administrator-
  controlled enrollment.
- **IP-address-based terminal identification** — rejected: DHCP-assigned
  addresses on a typical store LAN are not stable identifiers, and even a
  static-IP setup would tie terminal identity to network topology rather
  than to an explicit administrative decision.

## Consequences / Trade-offs
- **Positive:** every `terminal_id`-gated invariant in Stage 2 (shift
  concurrency, fiscal-day attribution, invoice-series scoping, audit
  attribution) now rests on a server-verified identity, not a client
  assertion.
- **Positive:** enrollment/revocation is a simple, administrator-visible,
  auditable action (itself worth an `audit_event` — `SETTINGS_CHANGED` or
  a dedicated event type, Stage 6 decision) — no invisible, automatic
  identity assignment.
- **Trade-off (residual risk, explicitly accepted, not ignored):** copying
  the terminal credential to a second device would let that device also
  present as the same terminal. Mitigations: the credential is
  `HttpOnly` (unreadable by JavaScript, reducing XSS-based exfiltration),
  and enrollment requires a deliberate administrator action (not
  something a cashier or an attacker can self-trigger without already
  having admin access). Detecting concurrent use of one terminal
  credential from two distinct sessions is a reasonable future
  operational enhancement, not a hard V1 requirement, because a
  duplicated terminal identity does not itself break any financial
  invariant — it still funnels through the same shift/fiscal-day/
  invoice-series locks (architecture.md §24); it would only produce a
  confusing operational picture worth investigating, not a corrupted
  ledger.
- **Trade-off:** loses a workstation → an administrator must actively
  revoke and re-enroll; there is no self-service terminal recovery, which
  is an intentional friction point (terminal identity changes should
  always be a deliberate administrative act).

## Stage / Scope Affected
Stage 3 (this ADR), Stage 5 (`terminal` credential/token schema), Stage 6
(enrollment endpoint, credential-resolution middleware), Stage 7
(administrator-facing enrollment UI).
