# Stage 23 — Production-readiness audit

## Status

**Audit done, the fixes applied and tested, and (after approval of the two new folders) the deployment artifacts
built and run for real: `.github/` (CI) and `docker/` (the production stack with hourly backups), §6.** No frozen
file was edited and no baseline moved; `scripts/validate-baselines.sh` stayed green.

Method: read the configuration and middleware; ran the application **in production mode** (`APP_ENV=production`,
`APP_DEBUG=false`) against a database built from nothing and probed what a client actually receives; audited the
dependencies; inspected the indexes behind the list and report queries; and **drilled a real backup and restore**
(§4). Findings and their status:

| # | Severity | Finding | Status |
|---|---|---|---|
| F1 | **High** | No business timezone: dates, printed invoice times and CSV offsets were all in UTC for a Philippine store | **Fixed** (D1) |
| F2 | Medium | An unauthenticated request that did not send `Accept: application/json` got a **500** instead of a 401 (a CSV export after the session expired looked like a server fault) | **Fixed** (D2) |
| F3 | Medium | No security headers at all (no CSP, frame protection, `nosniff`, referrer policy, HSTS) | **Fixed** (D3) |
| F4 | Medium | CORS answered every origin (`Access-Control-Allow-Origin: *`) and pre-flighted any header | **Fixed** (D4) |
| F5 | Medium | `.env.example` was the stock Laravel file: SQLite, debug on, none of this app's settings | **Fixed** |
| F6 | Medium | No ceiling on API traffic; only login was throttled | **Fixed** (D5) |
| F7 | Low | Logs: one file growing without limit (31 MB on the dev machine), lines not tied to a request | **Fixed** (D6) |
| F8 | Low | An inbound `X-Request-ID` was echoed and logged unchecked | **Fixed** (D6) |
| F9 | High | No CI, no Dockerfile/compose/nginx, no backup script: `deployment.md` describes them but none existed | **Built and tested** (§6) |
| F10 | High (owner) | A daily backup means up to a day of sales can be lost | **Decided: hourly** (§4.3), configurable |
| F11 | Info | Dependencies clean; production migrate + seed from empty works; indexes adequate for V1 scale | Verified (§5) |

## 1. Decisions

### D1 — The store's timezone is its business timezone (default `Asia/Manila`)

**The defect.** `config/app.php` was hard-coded to UTC and no other timezone existed anywhere (`stores` has no such
column; no document mentions one). For a store in UTC+8 that meant:

- a shift opened at 07:00 on the 10th was given **business date the 9th** (`business_date` came from
  `$openedAt->toDateString()` in UTC), so its Z-reading and every by-day report landed on the wrong day;
- a "from 2026-03-10" filter on any list or report started at 08:00 local, hiding the morning's sales;
- the time **printed on the customer's invoice** was UTC, eight hours behind the wall clock;
- the CSV exports carried `+00:00`, although `csv-export-contract.md`'s own example is `+08:00`.

**The decision.** `APP_TIMEZONE` (default `Asia/Manila`; V1 is a Philippine product, and the contract's example
already assumes UTC+8) is the business timezone. Instants stay absolute (`timestamptz`); only the places that turn
an instant into a *date* or a *printed time* use the zone: business date, `from`/`to` filters, invoice time, CSV
offsets. **The database session timezone follows it** (`DB_TIMEZONE` defaults to `APP_TIMEZONE`) because Eloquent
writes a zone-less string in the application's zone; the earlier A3 fix that pinned both to UTC had made them
agree, and they still do, in a different zone.

**Evidence and safety.** Running the entire Database suite under `Asia/Manila` failed exactly one test, the one
asserting the session is `UTC`, so nothing else depended on the zone. Four new tests
(`BusinessTimezoneTest`) use instants that fall on different days in Manila and in UTC and fail under UTC (three of
the four do, checked): business date, date ranges, the printed invoice time, the CSV offset. The renderer's own unit
test now pins local time. **A store outside the Philippines** sets `APP_TIMEZONE`; a store that ever spans zones
would need a per-store column, which is a larger change and not a V1 need. **Existing data**: instants are
untouched; only `fiscal_days.business_date` rows created before this change were derived in UTC, which matters only
for a database that already holds real early-morning shifts (none does; the dev database holds test data).

### D2 — A guest is never redirected

The API has no login page, so `redirectGuestsTo` returns nothing. Previously the framework tried `route('login')`
for any request that did not send `Accept: application/json` and threw `RouteNotFoundException` (a **500**). The SPA's
CSV downloads send `Accept: text/csv`, so an expired session during an export or a journal download read as "the
export could not be prepared" instead of "sign in again". Now every such request is the catalogued
`AUTHENTICATION_REQUIRED` 401.

### D3 — Security headers and a strict Content-Security-Policy

