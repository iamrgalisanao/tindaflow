# Module A — Authentication / Authorization / Authoritative Terminal Context: Initialization

## Status

**RULINGS CLOSED. Still no Module A implementation, controller, middleware, policy, authentication handler, or terminal-enrollment code exists** — this document remains scope/decisions only. All nine items originally flagged as unresolved in §14 have been ruled on and are recorded there as the binding Decision Register. One item required touching Stage 6C: `CheckoutService`'s shift-resolution query was found (during Ruling 1's schema verification) to check `terminal_id` only, never `cashier_id` — fixed in a dedicated correction commit with a negative regression test, since Stage 6C remains unfrozen and this was a genuine correctness defect, not a design question. One combined Stage 4 contract amendment was approved in substance (`terminalCookieAuth` security scheme + `TooManyRequests`/`RATE_LIMITED`) but **deliberately not yet applied** — it is batched as step A0 of the implementation sequence (§15). Unlike the Stage 5 `credential_hash` fix (a new migration, no reconstruction needed), this amendment modifies existing frozen Stage 4 file content and therefore requires the **same full baseline-reconstruction mechanism** used for the earlier Stage 2/InvoiceSeries linearization — not a simple tag move. See "Batched Stage 4 amendment" in §14 for the exact reasoning and the required five-step sequence. No Module A baseline/tag exists. Per the owner's explicit plan, implementation (A1 onward) begins in a fresh session, with A0's reconstruction work planned for explicitly at the start, not discovered partway through it.

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
  This still gives the credential lookup (`hash presented credential → look up by credential_hash → check revoked_at IS NULL → establish Terminal`) an efficient unique path; no separate index on `revoked_at` is needed, since it's checked as an ordinary predicate against the one row the unique index already finds. **This migration is a new file, not a modification of any existing frozen migration's content — no `stage-5-baseline` retag is needed**, exactly matching the already-established forward-fix pattern (`Sale`/`InvoiceSeries`/`ElectronicJournalEntry` during Stage 6C, none of which moved `stage-5-baseline` either). This is categorically different from the Stage 4 amendment immediately below, which *does* modify existing frozen file content and therefore *does* require baseline reconstruction — see "Batched Stage 4 amendment."
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

### Ruling 6 — `TooManyRequests` dangling ref (APPROVED, with a stable error code)

Confirmed contract defect: `authLogin`'s `429` response references an undefined `components/responses/TooManyRequests`. **Approved fix**: define it using the same standard error envelope as the other six reusable responses, **and** add one new domain error code, `RATE_LIMITED` (HTTP 429), to `error-catalog.md` — TindaFlow's error catalog is deliberately stable and machine-readable, so an uncatalogued/absent `code` on a real, reachable response would be inconsistent with every other error path. `RATE_LIMITED` is emitted by the login-throttling middleware/rate limiter directly; it needs no domain exception class in the business layer. Exact attempts/window thresholds remain internal configuration, not part of the public contract.

### Ruling 7 — Password policy (APPROVED, wording corrected)

Internal server-side validation only, no Stage 4 contract change. Correction to the original framing: a minimum-length rule (e.g. 8 characters) is **an explicit application choice for V1**, not "a Laravel default" — Laravel ships no password-complexity rule out of the box; whatever minimum is chosen is this project's own decision to make and can change later without a contract amendment, since failures already return the existing generic `422 UnprocessableEntity`.

### Ruling 8 — Terminal revoked/disabled semantics (APPROVED)

Revocation takes effect on the terminal's very next request — the same `credential_hash` lookup already re-checks `revoked_at IS NULL` every time, so there is no separate cache to invalidate. The human session on that same browser is untouched: back-office operations continue to work from it, only `POS_TERMINAL`-classified operations fail with `TERMINAL_REVOKED`. Recommended (not required): clear the `tindaflow_terminal` cookie client-side whenever a response carries `TERMINAL_REVOKED`, so the browser doesn't keep presenting a credential the server will only ever reject.

### Ruling 9 — Remaining error-catalog gaps (triaged)

- Password-reset code: not applicable — no such flow exists in the contract.
- `TerminalSummary` missing `credential_issued_at`/`revoked_at`: **deferred**, not blocking Module A or Stage 6C. Revisit as a Stage 4 amendment when back-office terminal-management UI is built (Stage 7).
- Missing `terminalCookieAuth` scheme / dangling `429` ref: resolved by Rulings 3 and 6.
- `revoked_at` vs. `TerminalStatus` enum: kept independent by design — `TERMINAL_REVOKED` is derived from `revoked_at IS NOT NULL` directly, never from a `status` enum value. No new enum value invented.

