# Running TindaFlow in production

One command starts the whole store server: `docker compose up -d --build`. It runs four long-running containers and one that runs once.

| Container | What it does | Reachable from |
|---|---|---|
| `web` | nginx: TLS, the compiled assets, hands requests to the app | the LAN or internet (ports 80 and 443 only) |
| `app` | PHP-FPM running TindaFlow, connected to the database as the restricted `tindaflow_app` role | `web` only |
| `postgres` | PostgreSQL 17, the only copy of your sales | `app`, `migrate` and `backup` only, no published port |
| `backup` | dumps the database every hour and archives the shop's files, checks both, prunes old ones | itself |
| `migrate` | runs once at every `up`: applies migrations and locks down the application's database role, then exits | itself |

**Two database roles.** The *owner* (`DB_OWNER_USERNAME`) creates and migrates the tables; only `migrate` and the restore script use it. The application connects as `tindaflow_app`, and PostgreSQL itself refuses that role any UPDATE or DELETE on the tables that must be append-only (sale lines, payments, invoices, slips, the audit log, the electronic journal, the stock ledger, X/Z readings) and lets it change only a sale's status and a void's or refund's own lifecycle columns. The only tables it may DELETE from are the session and cache tables Laravel keeps housekeeping in and two child tables the app removes rows from (alternate barcodes, stock-count lines); `tindaflow:harden-database` refuses to finish if the set is anything else. So a bug, or a stolen application credential, cannot rewrite what was sold. The running `app` container never holds the owner's password.

Background and the decisions behind this: `docs/06-backend/stage-23-production-readiness.md`.

## First deploy

Needs Docker with the Compose plugin. Run everything from this `docker/` folder.

1. **Settings.** `cp .env.example .env`, then fill in:
   - `APP_KEY`: `docker run --rm -v "$PWD/..:/app" -w /app php:8.4-cli php artisan key:generate --show` (or run it anywhere you have the repo). **Back this up somewhere other than this machine**; without it every session and every enrolled terminal's credential becomes unreadable.
   - `DB_OWNER_PASSWORD` and `DB_PASSWORD`: two different long random passwords (`openssl rand -base64 24` twice). The first belongs to the owner, the second to the application's `tindaflow_app` role, which `migrate` creates. Leave `DB_USERNAME=tindaflow_app` as it is: the database rules are written for that exact name.
   - `APP_URL`: the HTTPS address terminals will use. `APP_TIMEZONE`: the store's zone (default `Asia/Manila`).
2. **A certificate** in `certs/`, named `fullchain.pem` and `privkey.pem`. Cookies are `Secure`, so nobody can sign in over plain HTTP.
   - A public host name (a VPS): `tls/letsencrypt.sh issue <host-name> <your e-mail>` gets a free Let's Encrypt certificate and puts the two files here. See "A public host name" below for the renewal.
   - A store LAN with no public name: make a certificate for the server's name or IP, and install it on each till's browser as trusted:
     ```bash
     openssl req -x509 -newkey rsa:4096 -nodes -keyout certs/privkey.pem -out certs/fullchain.pem -days 825 \
       -subj "/CN=tindaflow.lan" -addext "subjectAltName=DNS:tindaflow.lan,IP:192.168.1.10"
     ```
     (In Git Bash on Windows prefix the command with `MSYS_NO_PATHCONV=1`, or it rewrites `/CN=`.)
