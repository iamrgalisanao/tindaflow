#!/usr/bin/env bash
#
# Tests docker/tls/letsencrypt.sh without Docker or Let's Encrypt: `docker` is a stub that records what it was asked to do,
# pretends to be certbot, and runs the certificate-copy step against local folders. Plain bash, no test framework; needs
# openssl (to make real short-lived certificates for the expiry check).
#
#   bash scripts/test-tls.sh
#
# Exits non-zero if any check fails.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASS=0
FAIL=0

check() { # description, then a command; passes if the command succeeds
    local description="$1"
    shift
    if "$@" > /dev/null 2>&1; then PASS=$((PASS + 1)); printf 'ok      %s\n' "$description"; else FAIL=$((FAIL + 1)); printf 'FAILED  %s\n' "$description"; fi
}
equals() { [ "$1" = "$2" ]; }
missing() { [ ! -e "$1" ]; }
logged() { grep -q -- "$1" "$CALLS"; }
not_logged() { ! grep -q -- "$1" "$CALLS"; }
same_file() { cmp -s "$1" "$2"; }

make_cert() { # path prefix, days valid
    openssl req -x509 -newkey rsa:2048 -nodes -keyout "$1.key" -out "$1.crt" -days "$2" -subj "/CN=beta.example.test" > /dev/null 2>&1
}

# A fresh sandbox per case: a copy of the script in its own docker/ folder, so nothing touches the real docker/certs.
new_case() {
    WORK="$(mktemp -d)"
    mkdir -p "$WORK/docker/tls" "$WORK/bin"
    cp "$HERE/../docker/tls/letsencrypt.sh" "$WORK/docker/tls/letsencrypt.sh"
    SCRIPT="$WORK/docker/tls/letsencrypt.sh"
    STATE="$WORK/docker/letsencrypt"
    CERTS="$WORK/docker/certs"
    export CALLS="$WORK/calls.log" DOCKER="$WORK/bin/docker" ISSUED="$WORK/issued"
    export WEB_RUNNING='' BACKUP_RUNNING='' CERTBOT_EXIT=0 CERTBOT_ISSUES=yes
    unset TLS_CERT_DIR TLS_ALERT_DAYS
    : > "$CALLS"
    make_cert "$ISSUED" 90
    cat > "$DOCKER" <<'STUB'
#!/usr/bin/env bash
# Records every call; plays certbot, the copy container, and `docker compose`.
printf '%s\n' "$*" >> "$CALLS"
if [ "$1" = compose ]; then
    case "$*" in
        *"ps --status running -q web"*) [ -n "$WEB_RUNNING" ] && echo web-id ;;
        *"ps --status running -q backup"*) [ -n "$BACKUP_RUNNING" ] && echo backup-id ;;
    esac
    exit 0
fi
state=''; certs=''; shell=''
args=("$@")
for ((i = 0; i < ${#args[@]}; i++)); do
    case "${args[i]}" in
        -v) mount="${args[i + 1]}"
            case "$mount" in
                *:/etc/letsencrypt*) state="${mount%%:/etc/letsencrypt*}" ;;
                *:/certs) certs="${mount%%:/certs}" ;;
            esac ;;
        --entrypoint) shell=yes ;;
        -c) body="${args[i + 1]}"; name="${args[i + 3]}" ;;
    esac
done
if [ -n "$shell" ]; then
    body="${body//\/etc\/letsencrypt/$state}"
    body="${body//\/certs/$certs}"
    exec sh -c "$body" sh "$name"
fi
# certbot certonly / renew
[ "$CERTBOT_EXIT" -eq 0 ] || exit "$CERTBOT_EXIT"
if [ -n "$CERTBOT_ISSUES" ]; then
    mkdir -p "$state/live/tindaflow" "$state/renewal"
    cp "$ISSUED.crt" "$state/live/tindaflow/fullchain.pem"
    cp "$ISSUED.key" "$state/live/tindaflow/privkey.pem"
    : > "$state/renewal/tindaflow.conf"
fi
exit 0
STUB
    chmod +x "$DOCKER"
}
run() { bash "$SCRIPT" "$@" > "$WORK/out.log" 2>&1; RESULT=$?; }

echo "== issue, before the stack has ever started"
new_case
run issue beta.example.test owner@example.test
check "it succeeds" equals "$RESULT" 0
check "certbot listens on port 80 itself" logged "run --rm -p 80:80 .* certonly --standalone"
check "for the requested name and e-mail" logged "-d beta.example.test -m owner@example.test"
check "the certificate is installed" same_file "$ISSUED.crt" "$CERTS/fullchain.pem"
check "and its key" same_file "$ISSUED.key" "$CERTS/privkey.pem"
check "the key is private" equals "$(ls -l "$CERTS/privkey.pem" | cut -c1-10)" "-rw-------"
check "no half-written file is left" missing "$CERTS/fullchain.pem.new"
check "nginx is not reloaded when it is not running" not_logged "nginx -s reload"
rm -rf "$WORK"

