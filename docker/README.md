# Running TindaFlow in production

One command starts the whole store server: `docker compose up -d --build`. It runs four long-running containers and one that runs once.

| Container | What it does | Reachable from |
|---|---|---|
| `web` | nginx: TLS, the compiled assets, hands requests to the app | the LAN or internet (ports 80 and 443 only) |
| `app` | PHP-FPM running TindaFlow, connected to the database as the restricted `tindaflow_app` role | `web` only |
| `postgres` | PostgreSQL 17, the only copy of your sales | `app`, `migrate` and `backup` only, no published port |
| `backup` | dumps the database every hour, checks each dump, prunes old ones | itself |
| `migrate` | runs once at every `up`: applies migrations and locks down the application's database role, then exits | itself |

**Two database roles.** The *owner* (`DB_OWNER_USERNAME`) creates and migrates the tables; only `migrate` and the restore script use it. The application connects as `tindaflow_app`, and PostgreSQL itself refuses that role any UPDATE or DELETE on the tables that must be append-only (sale lines, payments, invoices, the audit log, the electronic journal, the stock ledger, X/Z readings) and lets it change only a sale's status and a void's or refund's own lifecycle columns. So a bug, or a stolen application credential, cannot rewrite what was sold. The running `app` container never holds the owner's password.

Background and the decisions behind this: `docs/06-backend/stage-23-production-readiness.md`.

## First deploy

Needs Docker with the Compose plugin. Run everything from this `docker/` folder.

1. **Settings.** `cp .env.example .env`, then fill in:
   - `APP_KEY`: `docker run --rm -v "$PWD/..:/app" -w /app php:8.4-cli php artisan key:generate --show` (or run it anywhere you have the repo). **Back this up somewhere other than this machine**; without it every session and every enrolled terminal's credential becomes unreadable.
   - `DB_OWNER_PASSWORD` and `DB_PASSWORD`: two different long random passwords (`openssl rand -base64 24` twice). The first belongs to the owner, the second to the application's `tindaflow_app` role, which `migrate` creates. Leave `DB_USERNAME=tindaflow_app` as it is: the database rules are written for that exact name.
   - `APP_URL`: the HTTPS address terminals will use. `APP_TIMEZONE`: the store's zone (default `Asia/Manila`).
2. **A certificate** in `certs/`, named `fullchain.pem` and `privkey.pem`. Cookies are `Secure`, so nobody can sign in over plain HTTP.
   - A public host name: use Let's Encrypt (any ACME client) and copy the two files here.
   - A store LAN with no public name: make a certificate for the server's name or IP, and install it on each till's browser as trusted:
     ```bash
     openssl req -x509 -newkey rsa:4096 -nodes -keyout certs/privkey.pem -out certs/fullchain.pem -days 825 \
       -subj "/CN=tindaflow.lan" -addext "subjectAltName=DNS:tindaflow.lan,IP:192.168.1.10"
     ```
     (In Git Bash on Windows prefix the command with `MSYS_NO_PATHCONV=1`, or it rewrites `/CN=`.)
3. **Start it.** `docker compose up -d --build`. The first start waits for the database, `migrate` applies the migrations and creates the `tindaflow_app` role, and only then does `app` start and report healthy. `docker compose logs migrate` shows what it did.
4. **Create the first administrator.** `docker compose run --rm app php artisan db:seed --force`. It prints a generated password **once**; note it, sign in, and enroll the tills from Store Setup. (The demo data is never loaded in production.)

Check it: `docker compose ps` (all `healthy`) and `curl -k https://<server>/up`.

## Upgrading

```bash
git pull
docker compose up -d --build
```

The `web` image carries the compiled assets, so the code and the pages a browser loads always change together. Pending migrations run in the `migrate` step, which finishes before `app` starts (and re-applies the database privileges, so a table added by the release is covered). If `migrate` fails, `app` does not start and the previous release keeps running; read `docker compose logs migrate`. **Take a backup first** if the release changes the database: `docker compose exec backup backup.sh once`.

### Upgrading from one database role

A stack first deployed with a single `DB_USERNAME` (the earlier setup) keeps working until you switch, and the switch keeps your data. Take a backup first, then in `docker/.env`:

1. Keep the existing login as the owner: `DB_OWNER_USERNAME=<the old DB_USERNAME>` and `DB_OWNER_PASSWORD=<the old DB_PASSWORD>`.
2. Set `DB_USERNAME=tindaflow_app` and a **new** `DB_PASSWORD`.
3. `git pull && docker compose up -d --build`. `migrate` creates the `tindaflow_app` role and locks it down; nothing about your data changes.

Check it took effect: `docker compose exec postgres psql -U <owner> -d tindaflow -c "select has_table_privilege('tindaflow_app','audit_events','UPDATE')"` must print `f`.