3. **Start it.** `docker compose up -d --build`. The first start waits for the database, `migrate` applies the migrations and creates the `tindaflow_app` role, and only then does `app` start and report healthy. `docker compose logs migrate` shows what it did.
4. **Create the first administrator.** `docker compose run --rm app php artisan db:seed --force`. It prints a generated password **once**; note it and sign in. (The demo data is never loaded in production.)
5. **Set up the store and its tills.** The fiscal setup (tax registration, fiscal installation, invoice series, which till is under which installation) is **locked** for the shop's administrators; you open it for a time-boxed window during installation or a support visit: `docker compose exec app php artisan tindaflow:fiscal-setup unlock --minutes=120` (`lock` closes it early, `status` shows it; `--store="<name>"` only if the server has more than one store). Then sign in as the administrator, finish **Store Setup** (business details, tax registration, fiscal installation and its invoice series, a stock location), open **Terminals**, type the till's name under **Add terminal**, click **Enrollment token** on its row, and enter the token on the till computer. (Scripted setup can still create a till from the server: `docker compose exec app php artisan tindaflow:create-terminal TILL-1`.) Lock it again when you leave. Nothing sells until that is done.
6. **Optional: cap the tills with a license.** To limit how many tills a shop can run, you sign a license for that installation on **your own machine** (never on the client's server). Once: `php artisan tindaflow:license-keygen --private-out=/safe/place/license-private.key`, then paste the printed public key into `license.public_key` in `config/tindaflow.php` and rebuild the image (it is a constant in code on purpose). Per shop: read the installation's code with `docker compose exec app php artisan tindaflow:license-status`, sign with `php artisan tindaflow:license-issue --private-key=/safe/place/license-private.key --deployment=<code> --max-terminals=3 --out=license.key`, and put the file at `storage/app/license.key` on the client's server (or set `TINDAFLOW_LICENSE`). A license never stops a till that is already enrolled; it only blocks adding more, or re-enrolling a revoked one into a seat that is taken. Without a public key in the build, there is no cap.

Check it: `docker compose ps` (all `healthy`) and `curl -k https://<server>/up`.

## A public host name (a rented server)

For a server on the internet with its own name, such as `beta.example.com`. A shop LAN does not need any of this.

1. **Point the name at the server** (an `A` record in your DNS) and open ports 80 and 443 in the firewall, nothing else besides SSH. Set `APP_URL=https://<host-name>` in `.env`.
2. **Get the certificate, before the first `docker compose up`:** `tls/letsencrypt.sh issue <host-name> <your e-mail>`. It runs certbot in a throwaway container (nothing is installed), accepts the Let's Encrypt Subscriber Agreement for you, and writes `certs/fullchain.pem` and `certs/privkey.pem`. The e-mail is where Let's Encrypt sends expiry warnings. Then continue the first deploy from step 3.
3. **Renew automatically.** A Let's Encrypt certificate lasts 90 days. Add one line to the server's crontab (`crontab -e`), with the real path:
   ```
   17 3 * * * /opt/tindaflow/docker/tls/letsencrypt.sh renew >> /var/log/tindaflow-tls.log 2>&1
   ```
   Every night it asks certbot whether a renewal is due (under 30 days left); when one happens it installs the new certificate and reloads nginx without dropping a connection. If the installed certificate ever has fewer than 14 days left, it sends the same alert the backups use (Telegram, below). Run it once by hand to see it work: it should print nothing alarming and exit quietly.

Keep `docker/letsencrypt/` (certbot's account and keys; git ignores it) and never publish it. Renewal needs port 80 reachable from the internet.

**Sample products for beta testers.** A production server starts with an empty catalog (the demo data is never loaded there). To give testers something to sell: `docker compose exec app php artisan tindaflow:load-sample-data`. It asks before changing anything, then adds five categories and 28 products whose SKUs start with `SAMPLE-`, through the same import the catalog screen uses (so each is in the audit log). It creates no user, till, tax registration, stock or barcode. Running it again puts the sample products back to their original values and touches nothing else. Do not run it on a shop that is really trading.

**The canteen menu.** A canteen server starts with no menu groups, dishes or meals (the seeders refuse production). To load the starting menu: `docker compose exec app php artisan tindaflow:load-canteen-menu`. It asks before changing anything, then adds the 7 menu groups and their sub-groups, the dish categories and dishes, Standard Meal and Combo Meal with their choices, the rice add-ons (whole 15, half 10) and the opening prices (Standard: beef 68, chicken/pork/fish 60, vegetable 60; Combo: beef 85, chicken/pork/fish 80, **an assumption until the client confirms**). It creates menu rows only, attributed to the store's first administrator, and records a `CANTEEN_MENU_LOADED` audit event. Running it again adds nothing that already exists and never undoes an admin's change, but it matches by name, so load it once and then manage the menu on the Meal menu admin screens. `--store="<name>"` only if the server has more than one store.

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

## Replacing the shop's computer

For a licensed shop on a new computer, or the same one after a repair that changed its hardware. This applies only to a build that carries a vendor key. Until the new license is applied, a locked shop cannot open a new shift.

1. **On the new computer, restore the shop** ("Restoring a whole shop", below), and install the Windows host helper; its `-Check` must pass.
2. **Make the request** and send `replace-request.json` to your TindaFlow provider. Say what happened to the old computer. The request makes a new identity for this computer; nothing changes until you apply the reply.
   ```bash
   docker compose exec app php artisan tindaflow:activate --request --replace --out=/tmp/replace-request.json
   docker compose cp app:/tmp/replace-request.json .
   ```
3. **The provider** licenses the new computer and retires the old one:
   ```bash
   php artisan license:replace-computer DEPLOYMENT_CODE replace-request.json --reason="The old computer died" --out=bundle.txt
   ```
4. **Apply the bundle.** It changes only the license: no user, setting, till, stock or sale.
   ```bash
   docker compose cp bundle.txt app:/tmp/bundle.txt
   docker compose exec app php artisan tindaflow:activate --bundle=/tmp/bundle.txt --replace
   docker compose exec app php artisan tindaflow:license-status
   ```
   If the power fails while applying, apply the same bundle again. If the bundle is lost, the provider runs the same command again.
5. **Switch the old computer off.** It is never renewed again, and the provider is told whenever it tries. Until its last certificate runs out (up to about 30 days, longer with an extension code) it could still sell if someone ran it offline, so wipe it or take it out of service. Anything it sells after the backup you restored is not in the new computer's records.

Extension codes and their count start again on the new computer. A shop whose license was revoked cannot be replaced.

## Licensing a shop that already trades

This applies only to a build that carries a vendor key. Today no build does. `--adopt` records the license for the shop already on the computer and **changes nothing else**: no user, setting, till, stock or sale. (A new installation that will be licensed uses `tindaflow:activate` without `--adopt`, and must not run `db:seed` first. If it already did, adopt it.)

**Order matters.** A build with a vendor key refuses new shifts until a license is applied, and stops an open shift's sales 18 hours after it opened. So get the license **before** you put that build on the shop's computer. Run everything from the `docker/` folder; the commands work in PowerShell and bash.

1. **Update to a version that has `--adopt` and still carries no vendor key.** Nothing changes for the shop. The Windows host helper must be installed and its `-Check` must pass; a computer that cannot give the three hardware values cannot be licensed yet.
2. **Make the request** and send `adopt-request.json` to your TindaFlow provider.
   ```bash
   docker compose exec app php artisan tindaflow:activate --request --adopt --out=/tmp/adopt-request.json
   docker compose cp app:/tmp/adopt-request.json .
   ```
3. **The provider** registers the shop with the store id it already has, and sends back a bundle. Ask for **report mode** the first time: it records what it would refuse, and refuses nothing.
   ```bash
   php artisan license:register-client "Client name" --existing=adopt-request.json --seats=2 --mode=report
   php artisan license:issue-bundle DEPLOYMENT_CODE adopt-request.json --out=bundle.txt
   ```
   The certificate in it is good for about 30 days from when it was made.
4. **With `bundle.txt` in hand, outside trading hours, with no shift open:** take a backup (`docker compose exec backup bash /usr/local/bin/backup.sh once`) and copy both of its files off the computer. Keep the version you are running, so that you can go back to it.
5. **Update to the version with the vendor key, and apply the bundle at once:**
   ```bash
   docker compose up -d --build
   docker compose cp bundle.txt app:/tmp/bundle.txt
   docker compose exec app php artisan tindaflow:activate --bundle=/tmp/bundle.txt --adopt
   docker compose exec app php artisan tindaflow:license-status
   ```
6. **If the bundle is refused**, nothing was changed. Go back to the previous version, which refuses nothing, and send the message to your provider.

If the shop has more tills than the license covers, the command says so. Nothing is blocked: the till limit in the license is not enforced yet. A till limit set the older way (`license.public_key`) keeps working as before.

## A license that has run out, or a clock lock

This applies only to a build that carries a vendor key. Today no build does, so nothing here can happen yet. `docker compose exec app php artisan tindaflow:license-status` shows the state. Selling resumes only when a new shift is allowed again; closing a shift, reports, reprints, voids and refunds are never blocked.

- **With internet:** choose **Check license now** on the License screen, or run `docker compose exec app php artisan tindaflow:license-renew`. If the provider had suspended the shop, it must resume it first.
- **No internet: renew by file.** The files travel any way you like (USB stick, e-mail from a phone). Run these from the `docker/` folder; they work in PowerShell and bash. (Let the command write the file, as shown: redirecting its output in Windows PowerShell changes the file's encoding.)
  ```bash
  docker compose exec app php artisan tindaflow:license-renew --request --out=/tmp/renewal-request.txt
  docker compose cp app:/tmp/renewal-request.txt .
  ```
  Send `renewal-request.txt` to your TindaFlow provider. Put the reply, `renewal-reply.json`, in this folder, then:
  ```bash
  docker compose cp renewal-reply.json app:/tmp/renewal-reply.json
  docker compose exec app php artisan tindaflow:license-renew --apply=/tmp/renewal-reply.json
  ```
  The request holds no secret key, but it does hold the license and the hardware hashes. It needs the host helper's file to be current. If the reply is lost, send the same request again. **Apply the reply before it runs out** (it is good for about 30 days from when the provider made it).
- **Locked because of the clock** (`CLOCK_ROLLBACK`): first set the computer's date and time correctly. If it is still locked, the computer's clock was once ahead. Online, renew as above; if the last renewal was under an hour ago, wait and try again. Offline, ask your provider for a time-reset code and type it on the License screen. A reply file never unlocks a clock lock, on purpose.
- **Locked because the hardware changed:** replace the computer (below), even if it is the same computer after a repair.
- **A forked chain** (the message says the provider reports one): usually a restored backup older than the latest license. Tell your TindaFlow provider what happened and the **certificate number** this computer holds (the License screen, or `license-status`). The provider checks with the shop's owner and approves that number (`license:rechain`); within 7 days, renew as above, online or by file. The approval works once, and any other computer holding a later license is refused from then on, so say if one exists.
- **The provider has changed its key** (the message says the software does not accept the new one, or the provider asks you to update): update TindaFlow ("Upgrading"), then renew as above. After a stolen key, **do the update when you can renew straight away**: from the moment the new version runs until it has renewed once, no new shift opens. With internet the scheduler renews at start-up; `docker compose exec app php artisan tindaflow:license-renew` does it at once (the License screen's button is off in this state). With no internet, renew by file right after updating.
- **A revocation:** there is no way back. Contact your TindaFlow provider.

## Backups

The `backup` container writes a dump to `./backups` (`BACKUP_DIR`) every `BACKUP_INTERVAL_MINUTES` (default 60): **the most sales you can lose in a crash is one interval.** Each dump is checked readable before it is kept. Retention is every dump for 24 hours, then the first of each day for 30 days, then the first of each month for 12 months (all set in `.env`); pruning removes old copies only, never database rows.

- **A dump alone cannot rebuild a shop.** Beside each `tindaflow-<time>.dump.enc` the container writes `tindaflow-<time>.files.tar.enc`: `docker/.env` (with `APP_KEY` and every password), the HTTPS certificate in `certs/`, and the app's stored files (`storage/app`: the license and this installation's identity key). All of it is secret, so **it is written only when `BACKUP_PASSPHRASE` is set**; without one the log warns every interval that only the database is backed up. The two are kept, pruned and copied off the machine together. A missing certificate or a settings file without `APP_KEY` is a failed backup, like a failed dump. Not in it: logs, `docker/letsencrypt/` (get a new certificate instead) and the program itself.
- **Put `BACKUP_DIR` on a different disk from the database.** A backup on the same disk dies with it.
- **Encrypt:** set `BACKUP_PASSPHRASE`. Store the passphrase somewhere else; an encrypted backup is useless without it.
- **Copy off the machine:** see "Off-machine copy and alerts" below. Until you do, a fire, theft or flood takes the backups with the server.
- **See what happened:** `docker compose logs backup`. Every line carries `[BACKUP]` or `[DISK]`, so `docker compose logs backup | grep -E "FAILED|WARN|CRITICAL|ALERT"` shows every problem.
- **A failed backup is never silent.** A dump that fails, or that cannot be read back, is deleted and never kept, and it never ages out a good backup. The `backup` container turns **unhealthy** (`docker compose ps`) when no backup has succeeded for two and a half intervals, when the off-machine copy is failing, or when a disk is critically full. `docker compose exec backup bash /usr/local/bin/backup.sh health` says why.
- **Watch the disks.** Every interval the backup container checks how full the backups folder and the database volume are: **80% logs a warning, 90% is critical** (`DISK_WARN_PERCENT`, `DISK_CRIT_PERCENT`). A full database disk stops PostgreSQL, and then every till stops selling.
- **Get told:** see "Off-machine copy and alerts" below. Without it, nobody is told unless they look, so **an unattended shop should set it up before go-live**.

### Off-machine copy and alerts (set up once, about an hour)

The `backup` container is `postgres:17` plus `curl` and `rclone` (`docker/backup/Dockerfile`; `docker compose up -d --build` builds it). Four settings in `docker/.env` turn on everything below; each step says how to test it.

**1. A passphrase (required before anything leaves the machine).** Set `BACKUP_PASSPHRASE` to a long random phrase (`openssl rand -base64 24`). Put it in your password manager **and** on a printed copy in a safe place, never only on the server: a backup you cannot decrypt is useless. An off-machine copy is refused without it.

**2. A Backblaze B2 bucket (the off-machine copy).** About $6 per terabyte-month (the first 10 GB are free); a shop's backups are far smaller.
1. Create a Backblaze account, then a **private** bucket (for example `tindaflow-backups-nena`).
2. Under *Application Keys*, add a key **limited to that bucket** with **List, Read and Write** but **not Delete**. A key that cannot delete means even a hacked server cannot erase its own off-machine backups (the upload only ever adds files).
3. On the bucket, set a lifecycle rule (*Keep only the last version*, or delete files after about 400 days) so it does not grow forever.
4. In `docker/.env` set `RCLONE_DEST=b2:<bucket>/<shop>`, `RCLONE_CONFIG_B2_TYPE=b2`, `RCLONE_CONFIG_B2_ACCOUNT=<keyID>`, `RCLONE_CONFIG_B2_KEY=<application key>`.
5. `docker compose up -d --build backup`, then `docker compose exec backup bash /usr/local/bin/backup.sh once`. The last log line must say `uploaded ...`; the file is in your bucket. **Test this before you trust it.**

**3. A Telegram bot (the alert).** Free, no server of your own, and it reaches a phone.
1. In Telegram, message **@BotFather**, send `/newbot`, follow the prompts, and copy the **token** it gives you.
2. Create a Telegram group with the owner and the manager, add your new bot to it, and send any message in the group.
3. Open `https://api.telegram.org/bot<TOKEN>/getUpdates` in a browser and find `"chat":{"id":-100...` : that number (with the minus sign) is the **chat id**.
4. In `docker/.env` set `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` and `ALERT_SITE_NAME` (so a message says which shop it is from).
5. `docker compose up -d --build backup`, then send a test: `docker compose exec backup bash /usr/local/bin/alert-telegram.sh "Test alert from the shop server"`. It must appear in the group. **Test this before you trust it.**

**4. A dead-man's switch (recommended).** A dead machine sends no alert. Make a free check at healthchecks.io with a **1 hour** period and **2 hour** grace, and put its ping URL in `HEARTBEAT_URL`. The backup container pings it after every good backup; healthchecks.io messages you when the pings stop.

**Restore drill from the off-machine copy** (do this before go-live, and again after big changes; a backup never restored is a hope):

```bash
docker compose exec backup rclone ls "$RCLONE_DEST"                                      # find a file name (there are two per time stamp)
docker compose exec backup rclone copyto "$RCLONE_DEST/<file>.dump.enc" /backups/drill.dump.enc
docker compose exec backup restore.sh /backups/drill.dump.enc tindaflow_drill            # uses BACKUP_PASSPHRASE
```

It prints the table, index and migration counts, the number of sales and invoices, and the last invoice number; check them against the shop. Drop the practice database afterwards.

### Restoring, and practising it

A restore only ever writes into a **new** database, so it cannot overwrite live data (it uses the owner's login, `DB_OWNER_*`; the application's role cannot create databases):

```bash
docker compose exec backup restore.sh /backups/tindaflow-20260920-101500.dump tindaflow_restored
```

It prints the table, index and migration counts, the number of sales and invoices, and the last invoice number. The restore takes no privileges from the dump (the application's role may not exist yet), so until `migrate` has run only the owner can read the copy. To go live with it: `docker compose stop app scheduler backup`, set `DB_DATABASE=tindaflow_restored` in `.env` (the `postgres` container keeps both databases), then `docker compose up -d`. That runs `migrate` again, which gives `tindaflow_app` the same locked-down access on the restored copy. **Name no service in that last command:** `up -d app` would leave the backups and the scheduler on the old database.

**Do this drill before go-live, and again after any PostgreSQL major upgrade or big schema change**: a backup you have never restored is a hope, not a backup. Drop the practice database afterwards.

A backup you take by hand (`backup.sh once`) is pruned by the same rules as the hourly ones. To keep one, before an upgrade for instance, copy both of its files somewhere else.

### Restoring a whole shop (a new or rebuilt computer)

You need three things, and nothing else from the old computer:

1. **The program**, the same version as the shop ran, or a newer one. Never an older one.
2. **The two backup files with the same time stamp**: `tindaflow-<time>.dump.enc` and `tindaflow-<time>.files.tar.enc`. You lose what was sold after that time.
3. **The `BACKUP_PASSPHRASE`.** It is inside the files archive too, which cannot be opened without it, so it must be kept somewhere else.

> **Do not practise this in a second folder on the shop's own computer as written.** `compose.yaml` names the project `tindaflow`, so any copy of this folder uses the **live** shop's database and files. Practise on another computer. If you must use the same one, add `-p tindaflow-drill` to **every** `docker compose` command, give the copy other `HTTP_PORT` and `HTTPS_PORT` values and its own `BACKUP_DIR`, and stop if any command works on a container whose name does not start with `tindaflow-drill`.

The commands below are written to work in PowerShell and in bash. Run them from the new `docker/` folder. `<time>` is the time stamp in the file names.

1. **Put the two files in `backups/`** (create the folder).
2. **Give the passphrase to this window.** PowerShell: `$env:BACKUP_PASSPHRASE = '<passphrase>'`. Bash: `export BACKUP_PASSPHRASE='<passphrase>'`.
3. **Open the files archive.** On Linux add `--user "$(id -u):$(id -g)"` after `--rm`, so that the files are yours.
   ```bash
   docker run --rm -e BACKUP_PASSPHRASE -v "${PWD}/backups:/backups" -v "${PWD}/backup:/scripts:ro" postgres:17 bash /scripts/restore-shop-files.sh /backups/tindaflow-<time>.files.tar.enc /backups/restored
   ```
   It lists the matching dump and the newest migration the shop had. The program you are using must have that file in `database/migrations/`.
4. **Put the settings and the certificate in place.** Copy `backups/restored/env` to `.env`, and the files in `backups/restored/certs/` into `certs/`. Then read `.env` and correct anything that belongs to the old computer: `BACKUP_DIR`, `TLS_CERT_DIR`, `TINDAFLOW_HOST_DIR`. (If `BACKUP_DIR` points elsewhere, put the dump there.)
5. **Start only the database.**
   ```bash
   docker compose build
   docker compose up -d --wait postgres
   ```
6. **Restore the dump into a new database.**
   ```bash
   docker compose run --rm --no-deps --entrypoint bash backup /usr/local/bin/restore.sh /backups/tindaflow-<time>.dump.enc tindaflow_restored
   ```
   Check the number of sales it prints against what you expect.
7. **In `.env`, set `DB_DATABASE=tindaflow_restored`.**
8. **Put the app's stored files back**, owned by the application and private again:
   ```bash
   docker compose run --rm --no-deps -v "${PWD}/backups/restored/storage/app:/restore:ro" --entrypoint sh app -c 'cp -R /restore/. storage/app/ && chown -R www-data:www-data storage/app && if [ -d storage/app/license ]; then chmod 700 storage/app/license && chmod 600 storage/app/license/*; fi'
   ```
9. **Start everything**, naming no service: `docker compose up -d`. Then `docker compose ps` must show every long-running container healthy.
10. **Check the shop**: sign in, compare the latest sales and totals, and make sure a till still opens. Then **delete `backups/restored/`**, which holds every secret in plain text.

**The tills** stay enrolled, with nothing to re-enter, as long as the server answers at the same address with the same certificate and `APP_KEY`. If the new computer has another name or IP address, set `APP_URL`, make a certificate for the new address, and enroll the tills again. A public host name needs `tls/letsencrypt.sh issue` again.

**On a different computer a license sees different hardware** and locks. After restoring, follow "Replacing the shop's computer". On the same computer, a backup older than the latest license makes the next renewal report a forked chain; "A license that has run out, or a clock lock" says what to do. Builds with no vendor key, which is every build today, are not affected.

**What is tested.** `scripts/drill-whole-shop-restore.sh` runs these steps in CI on Linux on every change: it builds a shop, sells, destroys it, restores it from the two files and compares every record. The steps have **not** been run on Windows. Practise them on a spare Windows computer before relying on them.

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
