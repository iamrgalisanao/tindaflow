# Module A — Authentication / Authorization / Authoritative Terminal Context: Initialization

## Status

**RULINGS CLOSED. A0 CLOSED. A1 (human authentication/session foundation) GOVERNANCE-SEALED**, after two baseline reconstructions. **A2 (authorization/capability foundation) SEALED. A3 (terminal enrollment/credential verification) GOVERNANCE-SEALED**, after a third and then a fourth baseline reconstruction (see §19a and §19b). **A4 (authoritative User+Terminal+Store request-context composition) IMPLEMENTED** — see §20; no baseline reconstruction required. A5 (HTTP integration tests) and A6 (Stage 6C checkout integration) have not started.

All nine Decision Register items are ruled (§14). One item required touching Stage 6C during the evidence pass: `CheckoutService`'s shift-resolution query was found to check `terminal_id` only, never `cashier_id` — fixed with a dedicated regression test. Both approved Stage 4/5 amendments (`terminalCookieAuth` + `RATE_LIMITED`; the `terminals.credential_hash` partial unique index) were applied via the **A0 baseline reconstruction**: `stage-4-baseline`/`stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` were rebuilt on top of them and revalidated (see `docs/PROJECT-MANIFEST.md`'s "A0 baseline reconstruction" section for the full record — old/new hashes, verification, backup tag). Stage 1/2/3 baselines are unchanged.