Added to every response: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: same-origin`, a `Permissions-Policy` that turns off camera, microphone, geolocation and payment,
`Cross-Origin-Opener-Policy: same-origin`, and **HSTS only over HTTPS** (a plain-HTTP LAN host must not be pinned
before it has a certificate). The CSP is `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';
frame-ancestors 'none'`.

`'unsafe-inline'` is allowed for **styles only**, because the invoice viewer and the POS print frame show
server-rendered HTML in a `srcdoc` frame that inherits the page's policy and carries its own `<style>`; scripts stay
locked to `'self'` and that frame is sandboxed without script permission. **Verified in a browser against the built
app in production mode**: the SPA boots with no CSP violation, a sandboxed `srcdoc` frame keeps its inline styles,
a planted script in it does not run, an inline script in the page is blocked, and the frame can still print. The CSP
is skipped only in a *local* environment while the Vite dev server runs (`public/hot`), which needs inline scripts
and a websocket.

### D4 — No CORS

The SPA and API are same-origin with a `SameSite=Strict` cookie, so no browser origin ever needs to call the API.
`config/cors.php` lists no paths, which makes the CORS middleware inert: no `Access-Control-*` headers, and a
cross-origin pre-flight is not answered. (The wildcard was not exploitable, since credentialed requests cannot use
`*`, but there was no reason to invite them.)

### D5 — A ceiling on API traffic

`throttle:api` on the whole `/api/v1` group, **600 requests a minute per signed-in user** (`API_THROTTLE_PER_MINUTE`),
answering the catalogued `RATE_LIMITED` 429 with a `Retry-After` header (the existing 429 renderer dropped the
header; it now keeps it). A till makes a few requests a second at most. Note Laravel runs `auth` ahead of `throttle`,
so a guest is answered 401 before it is counted; the one route a guest can use, login, keeps its own stricter limiter.
This is a technical safeguard, not a business rule.

### D6 — Logs and request ids

`AssignRequestId` now shares the request id with the log context, so **every log line carries the id that the
response's `X-Request-ID` header also holds**: a failed request can be found from the id in its response. An inbound
id is trusted only if it looks like one (8–128 of `A-Za-z0-9._:-`); anything else is replaced. `.env.example` sets
`LOG_STACK=daily` (14 files kept) and documents `LOG_LEVEL=warning` for production.

## 2. Production configuration checklist

`.env` (never committed; `APP_KEY` is the one secret that cannot be regenerated without signing everyone out and
invalidating every enrolled terminal's credential, so back it up separately from the database):

| Setting | Production value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_URL` | the HTTPS URL terminals use |
| `APP_TIMEZONE` | the store's zone (default `Asia/Manila`); leave `DB_TIMEZONE` unset |
| `DB_*` | a dedicated role with a strong password; the database is reachable only from the app |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` |
| `SESSION_SECURE_COOKIE` | on by default outside local/testing (verified); serve over HTTPS |
| `TINDAFLOW_INITIAL_*` | first deploy only, then `php artisan db:seed --force` prints the generated admin password once |

Release steps: `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`,
`php artisan migrate --force`, `php artisan config:cache route:cache event:cache view:cache`. **PHP**:
`expose_php=Off` (the `X-Powered-By: PHP/8.4.x` header was visible), `display_errors=Off`, OPcache on. **Production
mode was checked**: an unhandled error returns `{"message":"Server Error"}` with no stack trace or paths; cookies are
`Secure; HttpOnly; SameSite=Strict`; the seeder creates one admin and **skips the demo data**. There are no queued
jobs and no scheduled tasks, so no worker or scheduler is needed (`QUEUE_CONNECTION=database` is unused). `/up` is
the health check; it proves the app boots, **not** that the database is reachable.

## 3. Deployment status (F9)

`docs/03-architecture/deployment.md` (a Stage 3 draft, baseline-protected, so left untouched) describes an nginx +
PHP-FPM + PostgreSQL Docker stack and says a later stage builds it. Until now **none of it existed**. It is built
in §6. Where the built stack differs from the draft, on purpose: it uses **PostgreSQL 17**, not the draft's 16 (a dump
from a newer server cannot be restored into an older one, and development and the drill use 17); the health check is
**database-aware** (`healthcheck.php`) rather than the draft's `/health`, which does not exist and which Laravel's `/up`
would not have satisfied anyway (it never touches the database); and the compiled assets are **baked into the nginx
image** rather than shared through a volume, because a volume keeps serving the previous release's files after an
upgrade.

## 4. Backup and restore: drilled

### 4.1 Procedure (what was run)

```bash
# Backup: a custom-format dump from a postgres:17 client container (no PostgreSQL install needed on the host)
docker run --rm -e PGPASSWORD=... -v <backup dir>:/backup postgres:17 \
  pg_dump -h <db host> -U <role> -Fc -f /backup/tindaflow-YYYYmmdd-HHMMSS.dump <database>

# Restore into a brand-new database
docker run --rm -e PGPASSWORD=... -v <backup dir>:/backup postgres:17 \
  pg_restore -h <db host> -U <role> -d <new database> --no-owner --exit-on-error /backup/<file>.dump