echo "== issue, with the stack running"
new_case
WEB_RUNNING=yes run issue beta.example.test owner@example.test
check "it succeeds" equals "$RESULT" 0
check "it goes through nginx, not port 80" logged "certonly --webroot -w /var/www/acme"
check "no port is published" not_logged "-p 80:80"
check "nginx is reloaded" logged "compose exec -T web nginx -s reload"
rm -rf "$WORK"

echo "== issue refused by Let's Encrypt"
new_case
CERTBOT_EXIT=1 run issue beta.example.test owner@example.test
check "it fails" equals "$RESULT" 1
check "it says what to check" grep -q "points at this server" "$WORK/out.log"
check "nothing is installed" missing "$CERTS/fullchain.pem"
rm -rf "$WORK"

echo "== issue without its arguments"
new_case
run issue beta.example.test
check "it fails" equals "$RESULT" 1
check "certbot is never called" not_logged "certonly"
rm -rf "$WORK"

echo "== renew before any certificate exists"
new_case
run renew
check "it fails" equals "$RESULT" 1
check "it says to issue first" grep -q "letsencrypt.sh issue" "$WORK/out.log"
check "certbot is never called" not_logged "renew"
rm -rf "$WORK"

echo "== renew when nothing is due"
new_case
WEB_RUNNING=yes run issue beta.example.test owner@example.test
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes CERTBOT_ISSUES='' run renew
check "it succeeds" equals "$RESULT" 0
check "it renews through nginx" logged "renew --webroot -w /var/www/acme --cert-name tindaflow"
check "an unchanged certificate does not reload nginx" not_logged "nginx -s reload"
check "no alert" not_logged "alert"
rm -rf "$WORK"

echo "== renew that gets a new certificate"
new_case
WEB_RUNNING=yes run issue beta.example.test owner@example.test
cp "$CERTS/fullchain.pem" "$WORK/old.crt"
make_cert "$ISSUED" 90
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes run renew
check "it succeeds" equals "$RESULT" 0
check "the new certificate is installed" same_file "$ISSUED.crt" "$CERTS/fullchain.pem"
check "it replaced the old one" test "$(cmp -s "$WORK/old.crt" "$CERTS/fullchain.pem"; echo $?)" = 1
check "the new key is installed" same_file "$ISSUED.key" "$CERTS/privkey.pem"
check "nginx is reloaded" logged "compose exec -T web nginx -s reload"
check "no alert" not_logged "alert"
rm -rf "$WORK"

echo "== a failed renewal with plenty of time left"
new_case
WEB_RUNNING=yes run issue beta.example.test owner@example.test
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes CERTBOT_EXIT=1 run renew
check "it exits non-zero, so cron reports it" equals "$RESULT" 1
check "but nobody is alerted yet" not_logged "alert"
check "the installed certificate is kept" same_file "$ISSUED.crt" "$CERTS/fullchain.pem"
rm -rf "$WORK"

echo "== a failed renewal with the certificate about to expire"
new_case
make_cert "$ISSUED" 5
WEB_RUNNING=yes run issue beta.example.test owner@example.test
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes CERTBOT_EXIT=1 run renew
check "it exits non-zero" equals "$RESULT" 1
check "the alert goes through the backup container's alert()" logged "compose exec -T backup bash -c .*alert.* fewer than 14 days"
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes CERTBOT_EXIT=1 TLS_ALERT_DAYS=3 run renew
check "TLS_ALERT_DAYS moves the threshold" not_logged "fewer than"
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING='' CERTBOT_EXIT=1 run renew
check "with the backup container down it still fails loudly" equals "$RESULT" 1
check "and says so on its own output" grep -q "ALERT: The HTTPS certificate" "$WORK/out.log"
rm -rf "$WORK"

echo "== certbot says renewed but the certificate on disk is still about to expire"
new_case
make_cert "$ISSUED" 5
WEB_RUNNING=yes run issue beta.example.test owner@example.test
: > "$CALLS"
WEB_RUNNING=yes BACKUP_RUNNING=yes CERTBOT_ISSUES='' run renew
check "it alerts anyway" logged "fewer than 14 days"
check "and exits non-zero" equals "$RESULT" 1
rm -rf "$WORK"

echo "== TLS_CERT_DIR"
new_case
export TLS_CERT_DIR="$WORK/elsewhere"
run issue beta.example.test owner@example.test
check "the certificate goes where compose.yaml reads it" same_file "$ISSUED.crt" "$WORK/elsewhere/fullchain.pem"
unset TLS_CERT_DIR
rm -rf "$WORK"

echo "== anything else"
new_case
run
check "no command is a usage error" equals "$RESULT" 2
rm -rf "$WORK"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
