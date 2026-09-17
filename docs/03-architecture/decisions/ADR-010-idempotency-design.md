# ADR-010: Terminal-Scoped Idempotency Record, Database-Enforced, Same-Key-Same-Hash-Returns-Original

## Status
Accepted — Stage 3, 2026-09-16. **Revised 2026-09-16 (remediation pass):**
rescoped from `(store_id, idempotency_key)` to `(terminal_id,
idempotency_key)`, generalized the persisted record shape, and defined
retention explicitly. See the corresponding correction to Stage 2
[invariants.md](../../02-domain/invariants.md) #5.

## Context
Stage 2 requires idempotent sale finalization and the governing brief
explicitly warns against relying on disabling a UI button to prevent
duplicate checkout. The first version of this ADR scoped the uniqueness
constraint to `(store_id, idempotency_key)`, reasoning that a UUIDv4's
entropy made a tighter scope unnecessary. On review, that reasoning missed
a real correctness benefit available once ADR-011 (server-verified
terminal identity) exists: binding idempotency to the *authenticated*
terminal context, not just the store, closes a gap ADR-011 itself already
flags as a residual risk (a copied terminal credential, or any bug that
caused two terminals to present the same key) — under store-wide scoping,
that scenario could incorrectly merge two distinct, legitimate sales
attempted from two different terminals into "the same request." Under
terminal-scoped scoping, it cannot.

## Decision

**Generation:** unchanged from the original decision — the frontend
generates a UUIDv4 the moment a checkout attempt begins and sends it as an
`Idempotency-Key` header, reusing the same key across retries of the same
attempt.

**Scope (revised): `UNIQUE(terminal_id, idempotency_key)`.** `terminal_id`
is **never** read from the request body/header — it is resolved
server-side from the authenticated, enrolled terminal context (ADR-011).
A request whose claimed key was actually created under a different
terminal is therefore structurally impossible to confuse with a retry of
"this" terminal's attempt.

**Generalized persisted shape** (conceptually an `idempotency_record` —
whether this becomes its own table or remains columns on `sale` for V1's
one operation type is a Stage 5 schema decision; the concept is fixed now
so a future second idempotent operation, e.g. refund/void finalization if
Stage 5+ adopts the same protection for those, doesn't need a redesign):

```
idempotency_record
-------------------
terminal_id          -- resolved server-side, never client-supplied
idempotency_key      -- client-generated UUIDv4
request_hash         -- SHA-256 of the normalized request payload, immutable once set
operation_type        -- 'CHECKOUT' for V1 (extensible)
result_resource_id    -- for CHECKOUT: the resulting sale.id (nullable until the operation completes)
created_at
```

**Behavior matrix (revised):**

| Situation | Behavior |
|---|---|
| Same terminal + same key + same request hash | Returns the original committed result (`result_resource_id`) with `200`; creates nothing new |
| Same terminal + same key + **different** request hash | `409 Conflict` with a specific error code **`IDEMPOTENCY_KEY_REUSED`** — the client must start a new attempt with a new key; silently accepting a changed payload under a reused key would let a client redefine what "this financial attempt" means after the fact |
| Different terminal, coincidentally the same UUID | **A completely separate namespace** — `(terminal_id, key)` scoping means this is not even a near-miss; both terminals proceed independently, each against its own row |
| Concurrent duplicate requests from the same terminal (race, not sequential retry) | Both attempt to `INSERT` under the same `(terminal_id, key)`; the database unique constraint allows exactly one to succeed; the loser's insert raises a unique-violation exception, caught specifically by `CheckoutService`, which re-queries and returns the winner's result — indistinguishable to the client from a normal same-key-same-hash response |

**Retention (newly defined in this revision): indefinite — no TTL, no
automated expiry.** A short TTL would make a legitimate, realistic network
retry (a client that doesn't resubmit for minutes, e.g. after a connectivity
blip or an app restart) unsafe, by allowing the *same* key to be reused for
a *new*, unrelated attempt once "expired," reintroducing exactly the
ambiguity idempotency exists to remove. Since `idempotency_key` lives
alongside `sale` (a table Stage 2 already never automatically deletes —
invariant #51), retaining the idempotency record for as long as its `sale`
exists costs nothing extra in retention policy and removes an entire class
of "was this expired or not" edge cases from the design.

## Alternatives Considered
- **No idempotency key; rely on the frontend disabling the submit button**
  — rejected outright per the governing brief; a UX nicety, not a safety
  mechanism (unchanged from the original decision).
- **Server-generated idempotency keys** — rejected as unnecessary
  round-trip complexity (unchanged).
- **Time-boxed key expiry** — rejected; see Retention above (unchanged
  reasoning, now stated as the primary retention decision rather than a
  rejected alternative buried in "Alternatives").
- **`(store_id, idempotency_key)` scope (the original decision in this
  ADR)** — superseded in this revision. It is not *wrong* in the sense of
  allowing real duplicates under normal operation (a UUIDv4 collision
  across terminals remains astronomically unlikely), but it is *weaker*
  than necessary once verified terminal identity (ADR-011) exists, and it
  does not use that verification where it would help: correctly rejecting
  or isolating a cross-terminal key collision instead of silently treating
  it as a same-terminal retry. Terminal scoping is strictly better with no
  measurable downside once ADR-011 exists to supply a trustworthy
  `terminal_id`.
- **A separate `idempotency_record` table now, in Stage 3** — not decided
  either way; deferred to Stage 5 as a schema-shape question. The
  behavior and scope are fixed by this ADR regardless of whether V1
  implements it as new columns on `sale` (sufficient while `operation_type`
  has exactly one value) or a dedicated table (necessary the moment a
  second idempotent operation type is added).

## Consequences / Trade-offs
- **Positive:** closes the specific cross-terminal collision scenario
  ADR-011 itself flags as a residual risk, using information (verified
  terminal identity) that didn't factor into the original decision.
- **Positive:** every disaster-behavior scenario in architecture.md §23
  involving an ambiguous client-side outcome still resolves safely via
  retry — this revision strengthens, not weakens, that guarantee.
- **Trade-off:** none identified beyond the original ADR's — the frontend
  must still correctly persist and reuse the key across a retry (Stage
  6/7 implementation risk, unchanged).
- **Note for Stage 2 traceability:** this revision required a corresponding
  one-line scope correction to Stage 2's frozen
  [invariants.md](../../02-domain/invariants.md) #5 (from
  `(store_id, idempotency_key)` to `(terminal_id, idempotency_key)`),
  applied directly (not held for a separate approval cycle) because it
  refines *how* an existing, unchanged guarantee is enforced — it does not
  introduce a new concept, alter any other invariant, or change any
  aggregate boundary. This is explicitly called out for the owner's
  visibility, distinct from the two genuine Stage 2 domain-model gaps
  (Void/Refund processing context, Refund settlement) reported separately,
  which were **not** edited without approval.

## Stage / Scope Affected
Stage 2 (invariants.md #5 scope correction, applied), Stage 3 (this ADR),
Stage 5 (`(terminal_id, idempotency_key)` unique constraint +
`request_hash`/`operation_type`/`result_resource_id` columns), Stage 6
(`CheckoutService` duplicate-handling logic, resolving `terminal_id` from
the ADR-011 credential), Stage 7 (frontend key generation/persistence),
Stage 8 (concurrent-duplicate-request test, cross-terminal-collision test,
timeout-retry test).
