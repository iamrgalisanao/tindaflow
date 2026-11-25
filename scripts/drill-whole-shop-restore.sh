#!/usr/bin/env bash
# Whole-shop restore drill (ADR-019 slice 3).
#
# Builds a throwaway shop from this checkout with the production compose stack, sells on it, backs it up, destroys it,
# and rebuilds it from nothing but the code, the two backup files and the passphrase, following docker/README.md,
# "Restoring a whole shop". Then it checks that the same shop came back, and that it can go on trading.
#
#   bash scripts/drill-whole-shop-restore.sh
#
# Needs Linux with Docker and the Compose plugin, bash, curl, jq and openssl (CI runs it on every change). It uses its
# own Compose project, tindaflow-drill, on ports 18080 and 18443, and refuses to start if anything of that project
# already exists. compose.yaml names the project `tindaflow`, so every command here names the drill's with -p: without
# it a second copy of this folder would use a real shop's volumes.
set -euo pipefail

PROJECT=tindaflow-drill
BASE=https://localhost:18443
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
SHOP=""
OWNED=""

# A value already in the environment would override the drill's own settings file in compose.
unset COMPOSE_PROJECT_NAME COMPOSE_FILE COMPOSE_ENV_FILES BACKUP_DIR TLS_CERT_DIR HTTP_PORT HTTPS_PORT TINDAFLOW_TAG TINDAFLOW_HOST_DIR \
    APP_TIMEZONE DB_DATABASE DB_OWNER_USERNAME DB_OWNER_PASSWORD DB_USERNAME DB_PASSWORD BACKUP_PASSPHRASE

say() { printf '\n== %s\n' "$*"; }
pass() { echo "ok      $*"; }
fail() { echo "DRILL FAILED: $*" >&2; exit 1; }
check_equal() { # description, expected, actual
    [ "$2" = "$3" ] || fail "$1
  expected: $2
  actual:   $3"
    pass "$1"
}

for tool in docker curl jq openssl sha256sum git tar; do
    command -v "$tool" > /dev/null || fail "$tool is not installed"
done

dc() { (cd "$SHOP/docker" && docker compose -p "$PROJECT" "$@"); }

remove_folder() { # containers wrote some of these files as root
    docker run --rm -v "$WORK:/work" postgres:17 rm -rf "/work/$1"
}

cleanup() {
    local status=$?
    if [ -n "$OWNED" ] && [ -n "$SHOP" ] && [ -d "$SHOP/docker" ]; then
        if [ "$status" -ne 0 ]; then
            say "the containers as they were when it failed"
            dc ps -a || true
            dc logs --no-color --tail 40 || true
        fi
        dc down -v --remove-orphans > /dev/null 2>&1 || true
    fi
    if [ -n "$OWNED" ]; then # whatever is left of the drill's own project, even if a folder is already gone
        docker ps -aq --filter "label=com.docker.compose.project=$PROJECT" | xargs -r docker rm -f > /dev/null 2>&1 || true
        docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" | xargs -r docker volume rm > /dev/null 2>&1 || true
    fi
    docker run --rm -v "$WORK:/work" postgres:17 sh -c 'rm -rf /work/* /work/.[!.]*' > /dev/null 2>&1 || true
    rm -rf "$WORK" 2> /dev/null || true
}
trap cleanup EXIT

# --- never touch anything this run did not create --------------------------------------------------------------------
existing="$(docker ps -aq --filter "label=com.docker.compose.project=$PROJECT")$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT")$(docker volume ls -q | grep "^${PROJECT}_" || true)"
[ -z "$existing" ] || fail "containers or volumes of the project $PROJECT already exist; this drill only works on a project it creates itself"
OWNED=yes

new_checkout() { # the code, as a new PC would have it: no settings, certificates or backups
    mkdir -p "$1"
    (cd "$REPO" && git ls-files -z --cached --others --exclude-standard | tar --null -T - -cf -) | tar -xf - -C "$1"
    mkdir -p "$1/docker/backups"
}

set_env() { # file, key, value
    { grep -v "^$2=" "$1" || true; printf '%s=%s\n' "$2" "$3"; } > "$1.new"
    mv "$1.new" "$1"
}

env_value() { sed -n "s/^$2=//p" "$1" | tail -n 1; }

wait_healthy() {
    local deadline=$((SECONDS + 420)) service id state pending
    while true; do
        pending=""
        for service in "$@"; do
            id="$(dc ps -q "$service")"
            state=missing
            if [ -n "$id" ]; then state="$(docker inspect -f '{{.State.Health.Status}}' "$id")"; fi
            [ "$state" = healthy ] || pending="$pending $service($state)"
        done
        [ -z "$pending" ] && return 0
        [ "$SECONDS" -lt "$deadline" ] || fail "not healthy in time:$pending"
        sleep 5
    done
}

