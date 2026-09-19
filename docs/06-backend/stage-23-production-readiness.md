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
creates the concurrency database. The full Database suite passes locally (522); the workflow needs one more run to show
green on GitHub. Not run from here: a real certificate. Two things to know: a redirect from plain HTTP drops a non-standard HTTPS port (only when 80 and 443 are
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
