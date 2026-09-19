# Running TindaFlow in production

One command starts the whole store server: `docker compose up -d --build`. It runs four containers.

| Container | What it does | Reachable from |
|---|---|---|
| `web` | nginx: TLS, the compiled assets, hands requests to the app | the LAN or internet (ports 80 and 443 only) |
| `app` | PHP-FPM running TindaFlow; applies migrations when it starts | `web` only |
| `postgres` | PostgreSQL 17, the only copy of your sales | `app` and `backup` only, no published port |
| `backup` | dumps the database every hour, checks each dump, prunes old ones | itself |

Background and the decisions behind this: `docs/06-backend/stage-23-production-readiness.md`.

## First deploy

Needs Docker with the Compose plugin. Run everything from this `docker/` folder.

1. **Settings.** `cp .env.example .env`, then fill in:
   - `APP_KEY`: `docker run --rm -v "$PWD/..:/app" -w /app php:8.4-cli php artisan key:generate --show` (or run it anywhere you have the repo). **Back this up somewhere other than this machine**; without it every session and every enrolled terminal's credential becomes unreadable.
   - `DB_PASSWORD`: a long random one (`openssl rand -base64 24`).
   - `APP_URL`: the HTTPS address terminals will use. `APP_TIMEZONE`: the store's zone (default `Asia/Manila`).
2. **A certificate** in `certs/`, named `fullchain.pem` and `privkey.pem`. Cookies are `Secure`, so nobody can sign in over plain HTTP.
   - A public host name: use Let's Encrypt (any ACME client) and copy the two files here.
   - A store LAN with no public name: make a certificate for the server's name or IP, and install it on each till's browser as trusted:
     ```bash
     openssl req -x509 -newkey rsa:4096 -nodes -keyout certs/privkey.pem -out certs/fullchain.pem -days 825 \
       -subj "/CN=tindaflow.lan" -addext "subjectAltName=DNS:tindaflow.lan,IP:192.168.1.10"
     ```
     (In Git Bash on Windows prefix the command with `MSYS_NO_PATHCONV=1`, or it rewrites `/CN=`.)
3. **Start it.** `docker compose up -d --build`. The first start waits for the database, applies the migrations, and only then reports healthy.
4. **Create the first administrator.** `docker compose run --rm app php artisan db:seed --force`. It prints a generated password **once**; note it, sign in, and enroll the tills from Store Setup. (The demo data is never loaded in production.)

Check it: `docker compose ps` (all `healthy`) and `curl -k https://<server>/up`.

## Upgrading

```bash
git pull
docker compose up -d --build
```

The `web` image carries the compiled assets, so the code and the pages a browser loads always change together. Pending migrations run when `app` starts (`RUN_MIGRATIONS=0` to run them yourself). **Take a backup first** if the release changes the database: `docker compose exec backup backup.sh once`.

## Backups

The `backup` container writes a dump to `./backups` (`BACKUP_DIR`) every `BACKUP_INTERVAL_MINUTES` (default 60): **the most sales you can lose in a crash is one interval.** Each dump is checked readable before it is kept. Retention is every dump for 24 hours, then the first of each day for 30 days, then the first of each month for 12 months (all set in `.env`); pruning removes old copies only, never database rows.

- **Put `BACKUP_DIR` on a different disk from the database.** A backup on the same disk dies with it.
- **Encrypt:** set `BACKUP_PASSPHRASE`. Store the passphrase somewhere else; an encrypted backup is useless without it.
- **Copy off the machine:** set `BACKUP_UPLOAD_COMMAND` to any command that copies `$1` (rclone, rsync, scp, a script). Until you do, a fire, theft or flood takes the backups with the server.
- **See what happened:** `docker compose logs backup`.

### Restoring, and practising it

A restore only ever writes into a **new** database, so it cannot overwrite live data:

```bash
docker compose exec backup restore.sh /backups/tindaflow-20260920-101500.dump tindaflow_restored
```

It prints the table, index and migration counts, the number of sales and invoices, and the last invoice number. To go live with the copy: `docker compose stop app`, set `DB_DATABASE=tindaflow_restored` in `.env` (the `postgres` container keeps both databases), `docker compose up -d app`.

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
- **Secrets.** `docker/.env`, `docker/certs/*` and `docker/backups/` are git-ignored. Do not commit them.
