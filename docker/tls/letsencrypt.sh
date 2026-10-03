#!/usr/bin/env bash
# A Let's Encrypt certificate for a server with a public host name (a VPS), and its renewal. Run it on the HOST, not in a
# container. A shop LAN with no public name does not use this: it keeps its own certificate (docker/README.md).
#
#   letsencrypt.sh issue <host-name> <e-mail>   once: gets the first certificate and puts it in docker/certs
#   letsencrypt.sh renew                        daily from cron: renews when under 30 days remain, then reloads nginx
#
# How: certbot runs in its own throwaway container (nothing is installed on the host). While the stack is up it proves
# control of the name through nginx (the "webroot" method: a file under docker/acme, served on port 80 at
# /.well-known/acme-challenge/); before the stack has ever started, `issue` listens on port 80 itself. certbot's state
# (account key, issued certificates) lives in docker/letsencrypt, which must be kept and never published. The current
# certificate is then copied to docker/certs as fullchain.pem and privkey.pem, where nginx reads it.
#
# `issue` accepts the Let's Encrypt Subscriber Agreement on your behalf (https://letsencrypt.org/repository/).
# `renew` exits non-zero when the renewal failed; and when the installed certificate has fewer than
# TLS_ALERT_DAYS (default 14) days left it sends the same alert the backups use (docker/README.md).
#
#   DOCKER          the docker command (default: docker)          CERTBOT_IMAGE   default certbot/certbot
#   TLS_CERT_DIR    where nginx reads the certificate (default: docker/certs, the same default as compose.yaml)
set -euo pipefail

DOCKER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKER="${DOCKER:-docker}"
CERTBOT_IMAGE="${CERTBOT_IMAGE:-certbot/certbot}"
STATE_DIR="$DOCKER_DIR/letsencrypt"
ACME_DIR="$DOCKER_DIR/acme"
CERT_DIR="${TLS_CERT_DIR:-$DOCKER_DIR/certs}"
CERT_NAME=tindaflow
ALERT_DAYS="${TLS_ALERT_DAYS:-14}"

log() { printf '%s [TLS] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
fail() { log "FAILED: $*" >&2; exit 1; }

compose() { (cd "$DOCKER_DIR" && "$DOCKER" compose "$@"); }
running() { [ -n "$(compose ps --status running -q "$1" 2> /dev/null)" ]; }

certbot() {
    "$DOCKER" run --rm ${PUBLISH[@]+"${PUBLISH[@]}"} -v "$STATE_DIR:/etc/letsencrypt" -v "$ACME_DIR:/var/www/acme" "$CERTBOT_IMAGE" "$@"
}

# Copies the current certificate into CERT_DIR if it differs from what is there. The key goes first and each file is
# moved into place whole, so nginx never reads a half-written file. Done in a container because certbot's files belong
# to root. Prints "changed" when it replaced the certificate.
install_certificate() {
    "$DOCKER" run --rm --entrypoint sh -v "$STATE_DIR:/etc/letsencrypt:ro" -v "$CERT_DIR:/certs" "$CERTBOT_IMAGE" -c '
        set -e
        live="/etc/letsencrypt/live/$1"
        [ -s "$live/fullchain.pem" ] && [ -s "$live/privkey.pem" ] || { echo "no certificate at $live" >&2; exit 1; }
        if cmp -s "$live/fullchain.pem" /certs/fullchain.pem && cmp -s "$live/privkey.pem" /certs/privkey.pem; then exit 0; fi
        cp -L "$live/privkey.pem" /certs/privkey.pem.new && chmod 600 /certs/privkey.pem.new && mv /certs/privkey.pem.new /certs/privkey.pem
        cp -L "$live/fullchain.pem" /certs/fullchain.pem.new && chmod 644 /certs/fullchain.pem.new && mv /certs/fullchain.pem.new /certs/fullchain.pem
        echo changed
    ' sh "$CERT_NAME"
}

reload_nginx() {
    if running web; then
        compose exec -T web nginx -s reload > /dev/null || fail "the certificate was installed but nginx did not reload; run: docker compose restart web"
        log "nginx reloaded with the new certificate"
    fi
}

install_and_reload() {
    local outcome
    outcome="$(install_certificate)" || fail "could not copy the certificate into $CERT_DIR"
    if [ "$outcome" = changed ]; then
        log "installed the certificate in $CERT_DIR"
        reload_nginx
    fi
}

# Through backup.sh's own alert(), so ALERT_COMMAND and Telegram behave exactly as they do for a failed backup.
alert() {
    log "ALERT: $*" >&2
    if running backup; then
        # shellcheck disable=SC2016
        compose exec -T backup bash -c 'source /usr/local/bin/backup.sh; alert "$1"' alert "$*" > /dev/null 2>&1 || log "the alert could not be sent" >&2
    fi
}

# True when the installed certificate is missing or has fewer than ALERT_DAYS days left.
expires_soon() {
    [ -s "$CERT_DIR/fullchain.pem" ] || return 0
    command -v openssl > /dev/null 2>&1 || { log "openssl is not installed here, so the expiry date was not checked" >&2; return 1; }
    ! openssl x509 -checkend "$((ALERT_DAYS * 86400))" -noout -in "$CERT_DIR/fullchain.pem" > /dev/null 2>&1
}

issue() {
    local domain="${1:-}" email="${2:-}"
    { [ -n "$domain" ] && [ -n "$email" ]; } || fail "usage: letsencrypt.sh issue <host-name> <e-mail>"
    mkdir -p "$STATE_DIR" "$ACME_DIR" "$CERT_DIR"

    local method=(--webroot -w /var/www/acme)
    PUBLISH=()
    if ! running web; then
        # Nothing is serving port 80 yet (nginx cannot start without a certificate), so certbot answers on it itself.
        method=(--standalone)
        PUBLISH=(-p 80:80)
    fi

    log "requesting a certificate for $domain (${method[0]#--})"
    certbot certonly "${method[@]}" --cert-name "$CERT_NAME" -d "$domain" -m "$email" --agree-tos --no-eff-email --non-interactive \
        || fail "Let's Encrypt did not issue a certificate. Check that $domain points at this server and that port 80 is open."
    install_and_reload
    log "done. Add the daily renewal to cron (docker/README.md, \"A public host name\")."
}

renew() {
    [ -e "$STATE_DIR/renewal/$CERT_NAME.conf" ] || fail "no certificate has been issued yet; run: letsencrypt.sh issue <host-name> <e-mail>"
    mkdir -p "$ACME_DIR"
    PUBLISH=()

    local renewed=0
    # The webroot method is named here so a certificate first issued by `issue` on port 80 renews through nginx too.
    certbot renew --webroot -w /var/www/acme --cert-name "$CERT_NAME" --non-interactive || renewed=$?
    if [ "$renewed" -eq 0 ]; then
        install_and_reload
    else
        log "FAILED: certbot could not renew (it is tried again on the next run)" >&2
    fi

    if expires_soon; then
        alert "The HTTPS certificate has fewer than $ALERT_DAYS days left and is not renewing. Tills will refuse to connect when it expires. On the server run: docker/tls/letsencrypt.sh renew"
        exit 1
    fi
    exit "$renewed"
}

PUBLISH=()
case "${1:-}" in
    issue) shift; issue "$@" ;;
    renew) renew ;;
    *) echo "usage: letsencrypt.sh issue <host-name> <e-mail> | renew" >&2; exit 2 ;;
esac