# --- the till's browser: a cookie jar that outlives the server -------------------------------------------------------
TILL="$WORK/till.cookies"

xsrf() {
    local token
    token="$(awk -F'\t' '$6 == "XSRF-TOKEN" { value = $7 } END { print value }' "$1" 2> /dev/null || true)"
    printf '%b' "${token//%/\\x}"
}

api() { # jar, method, path, expected status, [json body], [idempotency key]; prints the response body
    local jar="$1" method="$2" path="$3" want="$4" body="${5:-}" key="${6:-}" answer status
    local arguments=(-sk -b "$jar" -c "$jar" -X "$method" -H 'Accept: application/json' -H "X-XSRF-TOKEN: $(xsrf "$jar")")
    if [ -n "$body" ]; then arguments+=(-H 'Content-Type: application/json' --data "$body"); fi
    if [ -n "$key" ]; then arguments+=(-H "Idempotency-Key: $key"); fi
    answer="$(curl "${arguments[@]}" -w $'\n%{http_code}' "$BASE$path")" || fail "$method $path did not connect"
    status="${answer##*$'\n'}"
    answer="${answer%$'\n'*}"
    [ "$status" = "$want" ] || fail "$method $path answered $status, not $want: ${answer:0:600}"
    printf '%s' "$answer"
}

sign_in() { # jar
    api "$1" GET / 200 > /dev/null
    api "$1" POST /api/v1/auth/login 200 "$(jq -nc --arg email "$ADMIN_EMAIL" --arg password "$ADMIN_PASSWORD" '{email: $email, password: $password}')" > /dev/null
}

sell() { # prints the slip number of one cash sale of two items
    api "$TILL" POST /api/v1/sales 201 \
        "$(jq -nc --arg product "$PRODUCT" '{items: [{product_id: $product, quantity: "2"}], payments: [{method: "CASH", amount: "50.00"}]}')" \
        "$(cat /proc/sys/kernel/random/uuid)" | jq -r .document_reference
}

# --- what must be the same afterwards --------------------------------------------------------------------------------
sql() { # database, query
    dc exec -T postgres psql -U "$OWNER" -d "$1" -v ON_ERROR_STOP=1 -Atc "$2"
}

records() { # one line per table: how many rows, and a digest of every value in them
    local table
    for table in stores users products terminals shifts fiscal_days sales sale_items payments sale_slips slip_counters \
        stock_movements stock_balances audit_events electronic_journal_entries idempotency_records; do
        echo "$table $(sql "$1" "select count(*) || ' ' || coalesce(md5(string_agg(t::text, E'\n' order by t::text)), '-') from $table t")"
    done
}

certificate_fingerprint() {
    openssl s_client -connect localhost:18443 -servername localhost < /dev/null 2> /dev/null | openssl x509 -noout -fingerprint -sha256
}

stored_files() { dc exec -T app sh -c 'cd storage/app && sha256sum license.key license/installation.json'; }

# =====================================================================================================================
say "building the first shop (the one that will be lost)"
SHOP="$WORK/shop-a"
new_checkout "$SHOP"
cp "$SHOP/docker/.env.example" "$SHOP/docker/.env"
ENV="$SHOP/docker/.env"
set_env "$ENV" APP_KEY "base64:$(openssl rand -base64 32)"
set_env "$ENV" APP_URL "$BASE"
set_env "$ENV" DB_OWNER_PASSWORD "$(openssl rand -hex 24)"
set_env "$ENV" DB_PASSWORD "$(openssl rand -hex 24)"
set_env "$ENV" BACKUP_PASSPHRASE "$(openssl rand -hex 24)"
set_env "$ENV" BACKUP_INTERVAL_MINUTES 600
set_env "$ENV" HTTP_PORT 18080
set_env "$ENV" HTTPS_PORT 18443
set_env "$ENV" TINDAFLOW_TAG drill
set_env "$ENV" TINDAFLOW_HOST_DIR ./host
PASSPHRASE="$(env_value "$ENV" BACKUP_PASSPHRASE)"
OWNER="$(env_value "$ENV" DB_OWNER_USERNAME)"
DATABASE="$(env_value "$ENV" DB_DATABASE)"
APP_ROLE_PASSWORD="$(env_value "$ENV" DB_PASSWORD)"
ADMIN_EMAIL="$(env_value "$ENV" TINDAFLOW_INITIAL_ADMIN_EMAIL)"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj /CN=localhost -addext subjectAltName=DNS:localhost \
    -keyout "$SHOP/docker/certs/privkey.pem" -out "$SHOP/docker/certs/fullchain.pem" 2> /dev/null