**A1** implemented `POST /auth/login`, `POST /auth/logout`, and `GET /auth/me` against the reconstructed, corrected contract — see §17 for the full implementation summary. A1's own sealing correction discovered a **second** genuine Stage 4 contract gap (no existing frozen error code covers a `FormRequest`'s own structural/shape validation failure) and, after an initial mistaken attempt to add it as a forward edit, folded `VALIDATION_FAILED` into a **second baseline reconstruction** — `stage-4-baseline`/`stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` all moved again (see `docs/PROJECT-MANIFEST.md`'s "A1 baseline reconstruction" for the full record). The CSRF-bootstrap flow for a fresh browser was proven against the existing `GET /` application-shell route — no new endpoint, no Sanctum.

**A2** implemented the fixed role→capability authorization model — see §18 for the full implementation summary. No frozen-corpus conflict was found (the capability vocabulary was already identical across `openapi.yaml`, `domain-model.md`, and A1's own catalog), so no baseline reconstruction was needed or performed.

**A3** implemented ADR-011's terminal enrollment/credential trust boundary — see §19 for the full implementation summary, including a real project-wide PostgreSQL session-timezone bug found and fixed along the way. `ENROLLMENT_TOKEN_INVALID` was approved by the owner as a genuine Stage 4 amendment and folded into a **third baseline reconstruction** — `stage-4-baseline`/`stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` all moved again, alongside two closeout corrections (`terminalCurrent` production exposure deferred to A4; Store-scoping for terminal management proven executable) applied before reconstruction so it happened only once. See §19a for the full closeout + reconstruction record. A follow-up review held A3 short of sealed pending explicit proof for the three remaining Store-management operations (`terminalGet`/`terminalCreateEnrollmentToken`/`terminalRevoke`); writing those tests surfaced a real defect — a cross-store 404 leaked Laravel's raw exception shape instead of the frozen envelope — resolved as a genuine fourth Stage 4 amendment, `TERMINAL_NOT_FOUND`. See §19b for the full discovery, decision, and fourth-reconstruction record.

---

## 1. Frozen source inventory

Reviewed in full for this pass:

- `docs/03-architecture/decisions/ADR-011-terminal-identity-enrollment.md` (complete)
- `docs/05-api/openapi.yaml` — `Auth`, `Terminal`, `Users` tags; `components/securitySchemes`; `Capability`/`UserRole`/`TerminalStatus` enums; `UserSummary`/`UserInput`/`TerminalSummary`/`TerminalEnrollmentToken`/`SaleFinalizeRequest`/`SaleSummary` schemas; every operation's description text for identity-resolution language
- `docs/05-api/api-design.md` §2 (Authentication), §4 (conditional capabilities), §5 (idempotency-operation list), §29 (unresolved questions)
- `docs/05-api/error-catalog.md` (full 33-row table + HTTP status semantics)
- `docs/03-architecture/architecture.md` §16 (security: sessions, CSRF, passwords, rate limiting)
- `docs/02-domain/domain-model.md` §2.2 (User), the capability catalog, the Stage 4 capability-catalog-sync note
- `docs/03-architecture/erd.md` (Store/User/Terminal/Shift relationship lines)
- `docs/06-ui/sitemap.md` (POS-mode/back-office-mode framing — Stage 1, explicitly preliminary)
- `docs/04-database/context-integrity-matrix.md`, `constraint-register.md` (cross-store/cross-terminal composite-FK hardening, checked for a User/Terminal store-mismatch rule — none found)
- `docs/06-backend/stage-6c-sale-finalization.md` §5 gate 6, §7 (Module A dependency + authentication boundary)
- Stage 5 migrations: `users`, `terminals`, `terminal_enrollment_tokens`, `sessions`
- `app/Models/User.php`, `app/Models/Terminal.php`, `app/Models/TerminalEnrollmentToken.php`
- `config/auth.php`, `composer.json` (confirming no auth package installed, default Laravel scaffold)
- `app/Http/Controllers/` (confirmed empty except the base `Controller.php`; no `app/Policies/`, no `app/Http/Middleware/`)

---

## 2. Operation inventory

Global security default (`openapi.yaml`): every operation requires `cookieAuth` (`tindaflow_session`, HttpOnly/Secure/SameSite=Strict session cookie) unless it declares `security: []`. Only `authLogin` opts out.

| operationId | Method/Path | Auth | Capability | Authoritative identity source | Idempotency | Status |
|---|---|---|---|---|---|---|
| `authLogin` | `POST /auth/login` | none (`security: []`) | — | N/A (establishes identity) | not required | NOT IMPLEMENTED |
| `authLogout` | `POST /auth/logout` | session | — | session | not required | NOT IMPLEMENTED |
| `authMe` | `GET /auth/me` | session | — | session | not required | NOT IMPLEMENTED |
| `terminalCreateEnrollmentToken` | `POST /terminal-enrollment-tokens` | session | `TERMINAL_MANAGE` | session (actor); does **not** require an enrolled terminal | not required | NOT IMPLEMENTED |
| `terminalEnroll` | `POST /terminal/enroll` | session | `TERMINAL_MANAGE` | session (actor) + one-time token in body | not required | NOT IMPLEMENTED |
| `terminalCurrent` | `GET /terminal/current` | session | none (any authenticated) | **enrolled terminal credential** (only op requiring it among Terminal-tag ops) | not required | NOT IMPLEMENTED |
| `terminalList`/`Get` | `GET /terminals[/{id}]` | session | `TERMINAL_MANAGE` | session | not required | NOT IMPLEMENTED |
| `terminalRevoke` | `POST /terminals/{id}/revoke` | session | `TERMINAL_MANAGE` | session | not required | NOT IMPLEMENTED |
| `userList`/`Create`/`Get`/`Update`/`Deactivate` | `/users...` | session | `USER_MANAGE` (all five) | session | not required | NOT IMPLEMENTED |

**Request/response schemas** (exact, `openapi.yaml`):
- `authLogin`: request `required: [email, password]`; `200 → UserSummary`; `401`/`422`/`429`.
- `UserSummary`: `{id, name, email, role: UserRole, capabilities: [Capability], active: boolean}`.
- `UserInput`: `required: [name, email, role]`, optional `password` (writeOnly).
- `TerminalEnrollmentToken`: `{token, terminal_id, expires_at}` — the plaintext token, returned exactly once.
- `TerminalSummary`: `{id, terminal_code, status: TerminalStatus, activated_at}` — notably **excludes** `credential_issued_at`/`revoked_at`, both of which exist on the migrated table and model (§7 gap).
- `UserRole` enum: `ADMIN | MANAGER | CASHIER`. `TerminalStatus` enum: `ACTIVE | INACTIVE | DECOMMISSIONED` (no `REVOKED` value — kept independent by design, see §14 Ruling 9).
- `Capability` enum (17): `SALE_VOID, SALE_VOID_APPROVE, SALE_REFUND, SALE_REFUND_APPROVE, PRICE_OVERRIDE, DISCOUNT_OVERRIDE, STOCK_ADJUST, CASH_OUT, REPORT_VIEW, STORE_SETTINGS_MANAGE, FISCAL_DAY_CLOSE, CATALOG_MANAGE, AUDIT_VIEW, JOURNAL_VIEW, USER_MANAGE, TERMINAL_MANAGE, FISCAL_CONFIGURATION_MANAGE`.

**Idempotency**: `api-design.md` §5's 14-operation mandatory list does not include any Auth/Terminal/Users operation — confirmed **not required** on any Module A endpoint.

No endpoint was invented beyond what `openapi.yaml` actually declares.

---

## 3. ADR-011 trace

**FROZEN DECISIONS** (binding, quoted from the ADR's own Decision section):

1. **Enrollment flow** (four steps, verbatim): (a) an ADMIN/capable MANAGER creates a terminal record and a one-time enrollment token; (b) an administrator submits that token on the target workstation; (c) the server verifies the token (exists, unexpired, unused), issues a durable terminal credential, and permanently binds it to `terminal.id`; (d) every subsequent request from that browser carries the credential, and the server resolves `terminal_id` **exclusively from it — never from a request body/query parameter/header the client sets directly.**
2. **One-time token semantics**: high-entropy random secret, short validity window (example given: 15 minutes), single-use — invalidated immediately on first successful use, **regardless of outcome**.
3. **Trust boundary**: "the server resolves `terminal_id` exclusively from [the credential] — never from a request body/query parameter/header the client sets directly." This is the operative rule Stage 6C's `CheckoutService` boundary already depends on.
4. **Revocation**: an administrator can revoke a terminal's credential at any time; revocation immediately invalidates that browser's ability to act as the terminal; **re-binding (same or a new terminal record) requires a fresh enrollment token** — there is no "reactivate with the old credential" path.
5. **Rejected alternatives** (explicitly, so Module A must not reintroduce them): client-supplied `terminal_id`, mutual-TLS PKI, device fingerprinting, IP-based identification.
6. **Credential-copying/cloning risk — accepted, not ignored**: copying the credential to a second device lets that device also present as the terminal. Mitigation is HttpOnly (reduces XSS exfiltration) + enrollment requiring deliberate admin action. **Detecting concurrent use of one terminal credential is explicitly "a reasonable future operational enhancement, not a hard V1 requirement"** — the ADR's own reasoning: a duplicated terminal identity funnels through the same shift/fiscal-day/invoice-series locks, so it produces operational confusion, not a corrupted ledger.

**IMPLEMENTATION DETAILS EXPLICITLY LEFT OPEN** (quoted defer language):

1. **Credential mechanism**: "a signed, HttpOnly, Secure, SameSite=Strict cookie is the default V1 mechanism; a dedicated local credential store is an equivalent Stage 6 implementation choice" — the *exact* mechanism is deferred to Stage 6 (i.e., this initialization/implementation), not fixed by the ADR beyond "cookie is the default."
2. **"Stage / Scope Affected" section, quoted in full**: "Stage 3 (this ADR), Stage 5 (terminal credential/token schema), **Stage 6 (enrollment endpoint, credential-resolution middleware)**, Stage 7 (administrator-facing enrollment UI)." — the ADR itself names "enrollment endpoint" and "credential-resolution middleware" as Stage 6 (Module A's) work, not yet built.
3. Audit-event naming for revocation ("`SETTINGS_CHANGED` or a dedicated event type, Stage 6 decision").
4. Concurrent-credential-use detection (explicitly deferred past V1).
5. **Network assumptions are NOT addressed in ADR-011 itself** — that material lives separately in `architecture.md` §16 (LAN not treated as inherently trusted; TLS terminates at nginx even for LAN-only traffic).

---

*The gaps described in §§4, 7, and 11 reflect the state discovered during the initialization pass; where applicable, they are superseded by the binding rulings in §14.*

## 4. Auth contract — answers to the required questions

1. **What authenticates a human user?** Laravel's standard session-based auth. `User` extends `Illuminate\Foundation\Auth\User as Authenticatable`; `getAuthPasswordName()`/`getAuthPassword()` are already overridden to point at the frozen `password_hash` column name (domain-model.md §2.2). Hashing: bcrypt/argon2id (architecture.md §16, Laravel default).
2. **Session or cookie based?** Yes — `tindaflow_session` cookie (HttpOnly, Secure, SameSite=Strict), backed by Laravel's database session driver (`sessions` table already migrated). CSRF via the standard double-submit-cookie mechanism (`X-XSRF-TOKEN` header from the `XSRF-TOKEN` cookie), enforced globally, no carve-out for checkout.
3. **Session state expected?** Server-side TTL/inactivity policy — **the exact duration is not in the frozen corpus**, and `api-design.md` §29 explicitly states this is "not treated as a blocking question," an operational tunable.
4. **Logout invalidates?** "Terminate the current session" (`authLogout`'s own summary) — no further mechanics specified. Logging out does **not** touch the separate terminal credential (a different credential entirely, per ADR-011) — not stated, but follows necessarily from the two credentials being independent per the ADR.
5. **`/me` obtains identity how?** From the session (`cookieAuth`), returning `UserSummary` (id, name, email, role, capabilities, active).
6. **Disabled/inactive users?** `active` boolean exists on `User`; `POST /users/{id}/deactivate` exists (no `DELETE` — users are referenced by historical audit/sale records). **What happens to an already-open session when `active` flips to `false`, and whether login itself checks `active`, is not found anywhere in the frozen corpus.** Genuine gap.
7. **Password reset/change exposed by Stage 4?** **No.** No operation exists in `openapi.yaml` for this. `config/auth.php` still references a `password_reset_tokens` table (unmodified Laravel scaffold config), but no such migration exists — this is inert scaffold, not a frozen decision.
8. **Remember-me required?** The `users` migration has `rememberToken()` (bare Laravel scaffold column) and `User::$hidden` includes it, but **zero documented behavior, endpoint, or product decision exists**. Scaffold presence ≠ a frozen requirement.
9. **MFA required?** **Not found anywhere in the corpus.** Absent, not explicitly excluded either — just never mentioned.

---

## 5. Terminal enrollment contract

**Flow** (ADR-011, §3 above) mapped onto the actual Stage 5 schema:

```text
Administrator (session, TERMINAL_MANAGE)
  → POST /terminal-enrollment-tokens {terminal_id}
  → server generates high-entropy token, stores ONLY token_hash
    (terminal_enrollment_tokens: id, store_id, terminal_id, token_hash,
     created_by, expires_at, used_at, created_at)
  → plaintext token returned exactly once in the response body
       (TerminalEnrollmentToken: {token, terminal_id, expires_at})
  → administrator, on the target workstation, submits that token:
       POST /terminal/enroll {token}
  → server verifies: exists, unexpired, used_at IS NULL
  → server issues a durable terminal credential (mechanism: cookie,
    per ADR-011's V1 default), stamps terminals.credential_hash,
    credential_issued_at
  → token's used_at is set immediately (single-use, regardless of outcome)
  → every later request from that browser resolves terminal_id from
    the credential, never from any client-supplied field
```

**One-time / stored-hashed / returned-once / revocable / re-enrollable**, per the migration's own comment (`terminal_enrollment_tokens` migration): *"Tokens are one-time, expiring, stored hashed, non-recoverable after issuance — only `token_hash` is persisted, never the plaintext token (the plaintext is returned exactly once in the API response... and never stored anywhere)."*
- **Revocable**: `terminals.revoked_at` (nullable timestamp) exists; `POST /terminals/{id}/revoke` exists.
- **Re-enrollable**: ADR-011 requires a fresh one-time token; there is no "reactivate with the old credential" path.

No QR-code or pairing UX is frozen anywhere — none invented here.

---

## 6. User vs. Terminal identity distinction

**No direct database relationship exists between `User` and `Terminal`.** The ERD (`erd.md`) has `STORE ||--o{ TERMINAL` and `STORE ||--o{ USER` as independent children of `Store`; they meet only at `shift`/`sale` (a `shift` carries both `terminal_id` and `cashier_id`). Neither `User.php` nor `Terminal.php` declares a relation to the other.

- **Can a valid user session exist without an enrolled terminal?** **Yes, explicitly.** `terminalCreateEnrollmentToken`'s own description: *"Does not itself require an enrolled terminal, since this is administered from an already-authenticated back-office session (which may or may not be running on an enrolled terminal browser)."* All Users-tag and Terminal-management-tag operations are marked `enrolled? = false` in `operation-inventory.md`.
- **Can an enrolled terminal exist/operate without a logged-in user?** Not addressed as a standalone scenario. Every terminal-scoped operation found still also requires `cookieAuth` — the two credentials are additive requirements, never substitutes.
- **Does every protected endpoint require both?** **No — it varies, and the corpus already distinguishes this per-operation** (`operation-inventory.md`'s own `enrolled?` column). `terminalCurrent`, `saleFinalize`, `saleVoid`/`saleRefund` (execution), void/refund approve/reject, shift/fiscal-day operations require the enrolled-terminal credential in addition to the session. Users/Terminal-management/Catalog-write/Reports/Audit/Journal/StoreSettings/FiscalInstallation/TaxRegistration operations do not.
- **Back-office vs. POS terminal context** is a real, already-precedented distinction at the operation level (not invented here) — see §9's classification table, which generalizes the existing `enrolled?` column into a reusable middleware boundary.

---

## 7. Authoritative context model

| Field | Source | Trust level | Client input may influence? |
|---|---|---|---|
| `user_id` | Session (`Authenticatable`) | Authoritative | Never |
| `role` | `users.role` column, read via the authenticated `User` | Authoritative | Never |
| `capabilities` | Derived from `role` via a fixed, centralized code-level table (domain-model.md §2.2: *"no dynamic role/permission tables exist in V1"*) | Authoritative | Never |
| `store_id` (actor's) | `users.store_id` | Authoritative | Never |
| `store_id` (terminal's) | `terminals.store_id`, resolved from the terminal credential | Authoritative | Never |
| `terminal_id` | Terminal credential (separate cookie/credential per ADR-011, not the session cookie) | Authoritative | Never |
| `session_id` | Laravel's internal session store primary key | Internal only — no evidence it needs to be exposed to application logic beyond the framework's own session handling | N/A |

**Required principle, already frozen and re-confirmed by Stage 6C's own boundary**: none of the above may be overridden by request JSON. `SaleFinalizeRequest`'s schema (§10 below) already omits every one of these fields from its input, and its response schema (`SaleSummary`) exposes them only as server-populated output — direct confirmation this pattern is the intended shape for every future authenticated write, not something invented for checkout alone.

**Open point, not resolved by the frozen corpus**: the actor's `store_id` (from `users.store_id`) and the terminal's `store_id` (from `terminals.store_id`) are two independently-resolved values with **no stated rule for what happens if they disagree** — flagged as a ruling requirement in §14.

---

## 8. RBAC / capability mapping

**Fixed roles**: `ADMIN | MANAGER | CASHIER` (DB CHECK-enforced). **Fixed capability catalog** (17, domain-model.md §2.2, marked as a Stage 4 vocabulary-sync addition with *"no new authorization behavior, no change to the fixed role structure... a single, centralized code-level table... No dynamic role/permission tables exist in V1"*):

| Capability | ADMIN | MANAGER | CASHIER |
|---|---|---|---|
| SALE_VOID | ✓ | ✓ | ✓ (request only) |
| SALE_VOID_APPROVE | ✓ | ✓ | — |
| SALE_REFUND | ✓ | ✓ | ✓ (request only) |
| SALE_REFUND_APPROVE | ✓ | ✓ | — |
| PRICE_OVERRIDE | ✓ | ✓ | — |
| DISCOUNT_OVERRIDE | ✓ | ✓ | — |
| STOCK_ADJUST | ✓ | ✓ | — |
| CASH_OUT | ✓ | ✓ | — |
| REPORT_VIEW | ✓ | ✓ | — |
| CATALOG_MANAGE | ✓ | ✓ | — |
| AUDIT_VIEW | ✓ | ✓ | — |
| JOURNAL_VIEW | ✓ | ✓ | — |
| FISCAL_DAY_CLOSE | ✓ | ✓ | — |
| STORE_SETTINGS_MANAGE | ✓ | — | — |
| USER_MANAGE | ✓ | — | — |
| TERMINAL_MANAGE | ✓ | — | — |
| FISCAL_CONFIGURATION_MANAGE | ✓ | — | — |

**This table is explicitly "illustrative for contract purposes" per `api-design.md`** (*"the fixed role→capability table itself is a Stage 2/6 code-level concern, not something the API exposes as configurable in V1"*) — Stage 6 (this module) may tune it without a contract change, but it is the correct starting point, not an invention.

**Per-endpoint requirement, every operation** (full table, all ~60 operations found across the entire spec, not just Auth/Terminal/Users): see the research evidence for the complete list. Summary by shape: whole-operation `x-capability` gates (`TERMINAL_MANAGE`, `USER_MANAGE`, `CATALOG_MANAGE`, `STOCK_ADJUST`, `SALE_VOID`, `SALE_VOID_APPROVE`, `SALE_REFUND`, `SALE_REFUND_APPROVE`, `FISCAL_DAY_CLOSE`, `REPORT_VIEW`, `STORE_SETTINGS_MANAGE`, `FISCAL_CONFIGURATION_MANAGE`, `AUDIT_VIEW`, `JOURNAL_VIEW`) plus **three deliberately conditional/field-level checks disclosed in `api-design.md` §4**: `PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, and `CASH_OUT` (above a threshold) are checked *inside* a shared endpoint (`saleFinalize`, `shiftCashMovementCreate`), not gated at the whole-operation level. **This conditional pattern must be preserved, not flattened into a blanket per-operation gate.**

---

## 9. Endpoint / context classification

Generalizing `operation-inventory.md`'s existing `enrolled?` column into a reusable three-way classification:

| Context | Operations |
|---|---|
| **BACK_OFFICE** (session only, terminal credential irrelevant) | Terminal management (create-enrollment-token, list, get, revoke — *not* `enroll` itself, see below), Users CRUD, Catalog writes, Inventory receipts/adjustments, Reports (all 15), StoreSettings, TaxRegistration, FiscalInstallation config, Audit/Journal view |
| **POS_TERMINAL** (session + enrolled-terminal credential both required) | `terminalCurrent`, `saleFinalize`, `saleVoid`/`saleRefund` (request), void/refund approve/reject, Shift open/close/cash-movement/X-reading, FiscalDay close |
| **BOTH** (works from either context; no terminal-credential requirement, but not exclusively back-office either) | `authLogin`/`authLogout`/`authMe`, Catalog reads (barcode lookup used at POS), Sales list/get, Invoice get/reprint |
| **Special case: `terminalEnroll`** | Session-authenticated (`TERMINAL_MANAGE`) but happens *before* any terminal credential exists on that browser — it is the operation that *creates* POS_TERMINAL context, not one that consumes it. Classify as BACK_OFFICE-authenticated, terminal-credential-agnostic by necessity. |

**No blanket "every authenticated route needs a terminal" rule is supported by the evidence** — the frozen corpus already treats this per-operation, and a single global middleware would either wrongly block back-office work or wrongly admit an unenrolled browser to checkout.

---

## 10. Persistence ownership

| Table | Classification | Notes |
|---|---|---|
| `users` | **BOTH** | Module A owns identity/CRUD; `role`/`active` read for every authorization check |
| `sessions` | **WRITE** (framework-managed) | Laravel's database session driver; no custom Module A code needed beyond `config/session.php`/`config/auth.php` wiring |
| `terminals` | **BOTH** | Module A owns enrollment/revocation lifecycle and `credential_hash`/`credential_issued_at`/`revoked_at` |
| `terminal_enrollment_tokens` | **BOTH** | Module A owns issuance/verification/single-use marking |
| `stores` | **READ** | Module A never creates/edits stores; only reads `store_id` for context resolution |
| `shifts` | **NOT MODULE A OWNED** | Per §14 Ruling 1's layered model, Module A composes identity/terminal context only; the OPEN-Shift check (`shift.cashier_id`/`shift.terminal_id` match) is Stage 6C's own transaction-context responsibility, not Module A's |
| `fiscal_days` | **NOT MODULE A OWNED** | Same reasoning as `shifts` |
| `fiscal_installations`, `tax_registrations` | **NOT MODULE A OWNED** | Module A enforces the `FISCAL_CONFIGURATION_MANAGE` capability gate on their endpoints; it does not own the tables or their business rules |

---

## 11. Error-catalog mapping

**Existing, frozen codes** (`error-catalog.md`):

| Code | HTTP | Description |
|---|---|---|
| `AUTHENTICATION_REQUIRED` | 401 | No valid session |
| `AUTHORIZATION_DENIED` | 403 | Authenticated, but lacks the required capability |
| `TERMINAL_NOT_ENROLLED` | 403 | This browser has no terminal credential |
| `TERMINAL_REVOKED` | 403 | This browser's terminal credential was revoked |

**No new public error code is invented in this pass.** Genuine absences, flagged as **CONTRACT GAP** for a future ruling/Stage 4 amendment (not filled here):
- ~~No code for a structurally-bad login~~ **RESOLVED in the A1 correction** (see §17): `VALIDATION_FAILED` (422) now covers `FormRequest`-level structural failures (missing/malformed `email`/`password`) uniformly, distinct from `AUTHENTICATION_REQUIRED` (which still covers "wrong password" and "unknown email" identically, by design, not by omission).
- No `SESSION_EXPIRED` distinct from `AUTHENTICATION_REQUIRED`.
- No `CSRF_TOKEN_MISMATCH` code.
- No `USER_INACTIVE`/account-lockout code.
- `authLogin`'s declared `429` response references `#/components/responses/TooManyRequests`, which was not found among the response definitions inspected in this pass (`BadRequest`/`Unauthorized`/`Forbidden`/`NotFound`/`Conflict`/`UnprocessableEntity` only) — flagged as a possible unresolved `$ref`, not confirmed against the full file exhaustively.
  **Initial finding**: suspected dangling `TooManyRequests` reference. **A0 result**: disproved by exhaustive OpenAPI inspection during the A0 baseline reconstruction — `components.responses.TooManyRequests` already existed and was valid. The actual, confirmed gap was narrower: no stable `error-catalog.md` code existed for the reachable `429` outcome. See §14 Ruling 6's corrected record.
- No API-visible distinction between "terminal revoked" (`revoked_at` set) and the `TerminalStatus` enum's three values (`ACTIVE|INACTIVE|DECOMMISSIONED`, none literally named `REVOKED`) — the mapping between the column and the enum is not stated.

---

## 12. Implementation file map (proposed — not created in this pass)

| File | Responsibility | Frozen source implemented | New/existing |
|---|---|---|---|
| `app/Http/Middleware/ResolveTerminalContext.php` | Reads the terminal credential, resolves `terminal_id`/`store_id`, throws `TERMINAL_NOT_ENROLLED`/`TERMINAL_REVOKED`; applied only to POS_TERMINAL routes (§9) | ADR-011 trust boundary | New |
| Laravel's built-in `auth` (session) middleware | Session authentication gate | architecture.md §16 | Existing (framework), just wired into route groups |
| `app/Services/Auth/CapabilityChecker.php` (or `Gate::define` calls in a service provider) | The single fixed role→capability table + `can(User, Capability)` check | domain-model.md §2.2 ("single, centralized code-level table... no dynamic role/permission tables") | New |
| `app/Services/Terminal/TerminalEnrollmentService.php` | Token generation/hashing, verification, single-use marking, credential issuance, revocation | ADR-011 flow | New |
| `app/Support/AuthoritativeRequestContext.php` (or similar DTO) | Composes `{user, terminal, store}` for a controller to hand to `CheckoutService` — the object that makes "never accept these from the body" enforceable in one place | Stage 6C's own documented expectation (§7 there) | New |
| `app/Http/Controllers/AuthController.php` | `login`/`logout`/`me` | `openapi.yaml` Auth tag | New |
| `app/Http/Controllers/TerminalController.php` | Enrollment-token issuance, enroll, list/get/revoke, current | `openapi.yaml` Terminal tag, ADR-011 | New |
| `app/Http/Controllers/UserController.php` | Users CRUD | `openapi.yaml` Users tag | New |
| `app/Domain/Exceptions/{TerminalNotEnrolled,TerminalRevoked,AuthenticationRequired,AuthorizationDenied}Exception.php` (final naming TBD at implementation time) | Map to the four existing error-catalog codes | error-catalog.md | New |
| `tests/Feature/Auth/`, `tests/Feature/Terminal/`, `tests/Database/Auth/` | Per §13's test matrix | — | New |

**Deliberately not proposed**: a generic `PermissionService`, a `BasePolicy` hierarchy, or a dynamic ACL framework — the frozen corpus states the capability table is fixed and code-level, so Laravel's native `Gate` abilities (or an equally small custom check) are sufficient; per-model `Policy` classes are not clearly warranted since capability checks here are role-based, not per-resource-instance authorization.

---

## 13. Required test matrix (plan only — not implemented)

| # | Test | Proves |
|---|---|---|
| 1 | Successful login | Session established, `UserSummary` returned |
| 2 | Bad credentials | `401 AUTHENTICATION_REQUIRED`, no session |
| 3 | Inactive user login/already-open session | Login for an inactive user fails; an existing session belonging to a user who becomes inactive mid-session is invalidated on its very next request (§14 Ruling 2) |
| 4 | Logout | Session terminated, subsequent request `401` |
| 5 | `/me` | Returns the authenticated user's own identity, not another's |
| 6 | CSRF rejection | State-changing request without a valid token is rejected |
| 7 | Unauthenticated protected request | `401` on every non-`authLogin` operation |
| 8 | Role/capability authorization | A role lacking a capability gets `403 AUTHORIZATION_DENIED`; a role holding it succeeds |
| 9 | Successful terminal enrollment | Token → credential issued → `terminals.credential_hash` set |
| 10 | One-time enrollment token reuse rejection | Second `POST /terminal/enroll` with the same token fails |
| 11 | Invalid enrollment credential | Unknown/malformed token rejected |
| 12 | Revoked terminal | Request from a revoked terminal's browser gets `403 TERMINAL_REVOKED` |
| 13 | User-store/terminal-store mismatch | Rejected at the POS request-context boundary (`user.store_id != terminal.store_id`) — §14 Ruling 1's Module A layer, not Stage 6C's |
| 13a | Shift belongs to a different cashier than the authenticated one | Already implemented and tested at the Stage 6C layer (`CheckoutServiceTest::test_shift_belonging_to_a_different_cashier_is_rejected`) — §14 Ruling 1's transaction-context layer; re-verify once Module A supplies a genuine authenticated cashier instead of a trusted parameter |
| 14 | Request-body terminal-ID spoof attempt | A request supplying `terminal_id` in the body is ignored/rejected in favor of the credential-resolved value |
| 15 | Request-body user/cashier-ID spoof attempt | Same, for `cashier_id`/`user_id` |
| 16 | Admin request without POS terminal context | BACK_OFFICE-classified operation succeeds with no terminal credential present |
| 17 | Checkout request without terminal context | POS_TERMINAL-classified operation (`saleFinalize`) rejected with `TERMINAL_NOT_ENROLLED` if the credential is absent |
| 18 | Multiple simultaneous sessions | Behavior TBD — not addressed anywhere in the frozen corpus; likely permitted by default (no stated restriction), needs confirmation not assumption |

---

## 14. Decision Register (RULED — closes every item from the original §14)

All nine items below were originally flagged as unresolved and have now been ruled on by the owner. None was answered by inference; each cites the evidence that shaped it. This register is binding on the Module A implementation session.

### Ruling 1 — User ↔ Terminal ↔ Store coherence (layered, not a single check)

Schema verification (not assumption) during this ruling pass: `shifts` has no `store_id` column and no composite FK tying `cashier_id`'s store to `terminal_id`'s store. `fiscal_days`, by contrast, *does* enforce terminal↔store coherence (`fiscal_days_store_terminal_fk FOREIGN KEY (store_id, terminal_id) REFERENCES terminals (store_id, id)`), so the terminal side of the chain is already fully proven — only the user/cashier side was ever open. This confirms a single per-request `user.store_id == terminal.store_id` comparison would be **necessary but not sufficient**; the correct model is layered, because each layer protects a different boundary:

```text
Module A POS request context:
  authenticated active User
  + authenticated non-revoked Terminal
  + user.store_id == terminal.store_id

Stage 6C transaction context:
  OPEN Shift
  + shift.cashier_id == authenticated user.id
  + shift.terminal_id == authenticated terminal.id
```

The future `shiftOpen` command additionally enforces `cashier.store_id == terminal.store_id` before ever creating the Shift row — a **new invariant**, not previously stated anywhere, disclosed as such rather than backdated.

**Already acted on**: the Stage 6C half of this ruling exposed a real defect in `CheckoutService` (its shift-resolution query checked `terminal_id` only, never `cashier_id`) — fixed in a dedicated correction commit with a negative regression test proving the full write-set stays empty when the shift belongs to a different cashier. See the git log for the exact commit.

### Ruling 2 — Inactive user with an existing session

`users.active` is re-checked on **every** protected request, not only at login. If a request's session belongs to a now-inactive user, that session is invalidated outright (forced logout, not merely "this one request fails") and the response is the ordinary `401 AUTHENTICATION_REQUIRED` — no distinct public outcome for "you were logged in but got deactivated." This matches the "never trust a cached/stale read, re-verify at the point of use" discipline already used everywhere else in this codebase (row locks re-validate shift/fiscal_day status at usage time, not at initial resolution).

### Ruling 3 — Terminal credential mechanism (APPROVED, with a required Stage 4 amendment)

A second cookie, distinct from `tindaflow_session`:

```text
Human:    tindaflow_session       → Laravel session authentication
Terminal: tindaflow_terminal      → opaque high-entropy credential
                                   → deterministic cryptographic digest/HMAC
                                   → stored in terminals.credential_hash
                                   → looked up every request
                                   → revoked_at checked every request
```

- Cookie name: `tindaflow_terminal` (ADR-011 does not name one; this is the smallest, most legible choice paired with `tindaflow_session`). HttpOnly, SameSite=Strict; `Secure` mandatory in production.
- The raw credential is returned/set exactly once (at successful enrollment) and never stored server-side — same discipline already used for enrollment tokens.
- **Hashing algorithm**: ADR-011 does not specify one, so this is a Stage 6 (Module A) choice, not an amendment to the ADR. Because `credential_hash` is used for **direct equality lookup** (not verification of a low-entropy secret against a slow adversary, the way a login password is), it must **not** use `Hash::make()`/bcrypt — a deterministic digest is required for the lookup to work at all, and a bcrypt hash of a *high-entropy* random credential (unlike a human password) gains no meaningful security benefit from bcrypt's slowness, only cost. Use a deterministic cryptographic digest or HMAC over the raw credential (e.g. SHA-256), matching the same pattern `terminal_enrollment_tokens.token_hash` already uses.
- **Stage 5 schema gap confirmed by direct inspection, not assumed**: `terminals.credential_hash` has **no index of any kind**, let alone a unique one — unlike `terminal_enrollment_tokens.token_hash`, which already has `$table->unique('token_hash')`. **Approved as a required forward-fix, sequenced into A0 (not A3)** — a Stage 4/5 amendment discovered during initialization must land before any Module A code, exactly like the other A0 items, or A3 would be implementing credential verification on top of a database not yet amended to support it (the same "implementation on stale frozen corpus" problem already cleaned up earlier in this project). A **partial unique index**, not a plain unique constraint, per the owner's refinement — explicit about the pre-enrollment `NULL` state:
  ```sql
  CREATE UNIQUE INDEX terminals_credential_hash_unique
  ON terminals (credential_hash)
  WHERE credential_hash IS NOT NULL;
  ```
  This still gives the credential lookup (`hash presented credential → look up by credential_hash → check revoked_at IS NULL → establish Terminal`) an efficient unique path; no separate index on `revoked_at` is needed, since it's checked as an ordinary predicate against the one row the unique index already finds.

  **Correction from an earlier draft of this document**: this is *not* the same category as the `Sale`/`InvoiceSeries`/`ElectronicJournalEntry`/`inventory_locations` forward-fixes, and it *does* require folding into the Stage 5 reconstruction (not a standalone forward-fix, not a separate reconstruction either — see below). The distinguishing test isn't "new file vs. edited file" (that test was too coarse); it's **whether Stage 5 already declared the design intent this migration merely completes, or whether the invariant is genuinely new, introduced by a later stage's own needs**:
  - `terminals`' own migration comment already states: *"ADR-011: a terminal's identity is a server-issued credential"* — and the sibling `terminal_enrollment_tokens.token_hash`, created in the very same Stage 5 pass for the very same ADR-011 purpose, already has `$table->unique('token_hash')`. `credential_hash` missing its own unique index is Stage 5 failing to finish implementing its *own already-declared* intent — a genuine Stage 5 completion gap, squarely Stage-5-owned.
  - By contrast, `inventory_locations`' migration comment says only *"unchanged from revision 1"* — no comparable declared uniqueness intent exists anywhere in Stage 5 for "one default location per store." That invariant was genuinely new, discovered by Stage 6C's own resolution needs, not a Stage 5 oversight — which is why `inventory_locations_one_default_per_store_constraint` correctly stayed a standalone Stage 6C forward-fix with no baseline retag.
  - A partial unique index also isn't dismissible as a cosmetic implementation detail regardless of origin: it genuinely changes which database states PostgreSQL will accept (two terminals sharing one `credential_hash` was legal before, illegal after) — exactly the kind of thing "Stage 5 owns" is supposed to mean.

  **Consequence**: apply this migration during the Stage 5 replay step of the Stage 4 amendment's required reconstruction (below) — it does not need a *second*, separate reconstruction pass, since Stage 4's amendment already forces a Stage 5 replay regardless. See "Batched Stage 4 amendment" for the combined sequence.
- **Required Stage 4 amendment** (batched with Ruling 6's fix, applied together, not yet applied — see "Batched Stage 4 amendment" below): add a `terminalCookieAuth` security scheme, and require it **conjunctively** (AND, not OR) with `cookieAuth` on every `POS_TERMINAL`-classified operation (§9). Per the OpenAPI 3.1 spec, two schemes inside the *same* Security Requirement Object are AND'd together; two separate entries in the `security` array are OR'd (alternatives) — the correct shape is:
  ```yaml
  security:
    - cookieAuth: []
      terminalCookieAuth: []
  ```
  never two separate list entries, which would wrongly mean "either credential alone is sufficient."

### Ruling 4 — Which endpoints require terminal context (CONFIRMED, not new)

The BACK_OFFICE / POS_TERMINAL / BOTH classification already in §9 stands — it is what `operation-inventory.md`'s own `enrolled?` column already encodes, not an invention of this pass.

### Ruling 5 — Session/CSRF implementation (APPROVED)

Laravel's built-in `web` middleware group (session + `VerifyCsrfToken`) directly, applied to these routes. **No Sanctum, no other auth package** — `composer.json` confirms none installed, and the frozen design is explicitly same-origin (`api-design.md`), which is exactly what the default session guard handles without an SPA-token layer. Caution carried into implementation: Laravel's `routes/api.php` (which does not exist yet) is conventionally wired to the **stateless** `api` middleware group by default — Module A's routes must not be dropped into that group unmodified, or session authentication and CSRF will silently stop working. They need the `web` group's behavior even if physically declared in a file named `api.php`.

### Ruling 6 — `RATE_LIMITED` catalog completion (APPROVED and APPLIED, corrected post-A0)

The initialization pass initially reported `authLogin`'s `429` `#/components/responses/TooManyRequests` reference as dangling, and approved defining that response as part of the fix.

The A0 baseline reconstruction verified against the complete `openapi.yaml` that `components.responses.TooManyRequests` already existed and was valid (standard error envelope, same shape as the other six reusable responses). **No reusable response component was added or amended.**

The actual Stage 4 contract gap was narrower: the reachable HTTP `429` outcome had no corresponding stable `error-catalog.md` code.

**Approved and applied amendment**: `RATE_LIMITED` → HTTP 429, added to `error-catalog.md`. `RATE_LIMITED` is emitted by the login-throttling middleware/rate limiter directly; it needs no domain exception class in the business layer. Exact attempts/window thresholds remain internal configuration, not part of the public contract.

This is recorded as a correction, not a retraction: the amendment procedure worked as intended — a suspected defect was investigated, and the approved fix was narrowed once better evidence (A0's exhaustive inspection) showed part of it was unnecessary.

### Ruling 7 — Password policy (APPROVED, wording corrected)

Internal server-side validation only, no Stage 4 contract change. Correction to the original framing: a minimum-length rule (e.g. 8 characters) is **an explicit application choice for V1**, not "a Laravel default" — Laravel ships no password-complexity rule out of the box; whatever minimum is chosen is this project's own decision to make and can change later without a contract amendment, since failures already return the existing generic `422 UnprocessableEntity`.

### Ruling 8 — Terminal revoked/disabled semantics (APPROVED)

Revocation takes effect on the terminal's very next request — the same `credential_hash` lookup already re-checks `revoked_at IS NULL` every time, so there is no separate cache to invalidate. The human session on that same browser is untouched: back-office operations continue to work from it, only `POS_TERMINAL`-classified operations fail with `TERMINAL_REVOKED`. Recommended (not required): clear the `tindaflow_terminal` cookie client-side whenever a response carries `TERMINAL_REVOKED`, so the browser doesn't keep presenting a credential the server will only ever reject.

### Ruling 9 — Remaining error-catalog gaps (triaged)

- Password-reset code: not applicable — no such flow exists in the contract.
- `TerminalSummary` missing `credential_issued_at`/`revoked_at`: **deferred**, not blocking Module A or Stage 6C. Revisit as a Stage 4 amendment when back-office terminal-management UI is built (Stage 7).
- Missing `terminalCookieAuth` scheme: resolved by Ruling 3. Missing `RATE_LIMITED` catalog code for the (never actually dangling) `429` response: resolved by Ruling 6.
- `revoked_at` vs. `TerminalStatus` enum: kept independent by design — `TERMINAL_REVOKED` is derived from `revoked_at IS NOT NULL` directly, never from a `status` enum value. No new enum value invented.

### Batched Stage 4 amendment (APPROVED in substance; NOT YET APPLIED) — requires full baseline reconstruction, not a tag move

Per explicit instruction: **do not retag or rewrite Stage 4 history yet.** Both fixes are recorded here as one combined, approved amendment to apply together in a single pass (avoiding a second reconciliation later, the same lesson already learned from the Stage 2/5 InvoiceSeries amendment earlier in this project):

1. Add `terminalCookieAuth` (`apiKey`, in `cookie`, name `tindaflow_terminal`) to `components/securitySchemes`; require it conjunctively with `cookieAuth` on every `POS_TERMINAL`-classified operation (§9's list).
2. Add `RATE_LIMITED` (429) to `error-catalog.md`. (Originally planned to also define `components/responses/TooManyRequests`; A0's exhaustive OpenAPI inspection found that response already existed and valid, so nothing further was needed there — see §14 Ruling 6's corrected record.)

Both are purely additive at the API-behavior level — no existing operation's request/response shape changes, and no already-frozen behavior is altered. **But unlike the Stage 5 `credential_hash` fix above, this amendment modifies the actual content of existing frozen Stage 4 files (`openapi.yaml`, `error-catalog.md`), not merely adds new ones.** Confirmed by direct check: `git merge-base --is-ancestor stage-4-baseline stage-5-baseline` is currently true. If this amendment is committed forward of `main`'s current tip (which is necessarily where Module A's own work starts) and `stage-4-baseline` is then simply moved to point at it, `stage-4-baseline` would become a **descendant** of `stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` — inverting the required ancestry chain. This is the identical structural defect the Stage 2/InvoiceSeries linearization existed to fix, and the Stage Baseline Rule it produced applies here without exception: *"where a canonical baseline must be reconstructed as a result, every downstream baseline must be regenerated and revalidated."*

**Required mechanism, therefore, at the start of A0** (not a simple tag move) — this single reconstruction also carries the `credential_hash` partial-unique-index migration (above), since Stage 5 is being replayed forward regardless and there is no benefit to a second, separate pass for it:
1. Cherry-pick the Stage 4 amendment's diff onto the *current* `stage-4-baseline` (`2313a16`) directly — this becomes the new `stage-4-baseline`.
2. Replay `stage-5-baseline`'s substantive content (`9de98ce`) onto it, **then apply the `terminals_credential_hash_unique` partial-unique-index migration as an additional commit at this same boundary** — expected to be a clean, zero-conflict cherry-pick for the replay itself, since nothing in Stage 5 touches `openapi.yaml`/`error-catalog.md` (the same zero-conflict property the Stage 6A replay had onto the amended Stage 5); the new migration is then genuinely new Stage 5 content, applied here rather than later.
3. Validate this boundary (`scripts/validate-baselines.sh` + migration round-trip + full regression) and create the new `stage-5-baseline` on it.
4. Replay `stage-6a-baseline`'s substantive content (`823c032`), then `stage-6b-baseline`'s (`2f5e6e8` + `a64111e`), in turn, validating and re-tagging each boundary in sequence exactly as the original linearization did.
5. Replay every post-`stage-6b-baseline` commit currently on `main` (the Stage 6C implementation, the CheckoutService cashier/shift correction, and every Module A initialization/Decision-Register commit — i.e., everything from `8af1ca8` through whatever `main`'s tip is at the moment A0 begins) onto the reconstructed chain, so none of that work is lost.
6. Only then fast-forward `main` and move `stage-4-baseline`/`stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` to their reconstructed hashes.

This is real, non-trivial work — smaller in scope than the original Stage 2 linearization (one small, purely-additive contract diff plus one small schema migration to replay forward through three stages, instead of a multi-amendment domain/schema change), but the same mechanism, not a shortcut. Refer to `main`'s tip **at the time A0 actually begins**, not to any specific hash recorded in this document — this document is itself one of the commits that gets replayed in step 5, so a hash frozen into its own text would go stale the moment a further commit lands before A0 starts.

---

## 15. Module A implementation sequence (derived from evidence)

```text
A0. One reconstruction, carrying both approved frozen-corpus amendments,
    before any Module A code. Final shape (owner-specified, correcting
    two earlier drafts of this document — see SS14's "Batched Stage 4
    amendment" for the full reasoning):

     1. Preserve the current main tip (tag it, e.g. backup/pre-module-a-a0,
        before any rewriting -- same discipline as backup/pre-final-baseline-reconcile).
     2. Start from the CURRENT stage-4-baseline (2313a16).
     3. Apply the approved Stage 4 amendment -- actually applied by A0:
        terminalCookieAuth security scheme (ANDed with cookieAuth on
        every POS_TERMINAL operation, SS9's list) + RATE_LIMITED (429)
        in error-catalog.md. TooManyRequests already existed and
        required no modification (SS14 Ruling 6, corrected post-A0).
     4. Create the new stage-4-baseline on this commit.
     5. Replay Stage 5's substantive content (9de98ce) onto it.
     6. Apply the terminals.credential_hash partial-unique-index migration
        HERE, as further Stage 5 content at this same boundary -- not a
        separate forward-fix, not a second reconstruction pass. It
        qualifies as genuine Stage 5 content (not a later stage's
        addition, unlike inventory_locations' own forward-fix) because
        Stage 5's own terminals migration already declares the ADR-011
        design intent this index merely completes, evidenced by
        terminal_enrollment_tokens.token_hash already having the unique
        index credential_hash was missing.
     7. Validate (scripts/validate-baselines.sh + migration round-trip +
        full regression) and create the new stage-5-baseline.
     8. Replay Stage 6A's substantive content (823c032); validate; create
        the new stage-6a-baseline.
     9. Replay Stage 6B's substantive content (2f5e6e8 + a64111e);
        validate; create the new stage-6b-baseline.
    10. Replay every post-stage-6b-baseline commit on main at the time A0
        begins (the Stage 6C CheckoutService implementation, the
        cashier/shift correction, and every Module A initialization/
        Decision-Register commit) onto the reconstructed chain.
    11. Validate the full ancestry chain (all six adjacent-pair checks)
        and boundary isolation (scripts/validate-baselines.sh) one more
        time at the final tip.
    12. Fast-forward main; move all four tags to their reconstructed
        hashes; delete scratch branches; keep the backup tag until A1's
        first commit has been validated against the reconstructed chain,
        per the same disposal-point discipline used for
        backup/pre-final-baseline-reconcile.

    Only after step 12 does A1 begin.

A1. Authentication/session foundation
    — login/logout/me, session config, password hashing (already
      structurally supported by User::getAuthPassword()), CSRF wiring.
      No terminal concept needed yet; this alone unblocks every
      BACK_OFFICE-classified endpoint's authentication requirement.
      Includes §14 Ruling 2 (re-check `active` on every request, not
      just at login).

A2. Authorization/capability foundation
    — the fixed role→capability table + Gate-based check. Depends on
      A1 (needs an authenticated User to check a role against).

A3. Terminal enrollment and credential verification
    — TerminalEnrollmentService (token issuance/verification/
      single-use), the enrollment endpoints, ResolveTerminalContext
      middleware, per §14 Ruling 3's exact design (tindaflow_terminal
      cookie, deterministic digest/HMAC, never Hash::make()), built
      against the credential_hash partial unique index A0 already
      established. Independent of A2's capability logic except that
      terminalCreateEnrollmentToken/terminalEnroll/etc. themselves
      require TERMINAL_MANAGE (so A3 depends on A2 for its own gating,
      even though its OUTPUT — terminal context — is what A4 composes).

A4. Authoritative request-context composition
    — the DTO/service that hands {user, terminal, store} to a
      controller, enforcing §14 Ruling 1's Module A layer
      (`user.store_id == terminal.store_id`) explicitly, with the
      non-negotiable rule that none of these fields may be read from
      the request body. This is the component Stage 6C's own
      documentation names as its unblock condition.

A5. HTTP integration tests
    — the full matrix in §13, run against A0-A4 before touching
      Stage 6C's controller at all.

A6. Stage 6C checkout integration
    — build SaleController/SaleFinalizeRequest/routes/api.php (using
      the `web` middleware group per §14 Ruling 5, never the default
      stateless `api` group), wiring A4's authoritative context into
      CheckoutService::finalize() exactly as stage-6c-sale-finalization.md
      §7 already specifies. CheckoutService's own transaction-context
      layer (§14 Ruling 1's OPEN-Shift/cashier check) is already built
      and tested — re-verify it once fed a genuine authenticated
      cashier instead of a trusted parameter.
```

This order is derived from dependency evidence (A2 needs A1's authenticated user; A4 needs both A2's capability check, for the endpoints that require one, and A3's terminal resolution; A6 is Stage 6C's own stated unblock condition), not merely stylistic preference. A0 is placed first because every later step's tests and code should target the corrected contract, not the one missing a `RATE_LIMITED` catalog code and an unmodeled second credential.

---

## 16. Stage 6C unblock criteria

`POST /sales` becomes production-ready only when **all** of the following hold, cross-checked against `stage-6c-sale-finalization.md`'s own stated expectations:

1. **A1 DONE.** An authenticated user's identity is trustworthy, including live re-verification of `active` on every request, not just at login (A1, §14 Ruling 2) — implemented (§17); still pending A6's re-verification once fed real Stage 6C routes.
2. **A3 DONE for terminal identity itself; A4's composition still pending.** Authoritative terminal context exists and cannot be spoofed (A3/A4, ADR-011's trust boundary enforced in code via the `tindaflow_terminal` credential — §14 Ruling 3 — not just documented) — implemented (§19); `user.store_id == terminal.store_id` composition remains A4's responsibility, deliberately not implemented in A3.
3. Store context cannot be spoofed: `user.store_id == terminal.store_id` is enforced at the POS request-context boundary (A4), **and** `CheckoutService`'s own OPEN-Shift/cashier-match check (already implemented and tested) is re-verified once fed a genuine authenticated cashier — the two are layered per §14 Ruling 1, neither substitutes for the other.
4. **A2 DONE.** Role/capability checks are available and enforced (A2) for every operation that needs one, preserving the conditional field-level checks (`PRICE_OVERRIDE`/`DISCOUNT_OVERRIDE`/`CASH_OUT`) rather than flattening them — implemented (§18); no route exists yet that actually needs the whole-operation gate in production (Stage 4's Catalog/Terminal/User endpoints are unimplemented), proved instead via a test-only route and the Gate/OpenAPI test suites.
5. A `SaleController`/`saleFinalize` HTTP handler can call `CheckoutService::finalize($trustedTerminalId, $trustedCashierId, $idempotencyKey, $validatedPayload)` without ever reading `terminal_id`/`cashier_id`/`user_id`/`store_id` from the request body — exactly as `stage-6c-sale-finalization.md` §7 already specifies.
6. Authentication/authorization failures map to the existing frozen codes (`AUTHENTICATION_REQUIRED`, `AUTHORIZATION_DENIED`, `TERMINAL_NOT_ENROLLED`, `TERMINAL_REVOKED`) plus the one new, approved code (`RATE_LIMITED`, §14 Ruling 6) — no further new code invented without going through the amendment procedure.
7. **A3 DONE.** Terminal identity/enrollment behavior matches ADR-011 exactly (one-time tokens, hashed storage, revocation semantics), using the specific credential mechanism and hashing discipline ruled in §14 Ruling 3 (deterministic digest/HMAC, never `Hash::make()`) — implemented (§19).
8. The `Idempotency-Key` HTTP header reaches `CheckoutService`'s existing `IdempotencyService` integration unchanged — Module A must not interpose any additional idempotency logic of its own on top of Stage 6A's already-frozen mechanism.
9. **DONE.** The batched Stage 4 amendment (§14, A0) has been applied and `stage-4-baseline` moved, so the HTTP layer is built against the corrected contract, not the one missing a `RATE_LIMITED` catalog code and an unmodeled second credential.

Until all nine hold and are demonstrated by passing tests (not merely implemented), `POST /sales` remains not production-ready, and Stage 6C remains unfrozen.

---

## 17. A1 implementation summary (human authentication/session foundation)

**Scope delivered**: `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` — exactly these three, against the corrected (post-A0) `openapi.yaml` contract. No terminal enrollment, no RBAC/capability *enforcement*, no authoritative User+Terminal context, no user-management endpoints, no Stage 6C HTTP integration — all remain A2–A6.

**Files added**:
- `app/Http/Controllers/AuthController.php` — `login`/`logout`/`me`.
- `app/Http/Requests/LoginRequest.php` — whitelists exactly `{email, password}`.
- `app/Http/Middleware/EnsureUserIsActive.php` — Ruling 2's active-revalidation boundary.
- `app/Http/Middleware/AssignRequestId.php` — api-design.md §6's `request_id`/`X-Request-ID`, needed by every error envelope and not previously wired anywhere.
- `app/Http/Resources/UserSummaryResource.php` — the single `UserSummary` serializer (`public static $wrap = null`, since the frozen schema is a bare object, not `{"data": ...}`).
- `app/Services/Auth/RoleCapabilityCatalog.php` — the fixed role→capability projection from api-design.md's table, shared so login/`me` never drift and so A2 has one place to extend for its own `Gate` checks.
- `app/Services/Auth/LoginRateLimiter.php` + `config/tindaflow.php` — the named `login` rate limiter's key/limit, centralized (no magic numbers in the provider registration or tests).
- `tests/Database/AuthenticationSessionTest.php` (15 methods / 53 assertions) + `tests/Unit/Services/Auth/RoleCapabilityCatalogTest.php` (4 methods / 13 assertions).

**Files changed**:
- `app/Providers/AppServiceProvider.php` — registers the `login` named rate limiter.
- `bootstrap/app.php` — prepends `AssignRequestId`; wires three exception renderers (see below).
- `config/session.php` — `cookie` defaults to `tindaflow_session` (was the app-name-derived Laravel default), `same_site` to `strict` (was `lax`), `secure` to `true` unless `local`/`testing` (was always `null`/false unless explicitly set).
- `routes/web.php` — the three routes, prefixed `/api/v1`.
- `.env.example` — documents the new (optional) `SESSION_COOKIE`/`SESSION_SAME_SITE`/`SESSION_SECURE_COOKIE`/`LOGIN_THROTTLE_*` overrides.

**Route/middleware topology**: all three routes live in `routes/web.php` (registered via `withRouting(web: ...)`, so they run through Laravel's default `web` middleware group — session + CSRF already included, per Ruling 5 — never the stateless `api` group), under `Route::prefix('api/v1')` to match the OpenAPI server base path. `login` additionally carries `throttle:login`; `logout`/`me` carry `['auth', EnsureUserIsActive::class]`.

**Login/session mechanics**: `Auth::guard('web')->attempt($credentials)` (Laravel's own password-hashing facility); on success, an `active` check runs — if false, the just-established session is torn down (`logout()` + `session()->invalidate()` + `session()->regenerateToken()`) and the SAME generic 401 is returned as a wrong password, never revealing the credentials were otherwise correct. `session()->regenerate()` runs on every real success (session-fixation protection — proven by a dedicated test comparing the pre- and post-login session cookie values).

**Active-user revalidation**: `EnsureUserIsActive` runs after `auth` on every protected route; if the resolved user's `active` is false, it invalidates the session and throws `AuthenticationException` (rendered identically to any other 401) — proven by a test that flips `active` mid-session and confirms both the immediate rejection and that reactivating the user does *not* resurrect the old session (genuine invalidation, not a live-only check).

**Session invalidation on logout**: `logout()` + `session()->invalidate()` + `session()->regenerateToken()`; only the current session is affected — a second concurrent session for the same user is untouched (multiple simultaneous sessions remain allowed by default, per §14 Ruling 5's scope and the absence of any single-session-enforcement ruling).

**CSRF**: enforced by Laravel's default `web`-group `PreventRequestForgery` middleware on every state-changing route, login included — no exemptions, no Sanctum. (Laravel bypasses CSRF automatically whenever `APP_ENV=testing`, which is phpunit.xml's default for every suite; the CSRF tests force this off via `$this->app->instance('env', 'production')`, to exercise real enforcement.)

**CSRF bootstrap for a fresh browser** (verified, not assumed): before any session exists, a browser has no `XSRF-TOKEN` to send on its first `POST /auth/login`. `PreventRequestForgery::handle()` queues the `XSRF-TOKEN` cookie on **every** response that passes through the `web` group — success, failure, GET or POST — via `shouldAddXsrfTokenCookie()`/`addCookieToResponse()`, which run unconditionally after `$next($request)`. `routes/web.php`'s existing `GET /` application-shell route already sits in that group, so it already performs this role: no new endpoint, no `/sanctum/csrf-cookie`, no Sanctum. A dedicated end-to-end test (`test_a_fresh_browser_can_bootstrap_csrf_from_the_application_shell_before_logging_in`) proves the full flow with a genuinely cookie-less client and real CSRF enforcement forced on: `GET /` → receive `XSRF-TOKEN` + the session cookie it belongs to → present `X-XSRF-TOKEN` (the raw cookie value, undecoded by the client, exactly as Laravel's double-submit-cookie mechanism expects) alongside that session cookie → `POST /api/v1/auth/login` succeeds.

**Rate limiting**: named limiter `login`, keyed by `lower(email)|ip` (participates identically for real and nonexistent emails, and one abusive IP cannot lock out every other user sharing it), `Limit::perMinutes(decay_minutes, max_attempts)` with V1 defaults `max_attempts=5`, `decay_minutes=1` (`config/tindaflow.php`, overridable via `LOGIN_THROTTLE_MAX_ATTEMPTS`/`LOGIN_THROTTLE_DECAY_MINUTES`). Exceeding it throws Laravel's own `ThrottleRequestsException`, rendered as `429 RATE_LIMITED`. No explicit clear-on-success: "eventually decays" is satisfied by the window's natural expiry, which avoids depending on `ThrottleRequests`' internal (hashed, undocumented) cache-key format.

**Exception rendering** (`bootstrap/app.php`, wired for the first time — no controller/route existed before this pass to need it):
- `App\Domain\Exceptions\DomainException` → its own `toErrorEnvelope()`/`httpStatus()` (built during Stage 6C, never previously rendered).
- `Illuminate\Auth\AuthenticationException` → `401 AUTHENTICATION_REQUIRED`. Covers no/invalid session, bad login credentials, inactive-at-login, and inactive-session-on-next-request identically, per §13 — no `INVALID_CREDENTIALS`/`USER_INACTIVE`/`SESSION_EXPIRED` invented.
- `Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException` → `429 RATE_LIMITED`.
- `Illuminate\Validation\ValidationException` → `422 VALIDATION_FAILED` (A1 correction, below).

**A1 correction (post-report): the frozen envelope for structural login-validation failures.** The first A1 pass left `LoginRequest`'s own structural validation failures (missing/malformed `email`/`password`) rendering via Laravel's default `{message, errors}` shape, disclosing it as the same "structurally-bad login" gap §11 had already flagged and deferred. The owner correctly identified this as a genuine A1 contract defect, not an acceptable deferral: `authLogin`'s `422` is part of the frozen contract, and every other `422` across the entire `openapi.yaml` (checked exhaustively, not sampled) already references the same generic `Error`/`UnprocessableEntity` envelope — no operation has bespoke structural-validation semantics. **Resolution**: one new stable code, `VALIDATION_FAILED` (422, `error-catalog.md`), rendered by a single centralized `ValidationException` handler in `bootstrap/app.php` — not a per-`FormRequest` `failedValidation()` override — so every current and future `FormRequest` gets the frozen envelope automatically. §11's gap is now marked resolved rather than removed, preserving the original (mistaken-to-defer) finding alongside the correction.

**Governance correction (post-report)**: the sentence originally here claimed this was "a forward addition to the current corpus, not a Stage 4 amendment — no baseline tag moves." That was wrong, for exactly the reason A0 exists: `error-catalog.md` is Stage-4-owned frozen content, and `stage-4-baseline` had already been reconstructed once (by A0) to represent the *complete, correct* Stage 4 contract — a second forward edit to that same file leaves the baseline stale again, identically to why `terminalCookieAuth`/`RATE_LIMITED` needed A0 in the first place. The owner caught this before sealing A1. A governance check confirmed no existing frozen code could be reused instead (inspected the actual frozen `stage-4-baseline`, not `main`, which already carried the disputed amendment): every code is either protocol-level or tied to a specific business field, and `CONCURRENCY_CONFLICT`'s scope is explicitly limited to 409 state races, not structural request validation. `VALIDATION_FAILED` was therefore folded into a second baseline reconstruction — see `docs/PROJECT-MANIFEST.md`'s "A1 baseline reconstruction" for the full record (new `stage-4-baseline` @ `f57e924`, was `d8f05f9`; `stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` all moved accordingly). `RATE_LIMITED`'s own history (A0) was never a counterexample to this rule — it also required reconstruction; the mistake here was inferring a forward-edit precedent that never actually existed.

**Tests and totals**: `tests/Database/AuthenticationSessionTest.php` now has 21 methods / 77 assertions — the original 18-required-behavior coverage (15 methods) plus this correction's 6: the CSRF-bootstrap end-to-end flow, and five validation-envelope tests (missing email, malformed email, missing password, multiple simultaneous failures, exact envelope shape with no Laravel-native shape leaking). Still placed under `tests/Database/`, not `tests/Feature/`, for the same reason as before: every migration in this project is PostgreSQL-specific (confirmed directly: `ALTER TABLE ... ADD CONSTRAINT` fails under phpunit.xml's default sqlite connection) — a real `users` table to authenticate against is only available the same way every other schema-dependent test in this project already gets one. Full regression after the correction: `tests/Unit` 89/209, `tests/Database` 160/566, `tests/Feature` 1/1, Stage 6A concurrency (3/33), Stage 6B concurrency (3/105), Stage 6C checkout (12/105) and checkout-concurrency (3/46) individually re-verified, migration round-trip clean, Pint clean, `scripts/validate-baselines.sh` 12/12 (no baseline tag touched).

**Deviations from the initialization-pass assumptions**: none found. Both amendments assumed by §14/§15 turned out to be exactly what A0 needed (net of the Ruling 6 correction already recorded); no new contract gap was discovered during A1 implementation itself.

---

## 18. A2 implementation summary (authorization/capability foundation)

**Scope delivered**: the fixed V1 role→capability authorization model — one centralized capability catalog, a Laravel Gate ability per capability, and a reusable whole-operation enforcement mechanism. No terminal enrollment, no `ResolveTerminalContext`, no authoritative User+Terminal context, no Stage 6C HTTP work, no dynamic roles/permissions, no policy hierarchy — all remain A3–A6 or explicitly out of scope.

**Reconnaissance performed before any code changed** (§2/§3's required mechanical comparison): `openapi.yaml`'s `Capability` enum (17 entries), `domain-model.md` §2.2's catalog (11 original + 6 added in the Stage 4 remediation pass, explicitly disclosed there as vocabulary completion only), and A1's `RoleCapabilityCatalog` were compared directly — identical sets, no missing/duplicate/misspelled/divergent name. `api-design.md` §4's role→capability table is labeled "illustrative for contract purposes... can be tuned during implementation without a contract change," but it is the *only* source that breaks the vocabulary down per role, and it matches exactly what A1 already implemented (and what this task's own expected mapping specified) — no conflict existed across sources, so no STOP condition applied.

**Files changed**:
- `app/Services/Auth/RoleCapabilityCatalog.php` — added `CAPABILITIES` (the 17-entry vocabulary, single source of truth) and `has($role, $capability)`. `ADMIN`'s map entry is now literally `self::CAPABILITIES`, not a manually duplicated 17-item list, so "Admin: all capabilities" is a structural guarantee that can never drift from the vocabulary constant.
- `app/Providers/AppServiceProvider.php` — `boot()` now also registers one `Gate::define()` per `CAPABILITIES` entry, each a one-line closure delegating to `RoleCapabilityCatalog::has($user->role, $capability)`.
- `bootstrap/app.php` — new exception renderer for `AUTHORIZATION_DENIED` (403).
- `routes/web.php` — one test-only route, environment-guarded to `testing` only.

**Capability value type**: kept as plain string constants, matching A1's own choice and the frozen corpus's own framing ("a fixed enum of string constants") — no PHP enum introduced, per explicit instruction not to do so for stylistic reasons alone even though this codebase does use backed enums elsewhere (`IdempotencyOperationType`).

**Gate registration architecture**: `AppServiceProvider::boot()`, a `foreach` over `RoleCapabilityCatalog::CAPABILITIES` registering `Gate::define($capability, fn (User $user): bool => RoleCapabilityCatalog::has($user->role, $capability))`. No ACL engine, no database-backed permission middleware, no wildcard abilities, no Spatie Permission or equivalent package.

**Whole-operation enforcement mechanism**: Laravel's built-in `can:` middleware (`Illuminate\Auth\Middleware\Authorize`, already registered as the `can` alias by the framework — no custom middleware class needed), chained as `['auth', EnsureUserIsActive::class, 'can:CAPABILITY']`. Ordering is load-bearing: `auth` rejects an unauthenticated request before either later middleware runs; `EnsureUserIsActive` (A1) revalidates `active` and forces a 401 for a stale inactive session *before* the Gate ever evaluates a capability, so an inactive user can never reach 403 (§11's precedence requirement) — proved by a dedicated test.

**No production controller yet needs a whole-operation gate**: Stage 4's `CATALOG_MANAGE`/`TERMINAL_MANAGE`/`USER_MANAGE`/etc.-gated endpoints are unimplemented (Catalog/Terminal-management/User-management controllers are all future work, A3+ or later). Per explicit instruction not to build controllers merely to exercise the `x-capability` inventory, the real `auth` + `EnsureUserIsActive` + `can:` middleware chain is proved end-to-end via exactly one test-only route (`GET /api/v1/_test/requires-catalog-manage`, `routes/web.php`, wrapped in `if (app()->environment('testing'))` — unreachable outside test runs, never a production route), while the full 17-capability × 3-role authorization matrix is proved directly against `Gate`/`RoleCapabilityCatalog` (no HTTP or database needed for that part, since the Gate closure only reads `$user->role`).

**Conditional capabilities are not flattened**: `PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, and `CASH_OUT` are registered as Gate abilities (so `Gate::forUser($user)->allows('PRICE_OVERRIDE')` works) but are **not** applied as a route-level `can:` gate anywhere — `saleFinalize` is not blanket-gated with either override capability, and no cash-movement endpoint is blanket-gated with `CASH_OUT`. `CapabilityOpenApiTraceabilityTest` mechanically confirms these three never appear as a whole-operation `x-capability` anywhere in the frozen contract either, and `AuthorizationTest` confirms no route in the application applies them as a `can:` middleware parameter. Where and how each actually fires (field-level, request-body-conditional) is left to the module that owns that behavior (Stage 6C's `CheckoutService` for `PRICE_OVERRIDE`/`DISCOUNT_OVERRIDE`, a future cash-movement endpoint for `CASH_OUT`) — A2 provides the mechanism, not the call site.

**Unknown-role defense**: `RoleCapabilityCatalog::forRole()`'s `self::MAP[$role] ?? []` fallback (already present since A1) means an unrecognized role receives the empty set, never a silent upgrade to ADMIN/MANAGER-like access; `has()` inherits this by construction. Proved directly with an in-memory `User` instance holding a role string the database's own CHECK constraint would reject.

**Error mapping — a real framework subtlety found and worked around**: registering `$exceptions->render()` against `Illuminate\Auth\Access\AuthorizationException` directly never fired. Reading `Illuminate\Foundation\Exceptions\Handler::prepareException()` showed why: Laravel unconditionally rewraps a status-less `AuthorizationException` into `Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException` before any custom render callback for the Illuminate type gets a chance to run. `Gate::authorize()` and the `can:` middleware both throw the Illuminate exception, so by the time anything can render it, it is always the Symfony one — the renderer is registered against `AccessDeniedHttpException` instead, confirmed correct against real `Authorize`-middleware denials, not merely reasoned from documentation. No stack trace, policy class name, or missing-capability name is exposed; `details` stays empty, matching `AUTHENTICATION_REQUIRED`'s own envelope shape.

**UserSummary/Gate consistency**: proved directly for all three roles — the exact set of capabilities `RoleCapabilityCatalog::forRole($role)` returns (what `UserSummaryResource` serializes) equals the exact set `Gate::forUser($user)->allows($capability)` returns true for, capability by capability. They cannot drift apart because both read the same `RoleCapabilityCatalog::has()` call underneath.

**OpenAPI traceability**: `CapabilityOpenApiTraceabilityTest` parses `docs/05-api/openapi.yaml` directly with `Symfony\Component\Yaml\Yaml::parseFile()` at test time — no second hard-coded copy of the enum or the `x-capability` inventory anywhere in the test suite. It fails if the enum and `RoleCapabilityCatalog::CAPABILITIES` diverge in either direction, if either list has an internal duplicate, or if any `x-capability` value in the document isn't a member of both.

**Tests and totals**: `tests/Unit/Services/Auth/RoleCapabilityCatalogTest.php` (extended, 8 methods/101 assertions), `CapabilityGateTest.php` (4 methods/72 assertions — the full role × capability matrix, unknown-role denial, and the UserSummary/Gate consistency check), `CapabilityOpenApiTraceabilityTest.php` (5 methods/105 assertions); `tests/Database/AuthorizationTest.php` (5 methods/12 assertions — capable-user-allowed, incapable-user-403, unauthenticated-401, inactive-user-401-before-Gate, no-route-gates-a-conditional-capability). Full regression: `tests/Unit` 102/474, `tests/Database` 165/578, `tests/Feature` 1/1, the A1 authentication/session suite re-verified unchanged (21/77), Stage 6A concurrency (3/33), Stage 6B concurrency (3/105), Stage 6C checkout (12/105) and checkout-concurrency (3/46) individually re-verified, Pint clean, `scripts/validate-baselines.sh` 12/12 (no baseline tag touched).

**Deviations from the initialization-pass assumptions**: none in the role/capability vocabulary or mapping (see reconnaissance above). One real implementation-detail discovery not anticipated by the initialization pass: Laravel's automatic `AuthorizationException` → `AccessDeniedHttpException` rewrap, documented above so a future module doesn't rediscover it the hard way.

---

## 19. A3 implementation summary (terminal enrollment / credential verification)

**Scope delivered**: ADR-011's full enrollment lifecycle — `terminalCreateEnrollmentToken`, `terminalEnroll`, `terminalCurrent`, `terminalList`, `terminalGet`, `terminalRevoke`. No A4 User+Terminal+Store composition, no shift resolution, no Stage 6C HTTP work.

**Rulings resolved before writing code** (all had sufficient frozen evidence; none required a STOP):
- **Store scoping** (task §3): terminal management is scoped to the actor's own `store_id`. Evidence: domain-model.md §2.1 ("every table is store_id-scoped"), `terminalList`'s own summary ("List terminals for the store"), and `terminals.store_id`'s own existence. Not invented — applying an already-universal, repeatedly-stated project convention.
- **TerminalStatus** (task §4): untouched by every A3 operation. No endpoint in the frozen contract ever mutates `status` — there is no `terminalCreate`/`terminalUpdate` at all — and §14 Ruling 9 already ruled `TERMINAL_REVOKED` comes from `revoked_at` alone, independent of `status`. Nothing to gate on, nothing to invent. (architecture.md's own "revokes the old terminal's credential (marks it DECOMMISSIONED)" passage is a narrative example of an administrator's operational response to lost hardware, not a technical requirement on the `terminalRevoke` operation itself — reconciled with, not contradicting, the already-closed ruling.)
- **Token consumption timing** (task §7): ADR-011's own wording — "invalidated immediately on first successful use, regardless of outcome" — is the task's own "option B" verbatim. `TerminalEnrollmentService` implements this as two separate `DB::transaction()` calls: token claim (lock, validate, mark `used_at`) commits before credential issuance is even attempted, so a phase-2 failure can never roll back and un-consume the token.
- **Concurrency** (task §8): the same claim transaction's `SELECT ... FOR UPDATE` + used_at check-then-set serializes concurrent claims on the token row — proven with a real two-OS-process test (`TerminalEnrollmentConcurrencyTest`), mirroring `IdempotencyConcurrencyTest`'s established pattern.
- **Re-enrollment** (task §11): ADR-011 explicitly describes re-binding "to the same terminal record" after revocation — only possible if re-enrollment clears `revoked_at`, so it does. `activated_at` is set once, on first enrollment only (never overwritten on re-enrollment), matching `TerminalFactory::unenrolled()`'s own established convention of bundling it with `credential_hash`/`credential_issued_at`; `credential_issued_at` updates on every (re-)enrollment.
- **Audit events** (task §20): **not implemented.** ADR-011 explicitly defers exact event-type naming to "a Stage 6 decision," and domain-model.md's frozen `event_type` catalog has no enrollment/revocation entry. Disclosed as deferred, not silently dropped — inventing vocabulary the frozen corpus doesn't have was explicitly out of scope.
- **`terminalCurrent`** (task §18): fully implemented and exposed as a real route. Its response (`TerminalSummary`: `id`/`terminal_code`/`status`/`activated_at`) composes nothing with User or Store, so exposing it doesn't pre-empt A4's `user.store_id == terminal.store_id` rule — proven directly by a test showing a *different* store's admin can still resolve an unrelated terminal's credential via `terminalCurrent` in A3 (A4 is what will reject that combination, later).

**One decision flagged, not resolved unilaterally**: `terminalEnroll`'s `409` ("token already used, expired, or revoked") references the generic `Error` schema with no code in `error-catalog.md` — the same class of gap as A1's `VALIDATION_FAILED`. `CONCURRENCY_CONFLICT` was checked and rejected as a reuse candidate: its own description is scoped to "a current-state race," which fits "already used" but not "expired," and the frozen contract describes all three sub-cases as **one** outcome, not three to be split across codes. Implemented as `ENROLLMENT_TOKEN_INVALID` (409) — a `DomainException`, rendering through the existing generic envelope — but **not added to `error-catalog.md`**, pending the owner's ruling on reuse-vs-reconstruct (same two-path decision as `VALIDATION_FAILED`). No baseline reconstruction performed this pass.

**Files added**: `app/Domain/Exceptions/{TerminalNotEnrolledException,TerminalRevokedException,EnrollmentTokenInvalidException}.php`; `app/Services/Terminal/{TerminalCredentialResolver,TerminalEnrollmentTokenService,TerminalEnrollmentService}.php`; `app/Http/Middleware/ResolveTerminalContext.php`; `app/Http/Controllers/TerminalController.php`; `app/Http/Requests/{CreateEnrollmentTokenRequest,EnrollTerminalRequest}.php`; `app/Http/Resources/{TerminalSummaryResource,TerminalEnrollmentTokenResource}.php`; `tests/Database/{TerminalEnrollmentTest,TerminalEnrollmentConcurrencyTest}.php` + `tests/Database/support/terminal_enrollment_race_worker.php`.

**Files changed**: `routes/web.php` (the six Terminal routes); `config/database.php` (the timezone fix, below).

**Enrollment-token format**: `Str::random(64)`, high-entropy, plaintext returned exactly once in the `terminalCreateEnrollmentToken` response, only `hash('sha256', $plaintext)` persisted as `token_hash`. 15-minute expiry (`expires_at`), matching ADR-011's own example. **Credential format**: identical construction (`Str::random(64)` → `hash('sha256', ...)` → `terminals.credential_hash`), matching `CanonicalRequestHasher`'s already-established SHA-256 convention — never `Hash::make()`/bcrypt, per §14 Ruling 3.

**Terminal resolver architecture**: `TerminalCredentialResolver::resolve()` fetches by `credential_hash` alone first, then checks `revoked_at` **separately** (never one combined `WHERE credential_hash = ? AND revoked_at IS NULL`), so "unknown credential" and "known-but-revoked credential" stay distinguishable — required per task §15, and directly what makes `TERMINAL_NOT_ENROLLED` vs `TERMINAL_REVOKED` possible at all. `ResolveTerminalContext` middleware composes nothing with the authenticated User — that composition is A4's.

**Cookie configuration**: `tindaflow_terminal`, 5-year lifetime (`Cookie::make()`, not the 400-day `Cookie::forever()`), `HttpOnly`, `SameSite=Strict`, `Secure` per `config('session.secure')`'s already-established environment-aware default (shared with the human session cookie's own config, never duplicated). Transparently encrypted/decrypted by Laravel's own `EncryptCookies`/`DecryptCookies` middleware (already part of the `web` group) exactly like the session cookie and `XSRF-TOKEN` — no special handling needed in `ResolveTerminalContext`, which reads the already-decrypted value via `$request->cookie(...)`. Never contains `terminal_id`/`store_id`/`user_id` — fully opaque.

**Revocation semantics**: `terminalRevoke` sets only `revoked_at = now()`. No `status` transition. The human session on the revoking administrator's own browser is untouched — proven directly by a test.

**Re-enrollment semantics**: a fresh token targeting an already-enrolled (or already-revoked) terminal issues a brand-new `credential_hash`/`credential_issued_at` and clears `revoked_at`; the old credential immediately stops resolving (the column is overwritten, never appended) — proven directly.

**A2 403-renderer coexistence** (task's explicit regression watch): `TerminalNotEnrolledException`/`TerminalRevokedException` are `DomainException` subclasses, rendered by the existing generic `DomainException` handler in `bootstrap/app.php` — a completely different code path from A2's `AccessDeniedHttpException` renderer (which only ever fires for Gate/`can:` denials). A dedicated regression test (`test_terminal_403_codes_are_not_converted_into_authorization_denied`) proves `TERMINAL_NOT_ENROLLED` is never relabeled `AUTHORIZATION_DENIED`.

**A real, project-wide infrastructure bug found and fixed**: `config/database.php`'s `pgsql` connection never set a session `timezone`, so `Illuminate\Database\Connectors\PostgresConnector` never issued a `SET TIME ZONE`, leaving every connection on the PostgreSQL **server's own local zone** — reproduced directly as `Asia/Kuala_Lumpur`, not UTC. This silently corrupted every `timestamptz` round-trip through PDO for any value PHP itself computed and later compared (write a naive `now()`-derived string with no offset, Postgres interprets it as local time, not UTC): `terminal_enrollment_tokens.expires_at`, set 15 minutes in the future, was read back appearing **8 hours in the past**, making every fresh token immediately register as expired. Fixed with one line — `'timezone' => env('DB_TIMEZONE', 'UTC')` — pinning the session to `config/app.php`'s own already-declared `'UTC'`. This affects every future timestamptz comparison in the codebase, not just A3's; disclosed prominently since it was silent until now (no earlier stage's tests happened to compare a PHP-computed future timestamp against `now()` after a round trip).

**Tests and totals**: `TerminalEnrollmentTest.php` (22 methods / 65 assertions) covers the full required matrix — token issuance/authorization/authentication, plaintext-once/hash-only persistence, expired/unknown/reused-token rejection, cookie issuance and resolution, revoked/missing/unknown-credential outcomes, human-session independence from terminal revocation, re-enrollment invalidating the old credential, the partial unique index as a real invariant, request-body immunity, the A2-renderer regression proof, back-office operations needing no terminal credential, and the explicit non-implementation of A4's store-coherence rule. `TerminalEnrollmentConcurrencyTest.php` (1 method) proves single-use consumption under genuine two-process concurrency. Full regression: `tests/Unit` 102/474, `tests/Database` 188/652, `tests/Feature` 1/1, the A1 authentication/session suite (21/77) and A2 authorization suite (22/290) both re-verified unchanged, Stage 6A concurrency (3/33), Stage 6B concurrency (3/105), Stage 6C checkout (12/105) and checkout-concurrency (3/46) individually re-verified, Pint clean, `scripts/validate-baselines.sh` 12/12 (no baseline tag touched).

---

## 19a. A3 closeout and third baseline reconstruction

The first A3 report (§19) was accepted in principle but not sealed. Two implementation boundaries were required to be verified/fixed *before* reconstruction, so the Stage 4/5/6A/6B chain would only need to move once for `ENROLLMENT_TOKEN_INVALID`.

**Closeout correction 1 — `terminalCurrent` must not pre-empt A4**: `GET /terminal/current` was reachable in production behind only `auth` + `EnsureUserIsActive` + `ResolveTerminalContext`, meaning a Store-A user holding a Store-B terminal's credential could observe that terminal before A4's `user.store_id == terminal.store_id` coherence check exists. Rather than implementing A4 early, the route was removed from the production `Route::prefix('api/v1')->group()` registration in `routes/web.php` and re-added only inside the existing `if (app()->environment('testing'))` block (the same pattern A2 established for the `CATALOG_MANAGE` test-only route), at the identical path. The controller action and `TerminalCredentialResolver` remain fully implemented and fully tested — only the production route registration was deferred.

**Closeout correction 2 — Store scope for terminal management, proven executable**: `TerminalController::list()`/`get()`/`createEnrollmentToken()`/`revoke()` were confirmed to filter by the authenticated actor's own `store_id` (already the case per §19's ruling), and this was made *provable*, not just architecturally assumed, with dedicated tests: `test_terminal_list_only_returns_the_actors_own_store`, `test_request_body_store_id_cannot_override_the_actors_authoritative_store`, and `test_a_valid_token_cannot_be_used_by_an_administrator_from_a_different_store`. The last of these also hardened `TerminalEnrollmentService::consumeToken()` itself: it now takes the actor's `store_id` and rejects a cross-store claim attempt inside the same locked transaction, before the token's `used_at` is set — a cross-store attempt never consumes the token. No undefined public outcome was exposed by this; the existing `ENROLLMENT_TOKEN_INVALID` (409) already covers "token does not belong to this actor" as one more instance of its deliberately non-enumerated outcome set, so no further contract question arose.

**Other closeout items**:
- `TerminalEnrollmentService::consumeToken()`/`issueCredential()` were changed from `private` to `public` specifically so `test_token_stays_consumed_even_if_credential_issuance_subsequently_fails` could call them independently and prove the failure boundary directly: claim a token, force `issueCredential()` to fail (`ModelNotFoundException` on a bad terminal id), then show the token is still `used_at`-set and cannot be claimed again. This is the executable proof that the two-phase transaction design (§19, "Token consumption timing") is real, not incidental.
- `activated_at`'s "set only when null" behavior was reviewed and confirmed to be an implementation choice, not a `TerminalStatus` redefinition — left as-is, documented here rather than in the frozen contract.
- The database session-timezone fix (§19) was kept as a legitimate framework-level configuration fix, not a workaround, and a dedicated invariant test was added: `DatabaseConnectionTimezoneTest` (`tests/Database/DatabaseConnectionTimezoneTest.php`) asserts `SHOW TIME ZONE` resolves to `UTC` and that a future `expires_at` written and read back does not shift relative to application `now()`.
- The 5-year terminal-credential cookie lifetime was confirmed to be configuration, not a frozen-contract value: centralized as `config('tindaflow.terminal_credential.lifetime_minutes')` (`config/tindaflow.php`, backed by `TERMINAL_CREDENTIAL_LIFETIME_MINUTES`), replacing what had been a magic number in `TerminalController::credentialCookie()`. Not added to `openapi.yaml` or any architecture document — an operational default, not a contract term.

**Process note on the error-code decision**: A3's own STOP conditions listed "new public error code required" as a reason to stop before implementing. `ENROLLMENT_TOKEN_INVALID` was implemented and forward-committed before that STOP was raised — a workflow deviation, not a defect in the resulting design. It does not invalidate the implementation; the reconstruction below repairs the repository state. The lesson carried into A4+: stop *before* committing any newly discovered frozen-contract amendment, the moment its necessity is recognized — not after implementing around it.

**Third baseline reconstruction**: `ENROLLMENT_TOKEN_INVALID` (409) was approved by the owner as a genuine Stage 4 amendment, following direct inspection of the frozen `stage-4-baseline` (not `main`) confirming no existing code fit — `CONCURRENCY_CONFLICT` was checked and rejected as a reuse candidate (race-specific description; the contract describes one non-enumerated outcome across unknown/expired/used/cross-store, not three-plus codes to split across). Procedure (mirroring the A0 and `VALIDATION_FAILED` reconstructions):

1. New backup tag `backup/pre-a3-enrollment-token-reconstruction` created at the pre-reconstruction `main` tip (the A3 closeout commit).
2. Scratch branch `a3-enrollment-token-reconstruction` created from `stage-4-baseline`; `ENROLLMENT_TOKEN_INVALID` added to `docs/05-api/error-catalog.md` as the new candidate `stage-4-baseline` (`776c847`).
3. Stage 5 replayed on top → candidate `stage-5-baseline` (`68aafb9`); Stage 6A replayed → candidate `stage-6a-baseline` (`26c626e`); Stage 6B replayed → candidate `stage-6b-baseline` (`5a24120`).
4. All post-6B commits (including the A3 implementation and both closeout corrections above) replayed cleanly onto the new chain, ending at `6884e9f`. The `VALIDATION_FAILED` forward-fix commit (`d00f640`) replayed as an expected no-op for `error-catalog.md` (the row was already present from the second reconstruction) while its other file changes applied normally — confirmed by `grep -c` finding no duplicate row.
5. Ancestry validated (`git merge-base --is-ancestor`) across all 5 boundary pairs from `stage-3-baseline` through the final tip. Isolation validated by direct `git ls-tree` inspection at each of the four new boundaries (each stage's own content present, the next stage's not yet).
6. Content-equivalence check: `git diff --stat backup/pre-a3-enrollment-token-reconstruction HEAD` shows **exactly one line changed** — the new `ENROLLMENT_TOKEN_INVALID` catalog row — **not an empty diff**. This is the expected, correct result and differs from the `VALIDATION_FAILED` reconstruction's empty diff for a specific reason: `VALIDATION_FAILED` had already been forward-committed to `error-catalog.md` on `main` before that reconstruction began, so the row already existed in the preserved tree and the reconstruction only moved *where in history* it was introduced. Applying the explicit lesson from that correction, `ENROLLMENT_TOKEN_INVALID` was **not** forward-committed to `main` before this reconstruction — it existed only as the exception class's return value, never in the catalog file — so the preserved backup tree genuinely lacked the row, and this reconstruction is what introduces it. A one-line diff introducing a reviewed, owner-approved amendment is the correct outcome, not a discrepancy to investigate.
7. Final regression run at the reconstructed candidate tip, before moving any tag: fresh migration, migration round-trip (`fresh`/`reset`/`migrate`), full `tests/Unit` (102/474), full `tests/Database` (194/668), full `tests/Feature` (1/1), the A1 suite (21/77), the A2 suite (`AuthorizationTest` 5/12 + `tests/Unit/Services/Auth` 17/278), the A3 suite (`TerminalEnrollmentTest` 26/78 + `DatabaseConnectionTimezoneTest` 2/3), the enrollment-token multiprocess concurrency test (1/9), Stage 6A concurrency (3/33), Stage 6B concurrency (3/105), Stage 6C checkout (12/105) and checkout-concurrency (3/46), OpenAPI YAML parse (`Symfony\Component\Yaml\Yaml::parseFile()`, 7 top-level keys, no error), `scripts/validate-baselines.sh` (12/12), and Pint (clean) — all green.
8. Tags moved only after all of the above passed: `stage-4-baseline` → `776c847`, `stage-5-baseline` → `68aafb9`, `stage-6a-baseline` → `26c626e`, `stage-6b-baseline` → `5a24120`. `scripts/validate-baselines.sh` re-run against the moved tags — 12/12 again. `stage-1-baseline`/`stage-2-baseline`/`stage-3-baseline` untouched. `main` force-updated to the reconstructed tip `6884e9f` (not a literal fast-forward, since history was rewritten from `stage-4-baseline` forward — the same non-fast-forward "update main" pattern used in both prior reconstructions). Scratch branch deleted after the move.

**A3 sealing condition — held pending one further review**: Store scoping for terminal management is executable and tested; `terminalCurrent` does not bypass A4 (route deferred to testing-only, resolver fully implemented); token-claim single-use semantics proven under real concurrency and proven not to un-consume on a post-claim failure; `TERMINAL_NOT_ENROLLED`/`TERMINAL_REVOKED` remain distinct from `AUTHORIZATION_DENIED`; `ENROLLMENT_TOKEN_INVALID` now lives inside the canonical `stage-4-baseline` boundary; the full downstream baseline chain (5/6A/6B) is reconstructed and revalidated; every regression above is green. A follow-up review found this closeout's own Store-scope proof incomplete — it covered `terminalList`/`terminalEnroll`-consumption/request-body-override immunity, but not the other three management operations (`terminalGet`/`terminalCreateEnrollmentToken`/`terminalRevoke`) individually. See §19b for that final closeout, the real defect it surfaced, and the resulting fourth reconstruction. **A3 is GOVERNANCE-SEALED as of §19b, not as of this section.**

---

## 19b. A3 final Store-management closeout and fourth baseline reconstruction

**Why this was required**: a review of the §19a closeout accepted every item except one: the three successful Store-scope tests reported (`terminalList` scoping, request-body `store_id` override immunity, cross-store enrollment-token consumption) do not individually prove all four terminal-management operations are Store-scoped. In particular, cross-store **token consumption** (an administrator cannot *use* another store's token) is a different boundary from cross-store **token issuance** (an administrator cannot *generate* a token for another store's terminal in the first place) — proving one says nothing about the other. `terminalGet` and `terminalRevoke` had no dedicated test at all beyond a single combined test that only asserted the HTTP status code, not the response body or side effects.

**Three new tests written, each proving one previously-unproven boundary with real side-effect assertions** (`tests/Database/TerminalEnrollmentTest.php`), replacing the shallow status-only `test_terminal_management_is_scoped_to_the_actors_own_store`:
- `test_cross_store_terminal_get_does_not_expose_store_b_terminal_information` — asserts the 404 envelope shape and confirms no `TerminalSummary` field (`terminal_code`, `status`) of the actual Store-B record appears anywhere in the response.
- `test_cross_store_enrollment_token_issuance_fails_and_creates_no_row` — asserts zero `terminal_enrollment_tokens` rows exist before and after the attempt, and no plaintext token is returned.
- `test_cross_store_terminal_revoke_fails_and_leaves_store_b_terminal_unaffected` — asserts `revoked_at` is unchanged, the existing credential hash is unchanged, and the Store-B terminal's own credential still resolves normally via `GET /terminal/current` afterward.

The already-implemented tests for list-scoping, request-body-override immunity, and cross-store token-consumption were retained and re-verified unchanged, per instruction — they protect separate attack paths and are not superseded by the three new tests.

**A real defect surfaced while writing these tests, confirmed via an actual HTTP round-trip (not reasoned from documentation)**: `TerminalController::get()`, `createEnrollmentToken()`, and `revoke()` all used a bare `Terminal::where('store_id', $actor->store_id)->findOrFail(...)`. A cross-store ID correctly produces a 404, but the response body was Laravel's own default exception shape (`{"message": "No query results for model [...]", "exception": "...", "trace": [...]}` under `APP_DEBUG=true`), never the frozen `{"error": {"code", "message", "details", "request_id"}}` envelope. Root cause: `Illuminate\Foundation\Exceptions\Handler::prepareException()` unconditionally rewraps a `ModelNotFoundException` into `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` before any custom renderer for the original exception type could run — the same class of Laravel-internal rewrap A2 already documented for `AuthorizationException → AccessDeniedHttpException`. No renderer was registered for `NotFoundHttpException`, so it fell through to Laravel's default handler. This predates this closeout pass entirely; it was simply never exercised by a test that inspected the response body rather than only its status code.

**Decision, made directly (owner delegated the technical call, with an instruction to audit against project precedent and research external prior art where the internal evidence runs out)**: `openapi.yaml`'s `NotFound` response for all three operations already references the generic `Error` schema, and `error-catalog.md`'s own framing ("every failure is a non-2xx status with the envelope above," no carve-outs) implies every failure — this one included — should carry a catalogued code. No existing code fits "this terminal record does not exist for this actor": every other 404 in the codebase is resource-specific and already catalogued (`PRODUCT_NOT_FOUND`, `SALE_NOT_FOUND`, `VOID_NOT_FOUND`, `REFUND_NOT_FOUND`, `INVOICE_NOT_FOUND`), and there is no generic reusable fallback. `TERMINAL_NOT_FOUND` (404) was added following that identical, already-five-times-established pattern — the smallest, most consistent fix, not a novel design. A brief check of public competitor help-center documentation (UTAK, StoreHub) was made per instruction but returned no technical API/error-code material (both are customer-facing support sites, not API references) — the internal precedent already in this codebase was the decisive evidence. A short general search independently confirmed the already-implemented HTTP status choice (404, not 403, for a cross-tenant resource the requester should not be able to distinguish from "does not exist") matches standard REST/multi-tenancy practice — that part required no change.

**Application code was committed first, without touching `docs/05-api/error-catalog.md`** (commit `6c76799`) — `TerminalNotFoundException` (a plain `DomainException`, rendered through the already-existing generic renderer, no new renderer needed), the three `TerminalController` methods updated to throw it via a shared `findInActorsStore()` helper, and the three new tests. This deliberately applies the lesson recorded in §19a: the catalog row itself is added only at the Stage 4 boundary during reconstruction, never as an ordinary forward commit on `main`.

**Fourth baseline reconstruction**: identical method to the prior three — preserve `main` (`backup/pre-a3-terminal-not-found-reconstruction` @ `6c76799`), rebuild from `stage-4-baseline` forward on a scratch branch (`a3-terminal-not-found-reconstruction`), add `TERMINAL_NOT_FOUND` to `error-catalog.md` at the new Stage 4 boundary (`c231a31`), replay Stage 5 (`b9f4d3f`), Stage 6A (`ba97dfc`), Stage 6B (`721cd72`), and all 28 post-6B commits (ending at `ae6ffb2`) — every cherry-pick applied cleanly, no manual conflict resolution.

| Baseline | Before (A3 3rd-reconstruction output) | After (A3 4th reconstruction) |
|---|---|---|
| `stage-4-baseline` | `776c847` | `c231a31` |
| `stage-5-baseline` | `68aafb9` | `b9f4d3f` |
| `stage-6a-baseline` | `26c626e` | `ba97dfc` |
| `stage-6b-baseline` | `5a24120` | `721cd72` |

**Verification**: `git diff --stat backup/pre-a3-terminal-not-found-reconstruction HEAD` shows **exactly one line changed** — the new `TERMINAL_NOT_FOUND` catalog row — matching the deliberate (not forward-committed) pattern established for `ENROLLMENT_TOKEN_INVALID`. All 5 ancestry checks (`git merge-base --is-ancestor`, `stage-3-baseline` through the final tip `ae6ffb2`) and all applicable isolation checks (direct `git ls-tree` inspection at each of the four new boundaries) pass, both before and after the tag move (`scripts/validate-baselines.sh`, 12/12 each time). Full regression at the reconstructed tip, before any tag moved: fresh migration and migration round-trip clean; `tests/Unit` 102/474; `tests/Database` 196/689 (up from 194/668 — the net +2 from the three new tests replacing one); `tests/Feature` 1/1; A1 suite 21/77; A2 suite (`AuthorizationTest` 5/12 + `Unit/Services/Auth` 17/278); A3 suite (`TerminalEnrollmentTest` 28/99 + `DatabaseConnectionTimezoneTest` 2/3); enrollment-token multiprocess concurrency 1/9; Stage 6A concurrency 3/33; Stage 6B concurrency 3/105; Stage 6C checkout 12/105 and checkout-concurrency 3/46; OpenAPI YAML parse clean; Pint clean. Tags moved only after all of the above passed; `stage-1-baseline`/`stage-2-baseline`/`stage-3-baseline` untouched; `main` force-updated to `ae6ffb2` (non-fast-forward, same pattern as the prior three reconstructions); scratch branch deleted after the move.

**A3 sealing condition — now genuinely all met**: all four terminal-management operations (`terminalList`, `terminalGet`, `terminalCreateEnrollmentToken`, `terminalRevoke`) individually proven Store-scoped with real side-effect assertions, not merely status-code checks; `terminalCurrent` deferred to A4; token-claim semantics proven under concurrency and against post-claim failure; `TERMINAL_NOT_ENROLLED`/`TERMINAL_REVOKED`/`TERMINAL_NOT_FOUND` all remain distinct from `AUTHORIZATION_DENIED`; both `ENROLLMENT_TOKEN_INVALID` and `TERMINAL_NOT_FOUND` live inside the canonical `stage-4-baseline` boundary; the full downstream baseline chain is reconstructed and revalidated a fourth time; every regression is green; all five backup tags retained. **A3 is GOVERNANCE-SEALED.**

---

## 20. A4 implementation summary (authoritative User+Terminal+Store request-context composition)

**Scope delivered**: exactly §15's A4 definition — the DTO/service that hands `{user, terminal, store}` to a controller, enforcing §14 Ruling 1's Module A layer (`user.store_id == terminal.store_id`) explicitly, with none of these fields ever read from the request body. No shift resolution, no `cashier.store_id == terminal.store_id` shiftOpen invariant (that belongs to a future shift-management module), no Stage 6C HTTP wiring (A6) — those remain out of scope.

**Composition**: `App\Services\Auth\PosRequestContext` — a `final readonly` DTO holding the authenticated `User` and the authoritative `Terminal`, with a `store()` accessor returning `$this->user->store` (the two are already proven equal by the time one is constructed, so no second lookup against `$terminal->store` is needed). `App\Http\Middleware\ComposeAuthoritativeContext` constructs it: reads `Auth::guard('web')->user()` and the `terminal` request attribute already set by `ResolveTerminalContext`, compares `store_id` (`!==`, matching the string-UUID comparison convention already used in `TerminalEnrollmentService::consumeToken()`), and either throws or attaches the composed `PosRequestContext` to the request as the `pos_context` attribute for a controller to consume.

**Middleware ordering, unchanged from the layered model already established**: `auth` → `EnsureUserIsActive` → `ResolveTerminalContext` → `ComposeAuthoritativeContext`. Each stage's own boundary stays independent — an inactive user still gets `401 AUTHENTICATION_REQUIRED` before ever reaching the coherence check (proven directly, not assumed), and an unenrolled/revoked terminal still gets its own `403` before coherence is ever evaluated, since `ResolveTerminalContext` runs first and throws on its own terms.

**Decision made directly, no STOP required**: a store mismatch is reported as `TERMINAL_NOT_ENROLLED` (403) — the same code and message as "no credential at all" — rather than a new, more specific code. Evidence: §16 item 6 explicitly forecloses inventing anything further for this composition step ("no further new code invented without going through the amendment procedure"), and `openapi.yaml`'s `terminalCurrent` `403` description ("This browser is not enrolled as any terminal") already reads correctly under this reuse — from this user's perspective, a terminal belonging to another store is exactly as unusable as no terminal at all, matching the non-enumeration convention already established for `AUTHENTICATION_REQUIRED` and `ENROLLMENT_TOKEN_INVALID`. No `openapi.yaml`/`error-catalog.md` change was needed; no baseline reconstruction was performed.

**`terminalCurrent` unblocked**: the one existing `POS_TERMINAL`-classified route, deliberately deferred to testing-only in the A3 closeout pending exactly this middleware, is now registered in the real production route group (`routes/web.php`) with `ComposeAuthoritativeContext` appended to its chain; the test-only duplicate registration was removed.

**A real, unrelated test-isolation bug found and fixed while writing the coherence tests**: a cross-store test captured one administrator's login response early, then made an *unrelated* administrator's bare `login()` call later in the same test before reusing the first response's session cookie. Because Laravel's test client attaches whatever cookies are already set on the test instance to every request unless explicitly overridden, the later login request ambiently carried the first administrator's still-attached session cookie; `Auth::attempt()` authenticated the second administrator *into that same session record* before `session()->regenerate()` rotated away from it, silently repointing the first administrator's captured session ID at the second administrator. Fixed by capturing a fresh login immediately before the final assertion rather than reusing the early one — a general hazard of this test file's cookie-forwarding pattern, worth remembering for any future test that captures a login response and defers its reuse past another login call.

**Files added**: `app/Services/Auth/PosRequestContext.php`, `app/Http/Middleware/ComposeAuthoritativeContext.php`.

**Files changed**: `routes/web.php` (`terminalCurrent` moved into production, test-only duplicate removed); `tests/Database/TerminalEnrollmentTest.php` (the obsolete `test_terminal_resolution_does_not_enforce_user_store_coherence`, which proved A3 deliberately did *not* enforce this rule, replaced with `test_terminal_resolution_now_enforces_user_store_coherence` proving the opposite now holds; two new tests added — `test_terminal_resolution_succeeds_when_user_and_terminal_share_a_store`, `test_an_inactive_user_is_rejected_with_401_before_reaching_the_coherence_check`; the test-isolation bug above fixed in `test_cross_store_terminal_revoke_fails_and_leaves_store_b_terminal_unaffected`).

**Tests and totals**: `TerminalEnrollmentTest.php` now 30 methods / 105 assertions. Full regression: `tests/Unit` 102/474, `tests/Database` 198/695, `tests/Feature` 1/1, A1 suite 21/77, A2 suite (`AuthorizationTest` 5/12 + `Unit/Services/Auth` 17/278), Stage 6A concurrency 3/33, Stage 6B concurrency 3/105, Stage 6C checkout 12/105 and checkout-concurrency 3/46, Pint clean. `php artisan route:list --path=terminal/current` confirms exactly one route, registered unconditionally (not environment-guarded). No baseline tag touched; A4 required no Stage 4 amendment.

**A5/A6 not started.** Stage 6C's own `POST /sales` HTTP layer (A6) still requires: A5's full auth/authz/terminal HTTP integration matrix run against A0–A4; a `SaleController`/`SaleFinalizeRequest`/route registered under the `web` middleware group; and `CheckoutService::finalize()` fed `$trustedTerminalId`/`$trustedCashierId` from a `PosRequestContext` (via `ComposeAuthoritativeContext`, now available) rather than the request body — none of that is built yet.