## Forgot the administrator's password

A small shop's server sends no e-mail, so there is no "forgot password" link, and a sole administrator has nobody else who could reset it from Users. Anyone who can run commands on the server can:

```bash
docker compose exec app php artisan tindaflow:reset-password owner@example.com
```

It sets a new strong password, **prints it once** (copy it now; it is stored nowhere in plain text), ends every session that user has, and records a `PASSWORD_RESET` entry in the audit log. Sign in with it and change it under Users. If the user was deactivated, add `--activate`. It refuses an unknown e-mail, and an e-mail used in more than one store, without changing anything.

## Backups

The `backup` container writes a dump to `./backups` (`BACKUP_DIR`) every `BACKUP_INTERVAL_MINUTES` (default 60): **the most sales you can lose in a crash is one interval.** Each dump is checked readable before it is kept. Retention is every dump for 24 hours, then the first of each day for 30 days, then the first of each month for 12 months (all set in `.env`); pruning removes old copies only, never database rows.

- **Put `BACKUP_DIR` on a different disk from the database.** A backup on the same disk dies with it.
- **Encrypt:** set `BACKUP_PASSPHRASE`. Store the passphrase somewhere else; an encrypted backup is useless without it.
- **Copy off the machine:** set `BACKUP_UPLOAD_COMMAND` to any command that copies `$1` (rclone, rsync, scp, a script). Until you do, a fire, theft or flood takes the backups with the server.
- **See what happened:** `docker compose logs backup`. Every line carries `[BACKUP]` or `[DISK]`, so `docker compose logs backup | grep -E "FAILED|WARN|CRITICAL|ALERT"` shows every problem.
- **A failed backup is never silent.** A dump that fails, or that cannot be read back, is deleted and never kept, and it never ages out a good backup. The `backup` container turns **unhealthy** (`docker compose ps`) when no backup has succeeded for two and a half intervals, when the off-machine copy is failing, or when a disk is critically full. `docker compose exec backup bash /usr/local/bin/backup.sh health` says why.
- **Watch the disks.** Every interval the backup container checks how full the backups folder and the database volume are: **80% logs a warning, 90% is critical** (`DISK_WARN_PERCENT`, `DISK_CRIT_PERCENT`). A full database disk stops PostgreSQL, and then every till stops selling.
- **Get told (optional):** set `ALERT_COMMAND` to any command that takes the message as `$1` (a script that sends an email, an SMS or a chat message). It runs once when backups start failing, once when a disk crosses a level, and once when either recovers, not every hour. Without it, nobody is told unless they look, so **an unattended shop should set it before go-live**.

### Restoring, and practising it

A restore only ever writes into a **new** database, so it cannot overwrite live data (it uses the owner's login, `DB_OWNER_*`; the application's role cannot create databases):

```bash
docker compose exec backup restore.sh /backups/tindaflow-20260920-101500.dump tindaflow_restored
```

It prints the table, index and migration counts, the number of sales and invoices, and the last invoice number. To go live with the copy: `docker compose stop app`, set `DB_DATABASE=tindaflow_restored` in `.env` (the `postgres` container keeps both databases), `docker compose up -d app`. That runs `migrate` again, which gives `tindaflow_app` the same locked-down access on the restored copy.

**Do this drill before go-live, and again after any PostgreSQL major upgrade or big schema change**: a backup you have never restored is a hope, not a backup. Drop the practice database afterwards.

## Everyday

| Need | Command |
|---|---|
| Are things healthy? | `docker compose ps` |
| App logs (files rotate daily, 14 kept) | `docker compose exec app ls storage/logs` |
| Follow a failed request | search the logs for the `request_id` the error response showed (also the `X-Request-ID` header) |
| Run an Artisan command | `docker compose exec app php artisan <command>` |
| Stop / start | `docker compose stop` / `docker compose start` (data lives in the `pgdata` volume) |

Never run `docker compose down -v`: `-v` deletes the `pgdata` volume, which is the database.

## Notes

- **Ports.** If 80 or 443 are taken, set `HTTP_PORT` and `HTTPS_PORT`; the plain-HTTP redirect assumes the standard ports.
- **Health.** `app` is healthy only if it can reach PostgreSQL (`healthcheck.php`); Laravel's `/up` alone never touches the database.
- **Owner credentials.** They live in `docker/.env` because compose hands them to `postgres` and `migrate`, but the `app` container has them blanked out. Anyone who can run `docker compose exec postgres` or read `docker/.env` on the host is still the owner, as before; the protection is against the running application, not against the person administering the server.
- **Secrets.** `docker/.env`, `docker/certs/*` and `docker/backups/` are git-ignored. Do not commit them.