### Batched Stage 4 amendment (APPROVED in substance; NOT YET APPLIED) — requires full baseline reconstruction, not a tag move

Per explicit instruction: **do not retag or rewrite Stage 4 history yet.** Both fixes are recorded here as one combined, approved amendment to apply together in a single pass (avoiding a second reconciliation later, the same lesson already learned from the Stage 2/5 InvoiceSeries amendment earlier in this project):

1. Add `terminalCookieAuth` (`apiKey`, in `cookie`, name `tindaflow_terminal`) to `components/securitySchemes`; require it conjunctively with `cookieAuth` on every `POS_TERMINAL`-classified operation (§9's list).
2. Define `components/responses/TooManyRequests` (standard error envelope) and add `RATE_LIMITED` (429) to `error-catalog.md`.

Both are purely additive at the API-behavior level — no existing operation's request/response shape changes, and no already-frozen behavior is altered. **But unlike the Stage 5 `credential_hash` fix above, this amendment modifies the actual content of existing frozen Stage 4 files (`openapi.yaml`, `error-catalog.md`), not merely adds new ones.** Confirmed by direct check: `git merge-base --is-ancestor stage-4-baseline stage-5-baseline` is currently true. If this amendment is committed forward of `main`'s current tip (which is necessarily where Module A's own work starts) and `stage-4-baseline` is then simply moved to point at it, `stage-4-baseline` would become a **descendant** of `stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` — inverting the required ancestry chain. This is the identical structural defect the Stage 2/InvoiceSeries linearization existed to fix, and the Stage Baseline Rule it produced applies here without exception: *"where a canonical baseline must be reconstructed as a result, every downstream baseline must be regenerated and revalidated."*

**Required mechanism, therefore, at the start of A0** (not a simple tag move):
1. Cherry-pick the amendment's diff onto the *current* `stage-4-baseline` (`2313a16`) directly — this becomes the new `stage-4-baseline`.
2. Replay `stage-5-baseline`'s substantive content (`9de98ce`) onto it — expected to be a clean, zero-conflict cherry-pick, since nothing in Stage 5 touches `openapi.yaml`/`error-catalog.md` (the same zero-conflict property the Stage 6A replay had onto the amended Stage 5).
3. Replay `stage-6a-baseline`'s substantive content (`823c032`), then `stage-6b-baseline`'s (`2f5e6e8` + `a64111e`), in turn.
4. Run `scripts/validate-baselines.sh` (all 12 checks) plus the full regression suite at each new boundary before moving any tag, exactly as the original linearization did.
5. Only then fast-forward `main` and move `stage-4-baseline`/`stage-5-baseline`/`stage-6a-baseline`/`stage-6b-baseline` to their reconstructed hashes.

This is real, non-trivial work — smaller in scope than the original Stage 2 linearization (one small, purely-additive diff to replay forward through three stages instead of a multi-amendment domain/schema change), but the same mechanism, not a shortcut. `main`'s current tip (`af1615f`, Module A's Decision Register) and everything in Stage 6C built on top of it (`fb757b2` onward) also sit downstream of the *old* `stage-4-baseline`/`stage-5-baseline`/etc. and would themselves need replaying onto the reconstructed chain if they are to remain part of `main` afterward — this should be planned for explicitly at the start of A0, not discovered partway through it.

---

## 15. Module A implementation sequence (derived from evidence)

```text
A0. Apply ALL prerequisite frozen-corpus amendments before any Module A
    code — sequencing correction (owner ruling): the credential_hash
    fix moved here from A3, since it is itself a Stage 5 amendment
    discovered during initialization; implementing A3 first and
    amending the database afterward would recreate the exact
    "implementation on top of stale frozen corpus" problem already
    cleaned up earlier in this project.
    — Stage 4: add terminalCookieAuth security scheme; require it
      conjunctively (AND) with cookieAuth on every POS_TERMINAL
      operation (§9's list).
    — Stage 4: define components/responses/TooManyRequests (standard
      envelope) and add TERMINAL_MANAGE-independent RATE_LIMITED (429)
      to error-catalog.md.
    — Stage 5: add a partial unique index —
      `CREATE UNIQUE INDEX terminals_credential_hash_unique ON
      terminals (credential_hash) WHERE credential_hash IS NOT NULL;`
      — explicit about the pre-enrollment NULL state, still gives the
      credential lookup an efficient unique path. No separate index on
      revoked_at is needed; that column is checked as an ordinary
      predicate against the row this index already finds. THIS ONE
      NEEDS NO BASELINE RECONSTRUCTION -- a new migration file, not a
      modification of any existing frozen file's content, exactly like
      the inventory_locations/Sale/InvoiceSeries/ElectronicJournalEntry
      forward-fixes already done during Stage 6C.
    — The Stage 4 amendment (terminalCookieAuth + TooManyRequests/
      RATE_LIMITED) is different in kind: it modifies the CONTENT of
      existing frozen openapi.yaml/error-catalog.md, so simply
      committing it forward and moving stage-4-baseline would make
      stage-4-baseline a DESCENDANT of stage-5/6a/6b-baseline --
      inverting the required ancestry chain, the same defect the
      Stage 2/InvoiceSeries linearization fixed. This requires the full
      reconstruction mechanism (cherry-pick onto the CURRENT
      stage-4-baseline, replay Stage 5/6A/6B's substantive commits
      forward onto it, validate at each boundary, only then move tags)
      -- see "Batched Stage 4 amendment" in SS14 for the exact five-step
      sequence and which commits main's Stage 6C work must also be
      replayed onto afterward. Plan for this as real reconstruction
      work at the start of A0, not a quick tag move.
    — Run scripts/validate-baselines.sh (all 12 checks) and the full
      regression suite after reconstruction, BEFORE any A1+ code is
      written.

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

This order is derived from dependency evidence (A2 needs A1's authenticated user; A4 needs both A2's capability check, for the endpoints that require one, and A3's terminal resolution; A6 is Stage 6C's own stated unblock condition), not merely stylistic preference. A0 is placed first because every later step's tests and code should target the corrected contract, not the one with a dangling `$ref` and an unmodeled second credential.

---

## 16. Stage 6C unblock criteria

`POST /sales` becomes production-ready only when **all** of the following hold, cross-checked against `stage-6c-sale-finalization.md`'s own stated expectations:

1. An authenticated user's identity is trustworthy, including live re-verification of `active` on every request, not just at login (A1, §14 Ruling 2).
2. Authoritative terminal context exists and cannot be spoofed (A3/A4, ADR-011's trust boundary enforced in code via the `tindaflow_terminal` credential — §14 Ruling 3 — not just documented).
3. Store context cannot be spoofed: `user.store_id == terminal.store_id` is enforced at the POS request-context boundary (A4), **and** `CheckoutService`'s own OPEN-Shift/cashier-match check (already implemented and tested) is re-verified once fed a genuine authenticated cashier — the two are layered per §14 Ruling 1, neither substitutes for the other.
4. Role/capability checks are available and enforced (A2) for every operation that needs one, preserving the conditional field-level checks (`PRICE_OVERRIDE`/`DISCOUNT_OVERRIDE`/`CASH_OUT`) rather than flattening them.
5. A `SaleController`/`saleFinalize` HTTP handler can call `CheckoutService::finalize($trustedTerminalId, $trustedCashierId, $idempotencyKey, $validatedPayload)` without ever reading `terminal_id`/`cashier_id`/`user_id`/`store_id` from the request body — exactly as `stage-6c-sale-finalization.md` §7 already specifies.
6. Authentication/authorization failures map to the existing frozen codes (`AUTHENTICATION_REQUIRED`, `AUTHORIZATION_DENIED`, `TERMINAL_NOT_ENROLLED`, `TERMINAL_REVOKED`) plus the one new, approved code (`RATE_LIMITED`, §14 Ruling 6) — no further new code invented without going through the amendment procedure.
7. Terminal identity/enrollment behavior matches ADR-011 exactly (one-time tokens, hashed storage, revocation semantics), using the specific credential mechanism and hashing discipline ruled in §14 Ruling 3 (deterministic digest/HMAC, never `Hash::make()`).
8. The `Idempotency-Key` HTTP header reaches `CheckoutService`'s existing `IdempotencyService` integration unchanged — Module A must not interpose any additional idempotency logic of its own on top of Stage 6A's already-frozen mechanism.
9. The batched Stage 4 amendment (§14, A0) has been applied and `stage-4-baseline` moved, so the HTTP layer is built against the corrected contract, not the one with a dangling `$ref` and an unmodeled second credential.

Until all nine hold and are demonstrated by passing tests (not merely implemented), `POST /sales` remains not production-ready, and Stage 6C remains unfrozen.
