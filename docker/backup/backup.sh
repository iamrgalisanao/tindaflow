#!/usr/bin/env bash
# TindaFlow database backup (ADR-008, docs/06-backend/stage-23-production-readiness.md section 4).
#
#   backup.sh loop    take a backup every BACKUP_INTERVAL_MINUTES, forever (what the compose service runs)
#   backup.sh once    take one backup now (exits non-zero if it failed)
#   backup.sh prune   apply the retention policy to BACKUP_DIR
#   backup.sh health  exit 0 if backups are current and disks have room, else 1 with the reason (the container healthcheck)
#
# Failure is never silent (deployment.md section 7): every step of a backup is checked explicitly (`set -e` does not
# apply inside a function called as `take_backup || ...`, so it cannot be relied on there), a failed or unreadable dump
# is deleted and never kept, and the outcome is written to $BACKUP_DIR/.status so `health` can turn the container
# unhealthy when no backup has succeeded for 2.5 intervals, when the off-machine copy failed, or when a disk is at the
# critical level. Log lines carry [BACKUP] or [DISK]. ALERT_COMMAND (optional, like BACKUP_UPLOAD_COMMAND) is run with
# the message as $1 when a backup starts failing, when a disk crosses its warning or critical level, and when either
# recovers: once per change, not on every tick.
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
DISK_WARN_PERCENT="${DISK_WARN_PERCENT:-80}"
DISK_CRIT_PERCENT="${DISK_CRIT_PERCENT:-90}"
# The database's own volume, mounted read-only into this container so its disk can be watched (docker/compose.yaml).
DATA_DIR="${DATA_DIR:-/pgdata}"
STATUS_FILE="$BACKUP_DIR/.status"

export TZ=UTC
log() { printf '%(%Y-%m-%dT%H:%M:%SZ)T [BACKUP] %s\n' -1 "$*"; }

epoch_now() { date -u +%s; }

# --- status file: key=value lines, rewritten atomically, so `health` never reads a half-written file ---------------------
status_get() {
    [ -f "$STATUS_FILE" ] || return 0
    sed -n "s/^$1=//p" "$STATUS_FILE" | tail -n 1
}

status_set() {
    local key="$1" value="${2//$'\n'/ }" tmp
    mkdir -p "$BACKUP_DIR"
    tmp="$STATUS_FILE.tmp.$$"
    { if [ -f "$STATUS_FILE" ]; then grep -v "^${key}=" "$STATUS_FILE" || true; fi; printf '%s=%s\n' "$key" "$value"; } > "$tmp"
    mv "$tmp" "$STATUS_FILE"
}

# Runs ALERT_COMMAND (if any) with the message as $1. An alert that cannot be delivered is logged, never fatal.
alert() {
    log "ALERT: $*" >&2
    if [ -n "${ALERT_COMMAND:-}" ]; then
        bash -c "$ALERT_COMMAND" alert "$*" || log "the alert command failed" >&2
    fi
}

# A failed backup: recorded, logged, and alerted once (not on every retry). Always returns 1 so callers can `|| return`.
backup_failed() {
    status_set last_error "$*"
    log "FAILED: $*" >&2
    if [ "$(status_get backup_state)" != failing ]; then
        status_set backup_state failing
        alert "[BACKUP] backup failed: $*"
    fi
    return 1
}

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
    mkdir -p "$BACKUP_DIR" || { backup_failed "cannot create $BACKUP_DIR"; return 1; }
    status_set last_attempt "$(epoch_now)"
    local stamp file partial
    stamp="$(date -u +%Y%m%d-%H%M%S)"
    file="$BACKUP_DIR/tindaflow-${stamp}.dump"
    partial="${file}.partial"

    # Every step is checked by hand: this function usually runs as `take_backup || ...`, where `set -e` is off.
    if ! pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -Fc -f "$partial" "$DB_DATABASE"; then
        rm -f "$partial"
        backup_failed "pg_dump did not complete (is the database reachable, and is there disk space?)"
        return 1
    fi
    # A backup that cannot be read back is not a backup: it is deleted, never kept, and never allowed to age out a good one.
    if ! pg_restore --list "$partial" > /dev/null 2>&1; then
        rm -f "$partial"
        backup_failed "the dump could not be read back, so it was discarded"
        return 1
    fi
    if ! mv "$partial" "$file"; then
        rm -f "$partial" "$file"
        backup_failed "could not move the finished dump into place"
        return 1
    fi

    if [ -n "${BACKUP_PASSPHRASE:-}" ]; then
        if ! openssl enc -aes-256-cbc -pbkdf2 -salt -pass env:BACKUP_PASSPHRASE -in "$file" -out "${file}.enc"; then
            # Never leave the unencrypted copy of a backup that was meant to be encrypted.
            rm -f "$file" "${file}.enc"
            backup_failed "encryption failed, so the dump was discarded"
            return 1
        fi
        rm -f "$file"
        file="${file}.enc"
    fi
    log "wrote $(basename "$file") ($(wc -c < "$file") bytes)"
    status_set last_success "$(epoch_now)"
    status_set last_file "$(basename "$file")"

    prune || log "pruning failed; old copies were left in place" >&2
    if [ -n "${BACKUP_UPLOAD_COMMAND:-}" ]; then
        # The command receives the file path as its first argument (rclone, rsync, scp, a script...).
        if bash -c "$BACKUP_UPLOAD_COMMAND" upload "$file"; then
            log "uploaded $(basename "$file")"
            status_set upload_ok 1
        else
            status_set upload_ok 0
            backup_failed "the off-machine copy of $(basename "$file") failed; the local copy is kept"
            return 1
        fi
    else
        status_set upload_ok na
    fi

    status_set last_error ""
    if [ "$(status_get backup_state)" = failing ]; then
        status_set backup_state ok
        alert "[BACKUP] backups are working again ($(basename "$file"))"
    else
        status_set backup_state ok
    fi
}