mkdir -p "$SHOP/docker/host"
hash_of() { printf '%s' "$1" | sha256sum | cut -c1-64; }
printf '{"v":1,"generated_at":"%s","fp":{"smbios_uuid":"%s","board_serial":"%s","bios_serial":"%s"}}\n' \
    "$(date +%Y-%m-%dT%H:%M:%S%:z)" "$(hash_of uuid)" "$(hash_of board)" "$(hash_of bios)" > "$SHOP/docker/host/hardware.json"

dc build --quiet
dc up -d
wait_healthy postgres app web scheduler backup
pass "the first shop is up"

say "setting up the shop and selling on it"
ADMIN_PASSWORD="$(dc run --rm -T app php artisan db:seed --force --no-ansi | sed -n 's/^.*Generated password (shown once, not stored anywhere): //p' | tr -d '\r')"
[ -n "$ADMIN_PASSWORD" ] || fail "the seeder printed no administrator password"
dc exec -T app php artisan tindaflow:fiscal-setup unlock --minutes=60 > /dev/null
sign_in "$TILL"
api "$TILL" PUT /api/v1/sale-document-settings/mode 200 '{"sale_document_mode":"SLIP"}' > /dev/null
api "$TILL" PUT /api/v1/sale-document-settings/slip-footer 200 '{"slip_footer":"RESTORE DRILL"}' > /dev/null
api "$TILL" POST /api/v1/inventory-locations 201 '{"name":"Main stockroom","is_default":true}' > /dev/null
PRODUCT="$(api "$TILL" POST /api/v1/products 201 '{"sku":"DRILL-1","name":"Drill item","unit_of_measure":"piece","selling_price":"25.00","tax_class":"NON_VAT"}' | jq -r .id)"
TERMINAL="$(api "$TILL" POST /api/v1/terminals 201 '{"terminal_code":"TILL-1"}' | jq -r .id)"
ENROLLMENT="$(api "$TILL" POST /api/v1/terminal-enrollment-tokens 201 "{\"terminal_id\":\"$TERMINAL\"}" | jq -r .token)"
api "$TILL" POST /api/v1/terminal/enroll 200 "{\"token\":\"$ENROLLMENT\"}" > /dev/null
api "$TILL" POST /api/v1/shifts/open 201 '{"opening_cash":"500.00"}' "$(cat /proc/sys/kernel/random/uuid)" > /dev/null
check_equal "the first sale is slip 1" TILL-1-000001 "$(sell)"
check_equal "the second sale is slip 2" TILL-1-000002 "$(sell)"

say "the files the app keeps outside the database"
# As documented, artisan runs as root here. The web workers run as www-data and must be able to read what it wrote
# (ADR-019 defect 7); this is the first check of that in a real container, before any restart repairs ownership.
request="$(dc exec -T app php artisan tindaflow:activate --request --adopt --out=/tmp/request.json --no-ansi 2>&1)" ||
    fail "the activation request was refused: $request" # the shop exists by now, so it is a request to adopt it
check_equal "the identity file made by root belongs to the web workers, for them alone" "www-data 600" "$(dc exec -T app stat -c '%U %a' storage/app/license/installation.json)"
check_equal "and so does its folder" "www-data 700" "$(dc exec -T app stat -c '%U %a' storage/app/license)"
dc exec -T app runuser -u www-data -- cat storage/app/license/installation.json > /dev/null || fail "www-data cannot read the identity file"
dc exec -T -u www-data app sh -c 'printf drill-till-license > storage/app/license.key'

say "backing up, and noting exactly what the shop holds"
BEFORE_RECORDS="$(records "$DATABASE")"
BEFORE_CERTIFICATE="$(certificate_fingerprint)"
BEFORE_FILES="$(stored_files)"
BEFORE_SETTINGS="$(sha256sum < "$ENV")"
echo "$BEFORE_RECORDS"
sleep 2 # the first backup, taken when the stack started, must not share this one's time stamp
dc exec -T backup bash /usr/local/bin/backup.sh once
DUMP="$(cd "$SHOP/docker/backups" && ls tindaflow-*.dump.enc | sort | tail -n 1)"
FILES="${DUMP%.dump.enc}.files.tar.enc"
[ -f "$SHOP/docker/backups/$FILES" ] || fail "there is no shop-files archive beside $DUMP"
dc exec -T backup bash /usr/local/bin/backup.sh health > /dev/null || fail "the backup container is not healthy"
mkdir -p "$WORK/offsite"
cp "$SHOP/docker/backups/$DUMP" "$SHOP/docker/backups/$FILES" "$WORK/offsite/"
pass "two files go off the machine: $DUMP and $FILES"

say "losing the shop: containers, volumes and folder"
dc down -v --remove-orphans
remove_folder shop-a
[ ! -e "$SHOP" ] || fail "the first shop's folder is still there"
[ -z "$(docker volume ls -q | grep "^${PROJECT}_" || true)" ] || fail "a volume survived"
pass "nothing is left but the two files, the passphrase and the till's browser"