```

### 4.2 Result

Source: the development database (real test sales, voids, refunds, reprints, X and Z readings). Restored into an
empty database, then compared table by table.

- **41 tables, 206 rows: 0 mismatches**, by row count *and* by a per-row content checksum.
- **129 indexes and 218 constraints** present in both.
- `migrate:status`: nothing pending; the application run against the restored copy sees 7 sales, 7 invoices, last
  invoice number `000007`, and the stock balances.
- Timing: seconds for this size (the first run also pulled the image).

That is the drill ADR-008 asks for, on a small database. **It should be repeated on the real deployment before go-live,
and after any major change to the schema or the PostgreSQL version.** Not yet done: encrypting the dump, copying it
off the machine, retention pruning, and a scheduled job (part of the deployment artifacts, §6).

### 4.3 Owner decision: how much may be lost (F10)

ADR-008 proposes a **daily** dump. For a till, that means a crash in the afternoon can lose the whole day's sales,
which cannot be re-entered because invoice numbers and the journal are legal records. The recommendation, now
implemented as the default, is **hourly dumps kept for a day, then the first of each day kept 30 days, then the first of
each month kept 12** (all environment settings), copied off the machine. The most a crash can lose is one interval
(`BACKUP_INTERVAL_MINUTES`, default 60). If the store cannot accept an hour, the next step is PostgreSQL continuous
archiving for point-in-time recovery (to the minute, more to operate). The owner can change the numbers without a code
change; this is the owner's risk to accept.

## 5. Verified with no change needed

- **Dependencies**: `composer audit` and `npm audit --omit=dev` report nothing. PHPUnit has a newer major
  available; deliberately left.
- **Install from nothing in production mode**: 43 migrations run cleanly; the seeder creates one admin and no demo
  data.
- **Indexes** for the list and report queries (sales by store and time, shifts and fiscal days by terminal and time,
  journal and audit by time, products by store): present and adequate for a store's volume (about 100 000 sales a
  year). Watch items, each with a trigger rather than a fix now: sales history over all statuses uses a scan plus sort
  (the partial index covers only `COMPLETED`), and product search is `ILIKE '%x%'` with no trigram index. Revisit if
  a list takes more than about 200 ms on real data. Idempotency records are kept forever by design (ADR-010).
- **Login**: rate-limited, session regenerated on login, invalidated on logout, terminal credential cookie
  `HttpOnly; SameSite=Strict`.
- The database suite already uses its own `tindaflow_schema_test` database, separate from development data.

## 6. Deployment artifacts (approved and built)

Approval for the two new top-level folders was given, so they exist now.

**`.github/workflows/ci.yml`**: on every push to `main` and every pull request, a `test` job (PostgreSQL 17 service;
PHP 8.4; frontend build; `pint --test`; the Unit and Feature tests; the Database tests; the frozen-baseline check with
the `stage-*-baseline` tags fetched) and a `docker` job (builds both production images so a broken Dockerfile is caught
before a release). It could not be run from here; every step was run locally, and both YAML files parse.

**`docker/`** (operator runbook: `docker/README.md`):

| File | Purpose |
|---|---|
| `app/Dockerfile` | one file, two images: `app` (PHP-FPM 8.4 with `pdo_pgsql`, `intl`, `bcmath`, OPcache, production dependencies only) and `web` (nginx with the compiled assets baked in) |
| `app/php.ini` | `expose_php=Off`, `display_errors=Off`, OPcache without timestamp checks (the image is immutable) |
| `app/entrypoint.sh` | waits for the database, caches config/routes/events/views as `www-data`, applies migrations, starts PHP-FPM |
| `app/healthcheck.php` | healthy only if PostgreSQL answers a query with the app's own credentials |
| `nginx/default.conf` | TLS 1.2/1.3, HTTP redirects to HTTPS, hashed assets cached for a year, only `index.php` is executed, dotfiles denied, 6 MB body limit for the product import |
| `compose.yaml` | `web` (the only published ports), `app`, `postgres` (no published port, backend network only), `backup` |
| `backup/backup.sh`, `restore.sh` | verified, optionally AES-256 encrypted dumps on an interval, retention pruning, an off-machine hook; a restore that only ever writes into a new database |
| `.env.example`, `certs/`, `.dockerignore` | settings template; certificate folder (git-ignored contents); a small, secret-free build context |

### 6.1 The stack was built and run, not just written

`docker compose up -d --build` was run locally (both images, then the four containers, throwaway secrets and a
self-signed certificate), probed, and torn down. What was checked:

- **Build.** Both images build (app 851 MB, web 94 MB). The real build found a defect a read-through would not have:
  `.dockerignore` let the developer machine's cached `bootstrap/cache/*.php` into the image, which names dev-only
  packages and crashed package discovery. Fixed. The image holds no tests, docs or dev packages.
- **Start.** Postgres healthy, app healthy after applying all 43 migrations, web serving; only `web` publishes ports.
  The backup container was starting before the migrations and took a first dump of an empty database (898 bytes); it
  now waits for the app to be healthy.
- **Web tier.** `/up` 200 over TLS; every security header and HSTS present; `Server: nginx` with no version and no
  `X-Powered-By`; cookies `Secure; HttpOnly; SameSite=Strict`; plain HTTP redirects to HTTPS; a guest's CSV request is a
  clean `AUTHENTICATION_REQUIRED` 401; `*.php` and dotfiles are refused; a 7 MB upload is refused with 413 before it reaches
  the app. The application and the database session both report `Asia/Manila`.
- **Production seed.** Creates one administrator and prints the generated password once; no demo data.
- **Health check.** Reports failure (exit 1, "could not translate host name") with the database stopped and recovers
  when it returns; Laravel's `/up` cannot do either.
- **Backups.** A real dump (141 KB) is taken and read back before it is kept; encryption adds only the 19-byte salt
  header; the off-machine hook receives the file. **Restores** into a new database gave 41 tables, 129 indexes, 43
  migrations and the same users and stores; a wrong passphrase fails cleanly and leaves no half-made database; restoring
  over an existing database, or into a hostile name, is refused.
- **Retention.** The pruning was checked against an independent implementation of the policy on four cases, the largest
  685 backups spread over 16 months (kept exactly the expected 66); irregular gaps with encrypted names; a lone very old
  backup (never deleted); and an empty directory.

**The first real CI run** (on GitHub, after the push) passed the frontend build, `pint --test`, the Unit and Feature
tests, and the **production images build**, and failed in the Database tests: 508 of 522 passed, and the 14 that failed were
all the multi-process concurrency tests, `no password supplied`. They (and their six worker scripts) hard-coded
`127.0.0.1` / `postgres` / no password, so they only ever ran against a local trust-authenticated server, and they connect
to their own database, `tindaflow_concurrency_test`, which nothing created. That is a portability defect in the test suite
(no one with a password on their local PostgreSQL could have run them either), not in the application. Fixed the right way:
one `Tests\Database\PostgresTestConnection` helper driven by the same `PGSQL_TEST_*` variables the rest of the suite already
used, called by all six tests, all six workers and the base test case, with the same local defaults; and a CI step that
creates the concurrency database. The full Database suite passes locally (522), and the next GitHub run (`7c9f3ea`) was
**green** end to end. Not run from here: a real certificate. Two things to know: a redirect from plain HTTP drops a non-standard HTTPS port (only when 80 and 443 are
not the published ports), and running the certificate command in Git Bash needs `MSYS_NO_PATHCONV=1`; both are in
`docker/README.md`.

## 7. Verification

- New: `ProductionHardeningTest` (6: 401 whatever the `Accept`, headers, CSP contents, HSTS only over HTTPS, no CORS,
  request-id handling), `ApiThrottleHttpTest` (2), `BusinessTimezoneTest` (4). Updated: the renderer's unit test and
  `DatabaseConnectionTimezoneTest`, which now assert the store's zone.
- Browser checks against the built app in production mode: CSP boots the SPA and preserves the invoice frame (D3);
  an unauthenticated non-JSON request is a 401 (D2); a foreign-origin pre-flight gets no CORS headers (D4).
- Full regression: Unit 123 + Feature 7 = 130, Database 522, Pint clean, baselines hold.

## 8. Not done

The restore drill on the **real** deployment (it must be repeated there, on real data, before go-live); an
off-machine destination (the hook exists but the store has to choose one); PostgreSQL point-in-time recovery; and
per-store timezones.

## Addendum 2026-09-27 — two database roles, so the append-only rules are enforced

The invariant audit ([invariant-test-coverage.md](../02-domain/invariant-test-coverage.md), finding 1) found that
invariants #2, #23, #41, #45 and #48 were enforced only by the application not breaking them: the hardening script
that makes PostgreSQL enforce them was applied by nothing, and the Docker stack ran a single role (`POSTGRES_USER`, a
superuser inside its container, which ignores privileges) for both migrations and the running app.

**Decision.** The stack now has two roles, as `database-schema.md` §15 always described:

- The **owner** (`DB_OWNER_USERNAME`, default `tindaflow_owner`) is the postgres container's superuser. It creates and
  migrates the tables and is used only by the one-shot `migrate` service and by `restore.sh`.
- The **application role** `tindaflow_app` (`DB_USERNAME`) is what the running `app` connects as. It cannot create
  tables, cannot DELETE from anything, and cannot UPDATE the append-only tables; on `sales` it may update only `status`,
  and on `voids`/`refunds` only their lifecycle columns.

Mechanics: `docker/compose.yaml` gains a `migrate` service (the app image, run as the owner) that runs
`php artisan migrate --force` and then `php artisan tindaflow:harden-database`; `app` starts only after it succeeds.
The command creates or updates `tindaflow_app` (login and password from `DB_APP_PASSWORD`, which only `migrate` receives),
grants the ordinary privileges, applies `database/scripts/harden_append_only_privileges.sql`, then reads the result back
and rolls everything back if the database is not actually enforcing it (role not a superuser, audit/journal/stock
ledger refuse UPDATE, `sales.grand_total` refuses UPDATE while `sales.status` allows it, nothing deletable). It runs after
every migration, so a table a later release adds is covered. The `app` container has the owner's variables blanked, so the
running application never holds the owner's credentials. With a single shared role (local development) the command
refuses to run as the application role and nothing else changes.

Choices worth recording:

- **The application role's name is fixed** (`tindaflow_app`) because the script names it; the owner's name is free.
- **`RUN_MIGRATIONS` is gone.** Migrating moved out of the app entrypoint into `migrate`, which is the only place the
  owner's credentials are used at runtime. A failed `migrate` leaves the previous release running.
- **Backups still dump as the application role** (read access is enough; `deployment.md` already specified `<app_role>`).
  Restoring uses the owner, because only it can create a database; going live with a restored copy re-runs `migrate`,
  which re-provisions the role on it.
- **Existing single-role stacks upgrade in place**: keep the old login as `DB_OWNER_*` and give `tindaflow_app` a new
  password (docker/README.md, "Upgrading from one database role").

Verified against a real stack (built and run, then removed): `migrate` created the role, the app connected as
`tindaflow_app` with no owner variables in its environment, `db:seed` and `/up` worked, the role was refused UPDATE on
`audit_events`, the journal and the stock ledger, DELETE on `sales`, UPDATE of `sales.grand_total`, and CREATE TABLE, while
`sales.status` stayed updatable; a backup taken as the application role restored as the owner, and going live with the
restored copy re-locked it. Tests: `HardenDatabaseCommandTest` (6) and `AppendOnlyPrivilegesTest` (6, now driven by the command).

**Two existing defects found and fixed while doing this.**

- `BACKUP_DIR` in `.env` (the host folder, as the example file tells you to set it) also reached the backup container
  through `env_file`, so `backup.sh` wrote its dumps to that path inside the container, on its own disk, and never to the
  mounted host folder. The compose file now pins `BACKUP_DIR=/backups` inside the container.
- `docker/backup/backup.sh` and `restore.sh` were committed without the executable bit, so the backup container could not
  start from a git checkout (`exec ... permission denied`). They are now executable, and the container starts them
  through `bash` so a checkout that loses the bit (Windows, some archives) still works.

Not done: applying this to the real store server (an owner action: it needs the `.env` change above), and the
`..._restrict_application_role_privileges.php` migration that a Stage 5 comment cites, which never existed and lives in
the frozen corpus.

## Addendum 2026-09-27 (2) — a failed backup or a filling disk can no longer go unnoticed

Chosen by the architect review as the only remaining item that could lose data silently. `deployment.md` §7 already asked
for a disk-space check and greppable `[BACKUP]` status lines, and the manifest listed the alert as missing; the review found
a worse problem in the shipped script.

**The defect.** `backup.sh` ran `take_backup || log ...` in its loop. Bash switches `set -e` off inside a function called on
the left of `||` (verified with a three-line reproduction), so a failed step did not stop the function: a failed `pg_dump`
was followed by the readability check, the move and a "wrote ..." log line, a truncated dump could be kept as the newest
backup, and the function could return success. Abandoned `.partial` files were never pruned and could fill the disk.

**Now.**
- Every step of a backup is checked explicitly. A dump that fails, or that `pg_restore --list` cannot read, is deleted and
  never kept; a failed encryption never leaves the plaintext copy; a failed off-machine copy keeps the local one and is
  reported. `.partial` files older than one interval are pruned.
- The outcome is written atomically to `$BACKUP_DIR/.status` (`started`, `last_success`, `last_error`, `backup_state`,
  `upload_ok`, disk percentages and levels).
- `backup.sh health` is the container healthcheck: unhealthy when no backup has succeeded for 2.5 intervals (a fresh start
  gets the same window), when the off-machine copy is failing, or when a disk is critical. `docker/compose.yaml` adds the
  healthcheck to `backup`.
- Every interval it checks how full the backups folder and the database volume are (the volume is mounted read-only at
  `/pgdata` for this and nothing else): **80% warns, 90% is critical** (`DISK_WARN_PERCENT`, `DISK_CRIT_PERCENT`), the levels
  `deployment.md` §7 proposes. A disk it cannot read counts as critical, never as 0%.
- Log lines carry `[BACKUP]` or `[DISK]`. An optional `ALERT_COMMAND` (the message is `$1`, like `BACKUP_UPLOAD_COMMAND`) runs
  once when backups start failing, once when a disk crosses a level, and once on recovery, never on every tick. No channel is
  chosen or shipped.

**Not done, and needs you:** without `ALERT_COMMAND`, nobody is told unless they look at `docker compose ps` or the logs, and
an untrained shop owner will not. Choosing the channel (email, SMS or chat) and the off-machine destination is an owner
decision that should be made before the pilot. The 80% and 90% levels are `deployment.md`'s own proposal, "pending owner
approval"; they are configurable. Nothing in the till shows a backup problem (that would need a new endpoint, a frozen-corpus
exception). `deployment.md` itself is frozen and was not edited.

**Verification.** `scripts/test-backup.sh` (plain bash, stub `pg_dump`/`pg_restore`, no new tooling; it needs GNU date, so on a
Mac run it in the stack's image: `docker run --rm -v "$PWD":/w postgres:17 bash /w/scripts/test-backup.sh`): 49 checks
covering a failing dump, a truncated dump (earlier backups survive), recovery and alert-once behaviour, upload and encryption
failure, health, the disk levels (including an unreadable disk), abandoned partials, and the retention policy as a
regression. On a real built stack with a 1-minute interval: the first backup landed on the host folder with both disk
readings; with PostgreSQL stopped the failure was logged every minute, exactly one alert fired through a real
`ALERT_COMMAND`, and `backup` went unhealthy after about 4 minutes with the reason in its health output; restarting
PostgreSQL brought it back to healthy with a recovery alert. The stack was then removed.

## Addendum 2026-09-27 (3) — an expired session is a clean 401 and a sign-in dialog, not a dead end

Chosen by the architect review as a new risk: a till left idle past `SESSION_LIFETIME` (120 minutes, database driver) meets it
on the first morning of a pilot.

**What a till saw.** CSRF is checked before authentication in the `web` group, and an expired session has lost both its
session cookie and its XSRF token (or names a session the server has swept), so the next write was rejected as Laravel's
bare `419 {"message":"CSRF token mismatch."}`, outside the error envelope. The till showed "Checkout failed." (the fallback
text) with no way forward, and reloading lost the basket. Confirmed by a test against the real app with CSRF enforcement on
(`APP_ENV=testing` bypasses it).

**Backend.** `bootstrap/app.php` renders a 419 as the existing `401 AUTHENTICATION_REQUIRED` envelope (with `request_id`) when
the request has no signed-in user and is not the login request. Module A Decision Register §13 already says every expired or
absent session converges on that code, so no error code is added and the frozen corpus is untouched. Two 419s are deliberately
kept: a signed-in user with a wrong token (a genuine CSRF failure) and the login request without a token.

**Frontend.** `apiFetch` fires a `tindaflow:session-expired` event on any 401 outside `/auth/*`. `AuthContext` raises a flag
only if a user was signed in, and `SessionExpiredDialog` (mounted above the routes) asks for the password over the current
screen with the email prefilled. Nothing behind it unmounts, so a basket, a cash count or an unsaved form is exactly as it was.
After signing in the cashier presses the button again; a sale's idempotency key was dropped on the 401 because nothing was
saved. Signing in as a **different** user goes to the dashboard, because the open shift belongs to the previous user. Wrong
password, throttling (429) and an unreachable server each show their own message; "Sign out instead" is offered.

**Verified** in a real browser against a scratch database with database sessions: a two-item basket at the payment step, the
session row deleted on the server, Complete sale answered 401 and the dialog appeared with the order summary still behind it;
a wrong password was refused; the right one closed the dialog with the basket intact; pressing Complete sale again produced
exactly one sale, one invoice number (000001) and the right total; signing in as the admin from the dialog went to the
dashboard. Tests: `SessionExpiryHttpTest` (5; three fail on the old code). There is no automated frontend test (Vitest needs
your approval to add), so the dialog was verified by driving the browser.

**Watch items, not changed:** session lifetime stays 120 minutes (ask only if the pilot shows the dialog firing too often);
sessions, cache and `idempotency_records` live in the database and nothing prunes them on a schedule (Laravel's session
lottery sweeps sessions; the other two grow slowly), so revisit after the pilot.

## Addendum 2026-09-27 (4) — a production shop had no way to create its first till

Found by the architect review's last pass and verified by reading the code: nothing sells until a browser is enrolled as a
terminal, and enrolling needs a terminal row that already exists, but the API has no `terminalCreate` operation
(`TerminalsPage.jsx` said so itself), and the only other writer of `terminals` is `DemoDataSeeder`, which refuses to run in
production. A real shop could never get past first sign-in, and the README told the owner to "enroll the tills from Store
Setup" without saying where tills came from. Every earlier go-live walk-through probably ran on demo data.

**Decision.** `php artisan tindaflow:create-terminal {code} [--store=]`, a server-console tool like `tindaflow:reset-password`
(so no API operation or contract change is needed, and the frozen corpus is untouched). It creates an ACTIVE, never-enrolled
terminal (`activated_at` and the credential are set when a browser enrolls, as before), writes a `TERMINAL_CREATED` audit
event (actor null, since no signed-in user did it), and prints the next step. It uses the only store, requires `--store`
(name or id) when there are several, and refuses a blank or over-long code, a duplicate code in the same store (case
insensitive), an unknown store, and a server with no store yet (it says to create the administrator first, which creates the
store). Enrollment itself is unchanged: an administrator issues the token on the Terminals screen and enters it on the till.

Also refreshed, text only: `docker/README.md` gains the step (5) that creates each till and lists the rest of first-run setup
in order; the Terminals empty state now says who creates a till and how; and the Help guides describe behaviour added since
they were written (the session-expired dialog, "the connection dropped", the stale business-day notice, re-enrolling a
terminal whose credential was lost or revoked, and password recovery for a sole administrator), with no screenshots
regenerated.

Tests: `CreateTerminalCommandTest` (6, including one that creates the till with the command, issues an enrollment token,
enrolls, and opens a shift on it). Not driven in a browser: the new Help steps are text-only steps of the same shape as the
existing ones, and the empty-state text is one line.

## Addendum 2026-09-27 (5) — CORRECTION: the two-role change broke the running app; fixed and re-verified end to end

**What was wrong (my error, in addendum 1).** The hardening revoked DELETE on every table from `tindaflow_app` and its self-check
required "no table may be deletable". But the stack runs `SESSION_DRIVER=database` and `CACHE_STORE=database`, and the running app
deletes rows itself: Laravel deletes a session at login (the id is regenerated), at logout and in its sweep, and deletes an expired
cache row whenever it reads one, which the API rate limiter does on every request once its 60-second window has passed;
`ProductBarcodeService` and `StockCountService` delete barcode and count-line rows. PostgreSQL checks the privilege even when no
row matches. Reproduced on the real stack: **login returned 500, every request returned 500 after about a minute, and logout
returned 500**, with `permission denied for table cache` / `sessions` in the log. Following the owner checklist's first item would
have taken a shop down on its first day. Nothing caught it because the test suite uses the array cache and session drivers, the
privileges test built its world before switching role, and the earlier real-stack check only exercised `/up` and the seed, neither
of which is rate-limited or logs in.

**Fix.** `harden_append_only_privileges.sql` grants DELETE on exactly `sessions`, `cache`, `cache_locks`, `product_barcodes` and
`stock_count_lines` (none holds money or audit data) after the blanket revoke, and `tindaflow:harden-database` now requires the
deletable set to be **exactly** that list, so a financial or audit table becoming deletable, or the app losing one it needs, both fail
the run. `sales`, `sale_items`, `payments`, `invoices`, the audit log, the journal, the stock ledger and the readings stay undeletable.
`AppendOnlyPrivilegesTest` now switches to the stack's real drivers and proves the API keeps working after the rate-limit window
expires, logout and the session sweep work, and a barcode and a count line can be removed, all as the restricted role (4 tests; they
fail on the old script).

**Verified on a real built stack, from an empty volume, as a shop would set it up** (curl cookie jars, one per browser): seed the
admin, `tindaflow:create-terminal`, business details, tax registration, fiscal installation and terminal assignment, invoice series,
stock location, a cashier and a manager, two products, an alternate barcode added and removed; enroll a browser; the cashier opens a
shift and rings a sale, **waits 75 seconds** (past the rate-limit window), rings two more (one split-tender), cash in, X-reading,
logs out and in, closes the shift; the manager opens a shift, voids one sale, refunds another, closes; the administrator closes the
business day and reads the daily summary, audit log and journal; a backup as the app role restored as the owner (3 sales and 3
invoices back); and as the restricted role, updates to the audit log and stock ledger and deletes from `sales` and the journal were
still refused. 41 steps, 0 failed. Before the fix the same first steps failed as described above.

**A second defect found by the same walkthrough, fixed.** The `web` container's healthcheck (`wget https://localhost/up`) failed
every time: inside the nginx image `localhost` resolves to `::1` first and nginx listens on IPv4 only, so `web` was permanently
"unhealthy" while serving traffic perfectly, and the README's "all healthy" check could never be true. It now checks
`https://127.0.0.1/up`; all four services report healthy. (This matters more now that `unhealthy` on `backup` means a real problem.)

**Lesson recorded:** the earlier "verified on a real stack" claim covered only what the check exercised. A walkthrough that logs in,
waits past the rate-limit window, logs out and touches every table the app writes is the check that matters; it was a
recommendation of the architect review and it found this.

## Addendum 2026-09-27 (6) — a till's credential no longer expires on a fixed date

Found by the architect review (an inference from Chrome's cookie cap, then checked against the code): the `tindaflow_terminal`
credential cookie was set once at enrollment with a 5-year lifetime and never renewed. Browsers cap a cookie's lifetime (Chrome
at 400 days) whatever the server asks for, so every till enrolled on go-live day would have stopped selling, all on the same day,
about 13 months later ("This browser is not enrolled as any terminal"). Recoverable by re-enrolling, but a predictable, simultaneous
outage nobody would remember to prevent.

**Fix.** The cookie is built in one place (`TerminalCredentialCookie`, so its attributes cannot drift) and `ResolveTerminalContext`
renews it on every authenticated use of the till, with the same credential and a fresh lifetime. The lifetime is measured from the
till's last use; the default is 399 days (under the 400-day cap; `TERMINAL_CREDENTIAL_LIFETIME_MINUTES` still overrides). A till
lapses only if it sits unused for over a year, and re-enrolling it (an enrollment token for the same terminal) fixes that. A
credential that does not resolve (revoked, unknown, absent) is never renewed. It is a server-side change to an internal choice
(module A left the exact duration to the implementation), with no API, contract or schema change.

Tests: `TerminalCredentialRenewalTest` (4): a used till gets a renewed cookie with the same attributes and a fresh 399 days, and the
renewed cookie works as a credential; the configured lifetime stays within 400 days; a revoked terminal and a browser with no
credential get none. The renewal test fails on the old code. Both the original and the renewed cookie are encrypted by the web
middleware with a fresh IV, so their raw strings differ.

## Addendum 2026-09-27 (7) — keeping the restricted role and the code in step (process, from the final architect pass)

The DELETE regression (addendum 5) passed the whole suite because the suite runs as a PostgreSQL superuser. The set of statements
the application can issue against a table the restricted role may not change has now been enumerated and checked by grep: the app
DELETEs from exactly `product_barcodes` (`ProductBarcodeService`), `stock_count_lines` (`StockCountService`) and, through the
framework, `sessions`, `cache` and `cache_locks`, all on the allow-list; and it UPDATEs a revoked table only for `sales.status`
(`VoidService`, `RefundService`) and the void and refund lifecycle columns, all granted. There is no raw `UPDATE` or `DELETE` SQL
against those tables. Running the whole 753-test suite as the restricted role was judged not worth doing: it would mostly report
fixtures that write append-only tables directly, and the app-side risk is now small and enumerated.

**The rule that keeps it true.** Any feature or migration that adds an application `DELETE`, or a new `UPDATE` on a table the
hardening revokes (sales, sale items, payments, invoices, the audit log, the journal, the stock ledger, the readings, refund items
and settlements), must in the same change update `database/scripts/harden_append_only_privileges.sql` and
`AppendOnlyPrivilegesTest`. A new DELETE fails loudly at deploy (`tindaflow:harden-database` requires the deletable set to be
exactly its allow-list); a new UPDATE would still fail only at the till, so review for it.

**One check the suite cannot give, for the pilot.** After the first real week, have the manager exercise the paths the walkthrough
skipped, as the live restricted role: a stock receipt, transfer and count, a CSV import, a terminal revoke and re-enrol, a user
deactivate, and a void request followed by its approval, then look for `permission denied` in `docker compose logs app`.

## Addendum 2026-09-27 (8) — the owner checklist, decided by the architect at the owner's request

The owner asked the architect to decide the owner-checklist items ("let the software architect decide on the checklist"). These are
therefore the **owner's delegated decisions**, recorded as such; any edit to frozen text below cites this delegation. Physical
actions stay with the owner (section "Still yours").

**Decided and built**
- **Alert channel: a Telegram bot in a group chat that includes the owner and the manager.** Free, needs no server of the shop's own,
  reaches a phone with one `curl`; SMS gateways cost money and e-mail needs a mail server the shop does not have. `TELEGRAM_BOT_TOKEN`
  and `TELEGRAM_CHAT_ID` turn it on (`ALERT_COMMAND`, if set, wins). The token is passed to curl on stdin, never on its command line.
- **Off-machine backup: Backblaze B2 through rclone, with a bucket-scoped application key that has no delete permission.** About $6
  per terabyte-month after 10 GB free, and the key can be limited to one bucket; Google Drive tokens expire and are wrong for
  unattended use. `RCLONE_DEST` plus `RCLONE_CONFIG_*` variables turn it on (no config file; `BACKUP_UPLOAD_COMMAND`, if set, wins).
  The copy is `--immutable` (existing remote files are never overwritten), so with a no-delete key a compromised server cannot erase
  or replace its own off-machine backups. **An off-machine copy is refused unless the dump is encrypted** (`BACKUP_PASSPHRASE`, kept in
  the owner's password manager and a printed copy, never only on the server); `BACKUP_UPLOAD_UNENCRYPTED=yes` overrides it for a
  destination the owner fully controls. Fallback if B2 proves unsuitable: Cloudflare R2.
- **A dead-man's switch:** `HEARTBEAT_URL` is pinged after every good backup (a free healthchecks.io check alerts when the pings stop),
  because a dead machine sends no alert of its own.
- **Found while doing it:** the `backup` container was the stock `postgres:17`, which has no `curl`, no `rclone` and no `wget`, so
  the `ALERT_COMMAND` and `BACKUP_UPLOAD_COMMAND` hooks (and the `rclone copy` example in `.env.example`) could never have worked.
  It is now built from `docker/backup/Dockerfile` (`postgres:17` + `curl`, `ca-certificates`, `rclone`).
- **Verification.** `scripts/test-backup.sh` now has 74 checks (stub curl/rclone: the encryption guard, rclone arguments, Telegram
  on stdin and not in argv, alert-once, ALERT_COMMAND precedence, heartbeat on success only). And on real containers (built image, a
  real PostgreSQL and `pg_dump`, real `rclone` to a local destination standing in for B2, a stub Telegram server): a good backup was
  encrypted, copied and heartbeat-pinged; with the database stopped one failure alert was sent and no heartbeat; with it back a
  recovery alert; and the off-machine copy decrypted with the passphrase and restored with all rows (a wrong passphrase failed with
  "bad decrypt" and created nothing). Not verified against real Backblaze or Telegram (no accounts); the README says to test both
  before trusting them.

**Decided, to follow as their own slices** (each recorded here when shipped)
- **Frontend test tooling: approve Vitest, jsdom and @testing-library/react (dev-only); defer Playwright.** *(Built 2026-09-27, see the
  end of this addendum.)* The money-path logic
  (`attemptKey` retry keys, money formatting, and replacing the `Number(x).toFixed(2)` calls flagged as finding #55) is testable
  without a browser; Playwright needs a browser download and a running stack, and manual walk-throughs already work. Reconsider
  Playwright only if the pilot exposes a flow that breaks.
- **Hardware matrix (`docs/01-research/hardware-requirements-and-pricing.md`): approved as the supported floor**, landing in a new ADR
  with a one-line pointer from the frozen `deployment.md`; specifications and tiers, not a brand-specific bill of materials; memory
  and storage figures stay labelled estimates until the pilot measures them (`docker stats`, `df`); the real-time-clock requirement
  goes in the install checklist only.
- **Stage 29 packaging model (no base row, no per-store table): accepted.** Comparable systems use neither; the additive route stays open.
- **5% basic-necessities discount per-product flag: not built.** Which products are on the JAO list is a legal reading, and an
  auto-applied flag could discount or deny wrongly; the safe default stays (cashier judgment, beneficiary recorded). Revisit if a
  grocery pilot shows mis-rings, and get the accountant's or legal confirmation of the item mapping first.
- **Selling by the pack with its own price: not built.** It changes frozen checkout and no store has asked; it waits until one does.
- **Shift X-reading: "as of close".** The stored closing reading equals what was printed; a later void appears in the voiding shift and
  day, matching the tested refund attribution and the tested rule that a closed shift's stored figures cannot change. Invariant #40 is
  read as "recomputable from the ledger cut at the shift's `closed_at`". A clarification of frozen text (an exception recorded here).
- **Cash-out threshold: keep `>=` and correct the frozen text.** "Above" becomes "at or above" (invariants.md #39 and wherever the
  phrase appears), an exception recorded here: `>=` is the stricter cash control and is already pinned by a test.
- **Stale business day: never block sales, never auto-close.** An auto-close would create a fiscal reading nobody attested, and blocking
  would stop a working shop. The amber notice and the Fiscal Days marker stay.

**Still yours (nobody else can do these)**
1. Create the Telegram bot and group and note the token and chat id (about 15 minutes), following `docker/README.md`.
2. Create the Backblaze account, bucket and no-delete key; choose the passphrase and keep it in two places (about 45 minutes).
3. Put the values in `docker/.env`, rebuild, and fire a test alert and a test upload (about 20 minutes).
4. Take a backup and switch the real server to the two database roles (about 30 minutes).
5. `tindaflow:create-terminal`, finish Store Setup, enroll each till (about 30 minutes, plus 10 per extra till).
6. Restore drill from the Backblaze copy (about an hour).
7. Run the pilot for 14 days: a nightly Z-close, a daily one-line friction log, and the pilot-week check in addendum 7.

**Built: frontend tests and the money-formatting fix (2026-09-27).** `npm test` runs Vitest with jsdom (dev dependencies `vitest`,
`jsdom`, `@testing-library/react`, `@testing-library/dom`; `vitest.config.js`, separate from the build config; the CI job runs it).
54 tests over: the till's money arithmetic in whole centavos (`posMoney.js`, including that `3 x 0.10` is exactly 30 centavos); the
retry-safety rules (`pos/attempts.js`, extracted from `Pos.jsx` so they can be tested: the same request keeps its Idempotency-Key, any
change gets a new one, an unknown outcome keeps it, a definite answer or a 401 drops it); `apiFetch` (a 401 outside `/auth/*` signals
session-expired, a deadlock is retried once with the same key, and what a dropped connection or an HTML 502 looks like to a screen);
the business-day dates; and `SessionExpiredDialog` (prefilled email, wrong password, unreachable server, throttling, same user
stays put, different user goes to the dashboard). Two mutations (ignoring status 0 as an unknown outcome, and bringing the float
rounding back) each made tests fail. Playwright stays deferred.

**Finding #55 fixed.** `Pos.jsx` sent the cash-in/out amount and the declared cash as `Number(x).toFixed(2)`, which silently rounds a
typed `10.005` through a float and sends `NaN` for a half-typed box. It now sends `apiMoney(x)`: a valid amount is normalised in
whole centavos (`100` becomes `100.00`), and anything else is sent exactly as typed so the server refuses it with a field error
instead of the till quietly changing the figure. The close-shift variance colour no longer converts the amount to a float either.
The `package-lock.json` diff carries only the new packages (this machine's npm strips platform `libc` metadata, which was put back).

