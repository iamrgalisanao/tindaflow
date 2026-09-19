#!/usr/bin/env bash
# TindaFlow database backup (ADR-008, docs/06-backend/stage-23-production-readiness.md section 4).
#
#   backup.sh loop    take a backup every BACKUP_INTERVAL_MINUTES, forever (what the compose service runs)
#   backup.sh once    take one backup now
#   backup.sh prune   apply the retention policy to BACKUP_DIR
#
# Each backup is a pg_dump custom-format archive, checked readable (pg_restore --list) before it is kept, optionally
# encrypted with AES-256, then pruned and handed to BACKUP_UPLOAD_COMMAND. Retention (by the timestamp in the name):
#   every backup younger than BACKUP_KEEP_HOURLY_HOURS,
#   then the first backup of each day for BACKUP_KEEP_DAILY_DAYS,
#   then the first backup of each month for BACKUP_KEEP_MONTHLY_MONTHS.
# The newest backup is never deleted. Pruning removes backup COPIES only; it never touches the live database.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_INTERVAL_MINUTES="${BACKUP_INTERVAL_MINUTES:-60}"
BACKUP_KEEP_HOURLY_HOURS="${BACKUP_KEEP_HOURLY_HOURS:-24}"
BACKUP_KEEP_DAILY_DAYS="${BACKUP_KEEP_DAILY_DAYS:-30}"
BACKUP_KEEP_MONTHLY_MONTHS="${BACKUP_KEEP_MONTHLY_MONTHS:-12}"
DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_DATABASE="${DB_DATABASE:-tindaflow}"
DB_USERNAME="${DB_USERNAME:-tindaflow}"
export PGPASSWORD="${DB_PASSWORD:-${PGPASSWORD:-}}"

export TZ=UTC
log() { printf '%(%Y-%m-%dT%H:%M:%SZ)T backup: %s\n' -1 "$*"; }

# Seconds since the epoch "now"; BACKUP_NOW (a UTC timestamp like 20260920-131500) exists so pruning can be tested.
now_epoch() {
    if [ -n "${BACKUP_NOW:-}" ]; then
        name_epoch "tindaflow-${BACKUP_NOW}.dump"
    else
        date -u +%s
    fi
}

# tindaflow-YYYYmmdd-HHMMSS.dump[.enc] -> epoch seconds (UTC)
name_epoch() {
    local stamp
    stamp="${1#tindaflow-}"
    stamp="${stamp%%.*}"
    date -u -d "${stamp:0:4}-${stamp:4:2}-${stamp:6:2} ${stamp:9:2}:${stamp:11:2}:${stamp:13:2}" +%s
}

take_backup() {
    mkdir -p "$BACKUP_DIR"
    local stamp file partial
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    file="$BACKUP_DIR/tindaflow-${stamp}.dump"
    partial="${file}.partial"

    pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -Fc -f "$partial" "$DB_DATABASE"
    # A backup that cannot be read back is not a backup.
    pg_restore --list "$partial" > /dev/null
    mv "$partial" "$file"

    if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
        openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE -in "$file" -out "${file}.enc"
        rm -f "$file"
        file="${file}.enc"
    fi
    log "wrote $(basename "$file") ($(wc -c < "$file") bytes)"

    prune
    if [ -n "${BACKUP_UPLOAD_COMMAND:-}" ]; then
        # The command receives the file path as its first argument (rclone, rsync, scp, a script...).
        if bash -c "$BACKUP_UPLOAD_COMMAND" upload "$file"; then
            log "uploaded $(basename "$file")"
        else
            log "UPLOAD FAILED for $(basename "$file"); the local copy is kept" >&2
            return 1
        fi
    fi
}

prune() {
    # Timestamps in the names are fixed-width (YYYYmmdd-HHMMSS), so they compare correctly as plain strings: the
    # three cutoffs are worked out once and no per-file process is needed.
    local now hourly_cutoff daily_cutoff monthly_cutoff
    now="$(now_epoch)"
    hourly_cutoff="$(date -u -d "@$((now - BACKUP_KEEP_HOURLY_HOURS * 3600))" +%Y%m%d-%H%M%S)"
    daily_cutoff="$(date -u -d "@$((now - BACKUP_KEEP_DAILY_DAYS * 86400))" +%Y%m%d-%H%M%S)"
    monthly_cutoff="$(date -u -d "$(date -u -d "@${now}" +%Y-%m-01) - ${BACKUP_KEEP_MONTHLY_MONTHS} months" +%Y%m%d-%H%M%S)"

    shopt -s nullglob
    local files=("$BACKUP_DIR"/tindaflow-*.dump "$BACKUP_DIR"/tindaflow-*.dump.enc)
    [ "${#files[@]}" -gt 0 ] || return 0

    # Oldest first, so the first file seen for a day or a month is that day's or month's earliest.
    local sorted newest
    sorted="$(printf '%s\n' "${files[@]}" | sort)"
    newest="$(printf '%s\n' "$sorted" | tail -n 1)"

    declare -A seen_day=() seen_month=()
    local file base stamp day month keep
    while IFS= read -r file; do
        base="${file##*/}"
        stamp="${base#tindaflow-}"
        stamp="${stamp%%.*}"
        day="${stamp:0:8}"
        month="${stamp:0:6}"
        keep=no

        if [ "$file" = "$newest" ]; then keep=yes; fi
        if [[ ! "$stamp" < "$hourly_cutoff" ]]; then keep=yes; fi
        if [ -z "${seen_day[$day]:-}" ]; then
            seen_day[$day]=1
            if [[ ! "$stamp" < "$daily_cutoff" ]]; then keep=yes; fi
        fi
        if [ -z "${seen_month[$month]:-}" ]; then
            seen_month[$month]=1
            if [[ ! "$stamp" < "$monthly_cutoff" ]]; then keep=yes; fi
        fi

        if [ "$keep" = no ]; then
            rm -f -- "$file"
            log "pruned $base"
        fi
    done <<< "$sorted"
}

case "${1:-loop}" in
    once) take_backup ;;
    prune) prune ;;
    loop)
        log "every ${BACKUP_INTERVAL_MINUTES} min to ${BACKUP_DIR} (encrypted: $([ -n "${BACKUP_PASSPHRASE:-}" ] && echo yes || echo NO), off-machine copy: $([ -n "${BACKUP_UPLOAD_COMMAND:-}" ] && echo yes || echo NO))"
        while true; do
            take_backup || log "backup failed; will retry in ${BACKUP_INTERVAL_MINUTES} min" >&2
            sleep $((BACKUP_INTERVAL_MINUTES * 60))
        done
        ;;
    *) echo "usage: backup.sh [loop|once|prune]" >&2; exit 2 ;;
esac