# =====================================================================================================================
say "restoring on a new checkout, by the documented steps"
SHOP="$WORK/shop-b"
new_checkout "$SHOP"
cp "$WORK/offsite/$DUMP" "$WORK/offsite/$FILES" "$SHOP/docker/backups/"
(
    cd "$SHOP/docker"
    export BACKUP_PASSPHRASE="$PASSPHRASE"
    docker run --rm --user "$(id -u):$(id -g)" -e BACKUP_PASSPHRASE -v "${PWD}/backups:/backups" -v "${PWD}/backup:/scripts:ro" \
        postgres:17 bash /scripts/restore-shop-files.sh "/backups/$FILES" /backups/restored
    cp backups/restored/env .env
    cp backups/restored/certs/* certs/
)
ENV="$SHOP/docker/.env"
check_equal "the settings file came back unchanged" "$BEFORE_SETTINGS" "$(sha256sum < "$ENV")"
mkdir -p "$SHOP/docker/host"

dc build --quiet
dc up -d --wait postgres
dc run --rm -T --no-deps --entrypoint bash backup /usr/local/bin/restore.sh "/backups/$DUMP" "${DATABASE}_restored"
DATABASE="${DATABASE}_restored"
set_env "$ENV" DB_DATABASE "$DATABASE"
dc run --rm -T --no-deps -v "$SHOP/docker/backups/restored/storage/app:/restore:ro" --entrypoint sh app -c     'cp -R /restore/. storage/app/ && chown -R www-data:www-data storage/app && if [ -d storage/app/license ]; then chmod 700 storage/app/license && chmod 600 storage/app/license/*; fi'
dc up -d
wait_healthy postgres app web scheduler backup
pass "the restored shop is up, every container healthy"

# =====================================================================================================================
say "is it the same shop?"
# Compared before anything signs in or sells, so that nothing has been added since the backup.
check_equal "every record is the same" "$BEFORE_RECORDS" "$(records "$DATABASE")"
check_equal "the HTTPS certificate is the same" "$BEFORE_CERTIFICATE" "$(certificate_fingerprint)"
check_equal "the stored license files are the same" "$BEFORE_FILES" "$(stored_files)"
check_equal "the identity file is private again" "www-data 600" "$(dc exec -T app stat -c '%U %a' storage/app/license/installation.json)"
dc exec -T app runuser -u www-data -- cat storage/app/license/installation.json > /dev/null || fail "www-data cannot read the restored identity file"
pass "the web workers can read the restored identity file"

say "can it go on trading?"
check_equal "the till is still enrolled and signed in, with nothing re-entered (APP_KEY)" TILL-1 "$(api "$TILL" GET /api/v1/terminal/current 200 | jq -r .terminal_code)"
sign_in "$WORK/new-browser.cookies"
pass "the administrator signs in with the same password"
check_equal "the next sale continues the slip numbers" TILL-1-000003 "$(sell)"

say "is it as protected as before?"
if refusal="$(dc exec -T -e "PGPASSWORD=$APP_ROLE_PASSWORD" postgres psql -h 127.0.0.1 -U tindaflow_app -d "$DATABASE" -v ON_ERROR_STOP=1 -c 'update sale_items set quantity = quantity' 2>&1)"; then
    fail "the application's role could rewrite sale lines on the restored database"
fi
case "$refusal" in *"permission denied"*) pass "the application's role still cannot rewrite what was sold" ;; *) fail "unexpected error: $refusal" ;; esac
check_equal "it can read them" "3" "$(dc exec -T -e "PGPASSWORD=$APP_ROLE_PASSWORD" postgres psql -h 127.0.0.1 -U tindaflow_app -d "$DATABASE" -Atc 'select count(*) from sales')"

say "does everything now use the restored database?"
for service in app scheduler backup; do
    check_equal "$service is on the restored database" "$DATABASE" "$(dc exec -T "$service" printenv DB_DATABASE | tr -d '\r')"
done
sleep 2
dc exec -T backup bash /usr/local/bin/backup.sh once
NEWEST="$(cd "$SHOP/docker/backups" && ls tindaflow-*.dump.enc | sort | tail -n 1)"
dc exec -T backup bash /usr/local/bin/restore.sh "/backups/$NEWEST" tindaflow_check > /dev/null
check_equal "a backup taken after the restore holds the new sale" "3" "$(sql tindaflow_check 'select count(*) from sales')"
[ -f "$SHOP/docker/backups/${NEWEST%.dump.enc}.files.tar.enc" ] || fail "the new backup has no shop-files archive"
pass "and has its shop-files archive"

say "the drill passed"
