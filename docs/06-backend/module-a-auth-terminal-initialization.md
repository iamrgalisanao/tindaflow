# Module A — Authentication / Authorization / Authoritative Terminal Context: Initialization

## Status

**INITIALIZATION AND EVIDENCE PASS ONLY. No implementation exists.** No controller, middleware, policy, authentication handler, or terminal-enrollment code was created during this pass. Nothing in Stage 6C's `CheckoutService` was touched. This document reconstructs the exact frozen Module A contract before implementation begins, per the owner's explicit instruction. Started from `main` at `f5cd079` (Stage 6C bookkeeping, checkout domain/service layer verified, unfrozen).

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
- `UserRole` enum: `ADMIN | MANAGER | CASHIER`. `TerminalStatus` enum: `ACTIVE | INACTIVE | DECOMMISSIONED` (no `REVOKED` value — see §16).
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
| `shifts` | **NOT MODULE A OWNED** | Module A reads "does an OPEN shift exist for this terminal/user" only if it composes that into authoritative context (see §17's open question); shift *lifecycle* (open/close) is Stage 6C/checkout-domain territory |
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
- No code for a structurally-bad login (currently generic `401`/`AUTHENTICATION_REQUIRED` covers "wrong password" and "unknown email" identically — possibly intentional enumeration protection, but not stated as such anywhere).
- No `SESSION_EXPIRED` distinct from `AUTHENTICATION_REQUIRED`.
- No `CSRF_TOKEN_MISMATCH` code.
- No `USER_INACTIVE`/account-lockout code.
- `authLogin`'s declared `429` response references `#/components/responses/TooManyRequests`, which was not found among the response definitions inspected in this pass (`BadRequest`/`Unauthorized`/`Forbidden`/`NotFound`/`Conflict`/`UnprocessableEntity` only) — flagged as a possible unresolved `$ref`, not confirmed against the full file exhaustively.
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
| 3 | Inactive user login attempt | Behavior TBD by ruling (§14) |
| 4 | Logout | Session terminated, subsequent request `401` |
| 5 | `/me` | Returns the authenticated user's own identity, not another's |
| 6 | CSRF rejection | State-changing request without a valid token is rejected |
| 7 | Unauthenticated protected request | `401` on every non-`authLogin` operation |
| 8 | Role/capability authorization | A role lacking a capability gets `403 AUTHORIZATION_DENIED`; a role holding it succeeds |
| 9 | Successful terminal enrollment | Token → credential issued → `terminals.credential_hash` set |
| 10 | One-time enrollment token reuse rejection | Second `POST /terminal/enroll` with the same token fails |
| 11 | Invalid enrollment credential | Unknown/malformed token rejected |
| 12 | Revoked terminal | Request from a revoked terminal's browser gets `403 TERMINAL_REVOKED` |
| 13 | Terminal-store mismatch | Behavior TBD by ruling (§14) |
| 14 | Request-body terminal-ID spoof attempt | A request supplying `terminal_id` in the body is ignored/rejected in favor of the credential-resolved value |
| 15 | Request-body user/cashier-ID spoof attempt | Same, for `cashier_id`/`user_id` |
| 16 | Admin request without POS terminal context | BACK_OFFICE-classified operation succeeds with no terminal credential present |
| 17 | Checkout request without terminal context | POS_TERMINAL-classified operation (`saleFinalize`) rejected with `TERMINAL_NOT_ENROLLED` if the credential is absent |
| 18 | Multiple simultaneous sessions | Behavior TBD — not addressed anywhere in the frozen corpus; likely permitted by default (no stated restriction), needs confirmation not assumption |

---

## 14. Unresolved questions (require a ruling before implementation)

1. **User-store vs. terminal-store mismatch** — no rule anywhere governs an authenticated user whose `store_id` differs from the enrolled terminal's `store_id`. Given V1's single-store assumption this may be structurally rare, but nothing enforces or even detects it today.
2. **Effect of `user.active = false` on an already-open session** — does deactivation immediately invalidate live sessions, or only block future logins? Not stated.
3. **Whether login checks `active` at all** — not stated; a deactivated user's credentials might otherwise still authenticate.
4. **Session TTL/inactivity duration** — explicitly ruled "non-blocking" by `api-design.md` §29, but some concrete value is still needed to implement `config/session.php`.
5. **Rate-limiting thresholds** for login/checkout — intent stated (architecture.md §16), no numeric value anywhere.
6. **Credential-enumeration protection** — is identical-401-for-any-login-failure a deliberate anti-enumeration design, or incidental? Not stated either way.
7. **Multiple simultaneous sessions per user** — permitted, restricted, or undefined? Not addressed.
8. **Exact terminal-credential mechanism** — ADR-011 says "cookie is the default V1 mechanism," leaving room for "a dedicated local credential store" as an "equivalent Stage 6 implementation choice." A concrete choice is needed before writing `ResolveTerminalContext` middleware.
9. **Whether Module A itself resolves the current OPEN Shift**, or only resolves identity/terminal context and leaves Shift/FiscalDay resolution to the checkout/domain layer (as `CheckoutService` already does today, per its own docblock). See §17.

None of these are answered by inference in this document.

---

## 15. Contract gaps (public API / error-catalog level, distinct from the internal rulings above)

1. No error code for a password-reset/change flow, because no such flow exists in the contract at all.
2. `TerminalSummary` doesn't expose `credential_issued_at`/`revoked_at` — a caller cannot currently learn *when* a terminal's credential was issued or revoked via the API.
3. No `securityScheme` is declared in `openapi.yaml components/securitySchemes` for the terminal-credential cookie distinct from `cookieAuth` (the human session cookie) — the second credential is prose-only (ADR-011), not modeled in the machine-readable contract.
4. `authLogin`'s `429` response `$ref`s an undefined `TooManyRequests` response object (not found among the six defined response types inspected).
5. No mapping is stated between `terminals.revoked_at` and the `TerminalStatus` enum's three values (none of which is literally `REVOKED`).

---

## 16. Module A implementation sequence (derived from evidence)

```text
A1. Authentication/session foundation
    — login/logout/me, session config, password hashing (already
      structurally supported by User::getAuthPassword()), CSRF wiring.
      No terminal concept needed yet; this alone unblocks every
      BACK_OFFICE-classified endpoint's authentication requirement.

A2. Authorization/capability foundation
    — the fixed role→capability table + Gate-based check. Depends on
      A1 (needs an authenticated User to check a role against).

A3. Terminal enrollment and credential verification
    — TerminalEnrollmentService (token issuance/verification/
      single-use), the enrollment endpoints, ResolveTerminalContext
      middleware. Independent of A2's capability logic except that
      terminalCreateEnrollmentToken/terminalEnroll/etc. themselves
      require TERMINAL_MANAGE (so A3 depends on A2 for its own gating,
      even though its OUTPUT — terminal context — is what A4 composes).

A4. Authoritative request-context composition
    — the DTO/service that hands {user, terminal, store} to a
      controller, with the explicit non-negotiable rule that none of
      these fields may be read from the request body. This is the
      component Stage 6C's own documentation names as its unblock
      condition.

A5. HTTP integration tests
    — the full matrix in §13, run against A1-A4 before touching
      Stage 6C's controller at all.

A6. Stage 6C checkout integration
    — build SaleController/SaleFinalizeRequest/routes/api.php, wiring
      A4's authoritative context into CheckoutService::finalize()
      exactly as stage-6c-sale-finalization.md §7 already specifies.
```

This order is derived from dependency evidence (A2 needs A1's authenticated user; A4 needs both A2's capability check, for the endpoints that require one, and A3's terminal resolution; A6 is Stage 6C's own stated unblock condition), not merely stylistic preference.

---

## 17. Stage 6C unblock criteria

`POST /sales` becomes production-ready only when **all** of the following hold, cross-checked against `stage-6c-sale-finalization.md`'s own stated expectations:

1. An authenticated user's identity is trustworthy (A1).
2. Authoritative terminal context exists and cannot be spoofed (A3/A4, ADR-011's trust boundary enforced in code, not just documented).
3. Store context cannot be spoofed, and the user-store/terminal-store relationship is resolved per whatever ruling closes §14 item 1.
4. Role/capability checks are available and enforced (A2) for every operation that needs one, preserving the conditional field-level checks (`PRICE_OVERRIDE`/`DISCOUNT_OVERRIDE`/`CASH_OUT`) rather than flattening them.
5. A `SaleController`/`saleFinalize` HTTP handler can call `CheckoutService::finalize($trustedTerminalId, $trustedCashierId, $idempotencyKey, $validatedPayload)` without ever reading `terminal_id`/`cashier_id`/`user_id`/`store_id` from the request body — exactly as `stage-6c-sale-finalization.md` §7 already specifies.
6. Authentication/authorization failures map to the existing frozen codes (`AUTHENTICATION_REQUIRED`, `AUTHORIZATION_DENIED`, `TERMINAL_NOT_ENROLLED`, `TERMINAL_REVOKED`) — no new code invented without going through the amendment procedure.
7. Terminal identity/enrollment behavior matches ADR-011 exactly (one-time tokens, hashed storage, revocation semantics).
8. The `Idempotency-Key` HTTP header reaches `CheckoutService`'s existing `IdempotencyService` integration unchanged — Module A must not interpose any additional idempotency logic of its own on top of Stage 6A's already-frozen mechanism.

Until all eight hold and are demonstrated by passing tests (not merely implemented), `POST /sales` remains not production-ready, and Stage 6C remains unfrozen.