# Percentage of the filesystem holding $1 that is used; prints nothing (and fails) if it cannot be read.
disk_percent() {
    ${DF_CMD:-df} -P "$1" 2> /dev/null | awk 'NR == 2 { gsub("%", "", $5); print $5 }'
}

# Checks the backups disk and the database disk against the warning and critical levels (deployment.md section 7). A disk
# that cannot be read counts as critical, never as 0% used. Alerts once per change of level.
disk_check() {
    local name path percent level previous worst=ok
    for name in backups data; do
        if [ "$name" = backups ]; then path="$BACKUP_DIR"; else path="$DATA_DIR"; fi
        [ -d "$path" ] || continue
        percent="$(disk_percent "$path" || true)"
        if [[ ! "$percent" =~ ^[0-9]+$ ]]; then
            level=critical
            log "[DISK] CRITICAL: cannot read how full $path is" >&2
        elif [ "$percent" -ge "$DISK_CRIT_PERCENT" ]; then
            level=critical
            log "[DISK] CRITICAL: $path is ${percent}% full (critical at ${DISK_CRIT_PERCENT}%)" >&2
        elif [ "$percent" -ge "$DISK_WARN_PERCENT" ]; then
            level=warn
            log "[DISK] WARN: $path is ${percent}% full (warning at ${DISK_WARN_PERCENT}%)" >&2
        else
            level=ok
        fi
        status_set "disk_${name}_percent" "${percent:-unknown}"
        previous="$(status_get "disk_${name}_level")"
        status_set "disk_${name}_level" "$level"
        if [ "$level" != ok ] && [ "$previous" != "$level" ]; then
            alert "[DISK] the $name disk ($path) is ${percent:-unreadable}% full: $level"
        elif [ "$level" = ok ] && [ -n "$previous" ] && [ "$previous" != ok ]; then
            alert "[DISK] the $name disk ($path) is back to ${percent}% full"
        fi
        if [ "$level" = critical ]; then worst=critical; elif [ "$level" = warn ] && [ "$worst" = ok ]; then worst=warn; fi
    done
    status_set disk_level "$worst"
}

# Container healthcheck: healthy only if a backup has succeeded recently, the off-machine copy (if configured) works, and
# no disk is critical. A stack that has only just started gets 2.5 intervals to make its first backup.
health() {
    local now started last reference limit
    now="$(epoch_now)"
    started="$(status_get started)"
    if [ -z "$started" ]; then echo "unhealthy: the backup loop has not started"; return 1; fi
    last="$(status_get last_success)"
    reference="$started"
    if [ -n "$last" ] && [ "$last" -gt "$started" ]; then reference="$last"; fi
    limit=$((BACKUP_INTERVAL_MINUTES * 60 * 5 / 2))
    if [ $((now - reference)) -gt "$limit" ]; then
        echo "unhealthy: no successful backup for $(((now - reference) / 60)) minutes (limit $((limit / 60))); last error: $(status_get last_error)"
        return 1
    fi
    if [ "$(status_get upload_ok)" = 0 ]; then echo "unhealthy: the off-machine copy is failing"; return 1; fi
    if [ "$(status_get disk_level)" = critical ]; then echo "unhealthy: a disk is critically full"; return 1; fi
    echo "healthy"
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
    # A dump that never finished (the container was stopped mid-dump) is never a backup; older than one interval it is
    # certainly abandoned, and left alone it would eat the disk.
    local stale
    for stale in "$BACKUP_DIR"/tindaflow-*.partial; do
        if [ -n "$(find "$stale" -mmin +"$BACKUP_INTERVAL_MINUTES" 2> /dev/null)" ]; then
            rm -f -- "$stale"
            log "removed abandoned ${stale##*/}"
        fi
    done
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

main() {
    case "${1:-loop}" in
        once)
            status_set started "$(epoch_now)"
            disk_check
            take_backup
            ;;
        prune) prune ;;
        health) health ;;
        loop)
            log "every ${BACKUP_INTERVAL_MINUTES} min to ${BACKUP_DIR} (encrypted: $([ -n "${BACKUP_PASSPHRASE:-}" ] && echo yes || echo NO), off-machine copy: $([ -n "${BACKUP_UPLOAD_COMMAND:-}" ] && echo yes || echo NO), alerts: $([ -n "${ALERT_COMMAND:-}" ] && echo yes || echo NO))"
            status_set started "$(epoch_now)"
            while true; do
                disk_check || log "the disk check itself failed" >&2
                take_backup || log "backup failed; will retry in ${BACKUP_INTERVAL_MINUTES} min" >&2
                sleep $((BACKUP_INTERVAL_MINUTES * 60))
            done
            ;;
        *) echo "usage: backup.sh [loop|once|prune|health]" >&2; exit 2 ;;
    esac
}

# Run only when executed, so the functions above can be sourced by scripts/test-backup.sh.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    main "$@"
fi
