# Stage 13 — Users management

## Status

**Done and tested**, backend and admin screen. Two plain forward commits to the
frozen contract (both owner-approved, see §2); no baseline tag moved and
`scripts/validate-baselines.sh` stayed green.

## 1. Scope

All five `openapi.yaml` Users operations, plus one new one:

| Operation | Route | Notes |
|---|---|---|
| `userList` | `GET /users` | filters `role`, `active`; sorted by name; `per_page` 1..100 |
| `userCreate` | `POST /users` | `UserInput` → 201 `UserSummary` |
| `userGet` | `GET /users/{userId}` | |
| `userUpdate` | `PATCH /users/{userId}` | |
| `userDeactivate` | `POST /users/{userId}/deactivate` | idempotent |
| **`userActivate`** | `POST /users/{userId}/activate` | **new, forward-committed**; idempotent |

All require `USER_MANAGE`, which only `ADMIN` holds (a `MANAGER` or `CASHIER` gets
`403 AUTHORIZATION_DENIED`). Session-only, no terminal credential, scoped to the
actor's own store. Responses are `UserSummary`, which never carries the password hash.

## 2. Contract changes and why

Two owner decisions were needed, because the frozen contract had gaps:

1. **No way to reactivate.** `userDeactivate` had no inverse and `UserInput` has no
   `active` field, so a deactivation was permanent. The owner chose to add
   `userActivate` (a wholly new operation, so a forward commit under the existing rule).
2. **`USER_NOT_FOUND`.** `userGet`/`userUpdate`/`userDeactivate` declare a `404` with
   no registered code. Under the strict rule that is reconstruction territory, but the
   repository is now published, so it would mean force-pushing `main` and all baseline
   tags. The owner approved forward-committing the code instead — a recorded, one-time
   exception (see `error-catalog.md`'s governance exception).

Touched: `openapi.yaml` (new operation), `operation-inventory.md`, `error-catalog.md`,
`api-design.md`. No other frozen file.

## 3. Decisions

- **Password is optional on create** (the schema does not require it). Without one the
  account gets an unguessable random hash and cannot sign in until an admin sets a
  password with `userUpdate`. The screen requires one on create. The 8-character
  minimum is a technical baseline; no frozen document states a password policy.
- **On update a blank password keeps the current one**; a supplied one replaces it.
  `PATCH` follows the schema literally, so `name`, `email` and `role` are required.
- **Email is unique per store, compared case-insensitively**, so two accounts cannot
  differ only by letter case. It is stored as typed. (Existing behaviour, not changed:
  sign-in matches the email exactly, so the case typed at login must match.)
- **Lock-out protection is deliberately partial** (owner's choice): the API refuses
  changing the last active `ADMIN` to another role (a `role` field error, which the
  contract's `422` already allows; the check locks the store's active admins so two
  concurrent demotions cannot both pass). It does **not** refuse deactivating yourself
  or the last admin — `userDeactivate` declares only `404`, and adding a refusal would
  need a new code in a frozen response. The screen therefore hides/disables Deactivate
  and role change for your own account and for the only active administrator. Known
  gap: the API itself can still deactivate the last admin, and `AdminUserSeeder` will
  not recreate an admin while any `ADMIN` row exists, even an inactive one.
- **Deactivating ends the session** on the user's next request (`EnsureUserIsActive`),
  and they cannot sign in until reactivated; reactivating does not resurrect the old
  session (a test proves both).
- Users are never deleted; historical audit and sale records reference them.

## 4. Admin screen

`/admin/users` (gated by `USER_MANAGE`, new **Users** sidebar section, Dashboard link):
role/status filters, server-side pagination, table on `md+` and cards on phones, a
"YOU" marker on your own row. **New user / Edit** open the slide-over form: name, email,
role cards, password with Show / Generate (16 characters from the browser's CSPRNG) /
Copy, the user's current capabilities, and Deactivate / Reactivate (Deactivate asks for
confirmation). Server errors (duplicate email, last-admin role rule) appear under their
fields. No Stitch mockup was generated: the screen reuses the catalog pattern.

The slide-over shell (focus trap, Escape, scroll lock, focus restore) was extracted into
`admin/SlideOver.jsx` and the product form now uses it too.

## 5. Verification

- New `UsersHttpTest` (17 tests): create and sign in, password-less create, role gating,
  field validation, per-store case-insensitive email, list filters/scoping/no secrets,
  get/update/`USER_NOT_FOUND`, password replace vs keep, last-admin rule (including
  another admin remaining, and an inactive admin not counting), deactivation ending the
  session, activation, cross-store isolation.
- Full regression: Unit 102 + Feature 1 = 103, Database 346 (329 before), Pint clean,
  baseline invariants hold.
- Browser (desktop 1280, phone 375): list with the YOU guard, empty/short-password/
  generated-password create, duplicate email in another case, role change, confirm →
  deactivate → inactive filter → reactivate, role lock on your own account, panel
  geometry, the refactored product panel. The test user was removed afterwards.
- Not exercised in a browser: signing in as a newly created user (the app does not
  type passwords for me; the HTTP tests cover it) and the 401 return trip.

## 6. Still unbuilt

Stock receipts/adjustments, audit log and electronic journal screens (no backend routes),
product CSV import/export and barcode lookup, and the deferred shift/fiscal-day read
endpoints.
