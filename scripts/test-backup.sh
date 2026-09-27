#!/usr/bin/env bash
#
# Tests docker/backup/backup.sh without a database: pg_dump, pg_restore and (where needed) openssl are stubs on PATH, and
# the script is sourced, not run. Plain bash, no test framework. It needs GNU date and bash 4.2+ (the same the backup
# container has), so on a Mac run it in the same image the stack uses:
#
#   docker run --rm -v "$PWD":/w postgres:17 bash /w/scripts/test-backup.sh
#
# Exits non-zero if any check fails.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$HERE/../docker/backup/backup.sh"
PASS=0
FAIL=0

check() { # description, then a command; passes if the command succeeds
    local description="$1"
    shift
    if "$@" > /dev/null 2>&1; then PASS=$((PASS + 1)); printf 'ok      %s\n' "$description"; else FAIL=$((FAIL + 1)); printf 'FAILED  %s\n' "$description"; fi
}
equals() { [ "$1" = "$2" ]; }
missing() { [ ! -e "$1" ]; }
present() { [ -e "$1" ]; }
gone_all() { ! compgen -G "$1" > /dev/null; }
count_lines() { if [ -f "$1" ]; then wc -l < "$1" | tr -d ' '; else echo 0; fi; }

# A fresh sandbox per case: its own BACKUP_DIR, stub binaries and alert log.
new_case() {
    WORK="$(mktemp -d)"
    export BACKUP_DIR="$WORK/backups" DATA_DIR="$WORK/data" ALERTS="$WORK/alerts.log" BIN="$WORK/bin"
    mkdir -p "$BACKUP_DIR" "$DATA_DIR" "$BIN"
    export PATH="$BIN:$ORIGINAL_PATH"
    export ALERT_COMMAND='printf "%s\n" "$1" >> "$ALERTS"'
    export BACKUP_INTERVAL_MINUTES=60 BACKUP_PASSPHRASE='' BACKUP_UPLOAD_COMMAND='' DF_CMD=''
    unset TELEGRAM_BOT_TOKEN TELEGRAM_CHAT_ID RCLONE_DEST HEARTBEAT_URL BACKUP_UPLOAD_UNENCRYPTED ALERT_SITE_NAME TELEGRAM_API_BASE
    unset BACKUP_NOW
    stub pg_dump 'printf "PGDMP-data" > "${@: -2:1}" 2>/dev/null || true; exit 0' # writes to the -f argument
    stub pg_restore 'exit 0'
    # shellcheck disable=SC1090
    source "$SCRIPT"
}
stub() { printf '#!/usr/bin/env bash\n%s\n' "$2" > "$BIN/$1"; chmod +x "$BIN/$1"; }
ORIGINAL_PATH="$PATH"

# pg_dump stub that writes its output file (the value after -f) and then behaves as told.
dump_stub() { # exit code
    stub pg_dump 'out=""; while [ $# -gt 0 ]; do if [ "$1" = -f ]; then out="$2"; fi; shift; done; printf "PGDMP-data" > "$out"; exit '"$1"
}

echo "== a failing pg_dump"
new_case
dump_stub 1
take_backup 2> /dev/null || RESULT=$?
check "take_backup returns non-zero even when called as 'take_backup || ...'" equals "${RESULT:-0}" 1
check "no .dump is kept" gone_all "$BACKUP_DIR/*.dump"
check "no .partial is left behind" gone_all "$BACKUP_DIR/*.partial"
check "last_error is recorded" test -n "$(status_get last_error)"
check "backup_state is failing" equals "$(status_get backup_state)" failing
check "one alert was sent" equals "$(count_lines "$ALERTS")" 1
take_backup 2> /dev/null || true
check "a second failure does not alert again" equals "$(count_lines "$ALERTS")" 1
rm -rf "$WORK"

echo "== an unreadable (truncated) dump"
new_case
touch -d '2 days ago' "$BACKUP_DIR/tindaflow-20200101-000000.dump"
dump_stub 0
stub pg_restore 'exit 1'
take_backup 2> /dev/null || true
check "the unreadable dump is discarded" gone_all "$BACKUP_DIR/tindaflow-2[0-9]*-*.partial"
check "no new .dump is kept" equals "$(ls "$BACKUP_DIR"/tindaflow-*.dump | wc -l | tr -d ' ')" 1
check "the earlier good backup survives" present "$BACKUP_DIR/tindaflow-20200101-000000.dump"
check "the failure is reported" equals "$(status_get backup_state)" failing
rm -rf "$WORK"

echo "== a good backup, and recovery"
new_case
dump_stub 1
take_backup 2> /dev/null || true
dump_stub 0
take_backup > /dev/null 2>&1
check "a .dump is kept" test "$(ls "$BACKUP_DIR"/tindaflow-*.dump | wc -l)" -eq 1
check "last_success is recorded" test -n "$(status_get last_success)"
check "state is ok again" equals "$(status_get backup_state)" ok
check "last_error is cleared" equals "$(status_get last_error)" ""
check "exactly one failure alert and one recovery alert" equals "$(count_lines "$ALERTS")" 2
check "the recovery alert says so" grep -q "working again" "$ALERTS"
rm -rf "$WORK"

echo "== upload failure"
new_case
dump_stub 0
BACKUP_UPLOAD_COMMAND='false'
BACKUP_PASSPHRASE='secret'
take_backup > /dev/null 2>&1 || RESULT=$?
check "take_backup reports the failed upload" equals "${RESULT:-0}" 1
check "upload_ok is 0" equals "$(status_get upload_ok)" 0
check "the local copy is kept" test "$(ls "$BACKUP_DIR"/tindaflow-*.dump* | grep -vc partial)" -eq 1
check "health is unhealthy" bash -c '! ( source "'"$SCRIPT"'"; BACKUP_DIR="'"$BACKUP_DIR"'"; STATUS_FILE="'"$BACKUP_DIR"'/.status"; health )'
rm -rf "$WORK"

echo "== encryption failure never leaves a plaintext dump"
new_case
dump_stub 0
stub openssl 'exit 1'
BACKUP_PASSPHRASE='secret'
take_backup > /dev/null 2>&1 || true
check "no plaintext .dump is left" gone_all "$BACKUP_DIR/*.dump"
check "no .enc is left" gone_all "$BACKUP_DIR/*.enc"
rm -rf "$WORK"

echo "== health"
new_case
now="$(date -u +%s)"
check "no status file at all is unhealthy" bash -c '! ( source "'"$SCRIPT"'"; BACKUP_DIR="'"$BACKUP_DIR"'"; STATUS_FILE="'"$BACKUP_DIR"'/.status"; health )'
status_set started "$now"
check "just started is healthy" health
status_set started "$((now - 3 * 3600))"
check "started 3 hours ago with no backup is unhealthy (limit 2.5 intervals)" bash -c '! ( source "'"$SCRIPT"'"; BACKUP_DIR="'"$BACKUP_DIR"'"; STATUS_FILE="'"$BACKUP_DIR"'/.status"; health )'
status_set last_success "$((now - 3600))"
check "a backup an hour ago is healthy" health
status_set last_success "$((now - 3 * 3600))"
check "the last backup 3 hours ago is unhealthy" bash -c '! ( source "'"$SCRIPT"'"; BACKUP_DIR="'"$BACKUP_DIR"'"; STATUS_FILE="'"$BACKUP_DIR"'/.status"; health )'
status_set last_success "$now"
status_set disk_level critical
check "a critical disk is unhealthy" bash -c '! ( source "'"$SCRIPT"'"; BACKUP_DIR="'"$BACKUP_DIR"'"; STATUS_FILE="'"$BACKUP_DIR"'/.status"; health )'
status_set disk_level warn
check "a warning disk is still healthy" health
rm -rf "$WORK"

echo "== disk check"
new_case
df_at() { printf '#!/usr/bin/env bash\nprintf "Filesystem 1024-blocks Used Available Capacity Mounted\\n/dev/x 100 %s 10 %s%% /\\n"\n' "$1" "$1"; }
stub fakedf "$(df_at 85 | tail -n +2)"
DF_CMD="$BIN/fakedf"
disk_check 2> "$WORK/log"
check "85% is a warning" equals "$(status_get disk_backups_level)" warn
check "the warning is logged with the [DISK] prefix" grep -q "\[DISK\] WARN" "$WORK/log"
check "one alert for the warning" equals "$(count_lines "$ALERTS")" 2 # backups disk and data disk both read the stub
disk_check 2> /dev/null
check "no repeat alert at the same level" equals "$(count_lines "$ALERTS")" 2
stub fakedf "$(df_at 95 | tail -n +2)"
disk_check 2> "$WORK/log"
check "95% is critical" equals "$(status_get disk_level)" critical
check "the critical level is logged" grep -q "\[DISK\] CRITICAL" "$WORK/log"
check "crossing to critical alerts again" equals "$(count_lines "$ALERTS")" 4
stub fakedf "$(df_at 40 | tail -n +2)"
disk_check 2> /dev/null
check "40% is ok again" equals "$(status_get disk_level)" ok
check "recovery alerts" equals "$(count_lines "$ALERTS")" 6
stub fakedf 'exit 1'
disk_check 2> "$WORK/log"
check "an unreadable disk counts as critical, not as 0%" equals "$(status_get disk_backups_level)" critical
rm -rf "$WORK"

echo "== abandoned partial dumps"
new_case
touch -d '3 hours ago' "$BACKUP_DIR/tindaflow-20200101-000000.dump.partial"
touch "$BACKUP_DIR/tindaflow-29990101-000000.dump.partial"
prune > /dev/null 2>&1
check "a partial older than one interval is removed" missing "$BACKUP_DIR/tindaflow-20200101-000000.dump.partial"
check "a fresh partial (a dump in progress) is kept" present "$BACKUP_DIR/tindaflow-29990101-000000.dump.partial"
rm -rf "$WORK"

echo "== an off-machine copy is refused unless the dump is encrypted"
new_case
dump_stub 0
BACKUP_UPLOAD_COMMAND='printf "%s\n" "$1" >> "$WORK/uploaded.log"'
export WORK
take_backup > /dev/null 2>&1 || RESULT=$?
check "the backup itself succeeds locally but the upload is refused (non-zero)" equals "${RESULT:-0}" 1
check "nothing was handed to the upload command" missing "$WORK/uploaded.log"
check "the reason names BACKUP_PASSPHRASE" grep -q "BACKUP_PASSPHRASE" "$BACKUP_DIR/.status"
check "upload_ok is 0, so health is unhealthy" equals "$(status_get upload_ok)" 0
check "the local dump is kept" test "$(ls "$BACKUP_DIR"/tindaflow-*.dump | wc -l)" -eq 1
rm -f "$BACKUP_DIR"/tindaflow-*.dump
BACKUP_UPLOAD_UNENCRYPTED=yes take_backup > /dev/null 2>&1
check "BACKUP_UPLOAD_UNENCRYPTED=yes lets an unencrypted dump go to a destination the owner controls" present "$WORK/uploaded.log"
rm -f "$WORK/uploaded.log"
BACKUP_PASSPHRASE='secret' take_backup > /dev/null 2>&1
check "with a passphrase the .enc file is what is uploaded" grep -q "\.dump\.enc$" "$WORK/uploaded.log"
check "and it is really encrypted, not the plaintext dump" bash -c '! grep -q PGDMP-data "$(cat "'"$WORK"'/uploaded.log")"'
rm -rf "$WORK"

echo "== rclone is the default off-machine copy when RCLONE_DEST is set"
new_case
dump_stub 0
BACKUP_PASSPHRASE='secret'
export WORK
stub rclone 'printf "%s\n" "$*" >> "$WORK/rclone.args"; exit 0'
RCLONE_DEST='b2:shop-bucket/till1' take_backup > /dev/null 2>&1
check "rclone was called once" equals "$(count_lines "$WORK/rclone.args")" 1
check "with copyto, the .enc file and the destination path" grep -q "^copyto .*tindaflow-.*\.dump\.enc b2:shop-bucket/till1/tindaflow-.*\.dump\.enc " "$WORK/rclone.args"
check "and --immutable, so the remote is only ever added to" grep -q -- "--immutable" "$WORK/rclone.args"
check "upload_ok is 1" equals "$(status_get upload_ok)" 1
stub rclone 'exit 1'
RCLONE_DEST='b2:shop-bucket/till1' take_backup > /dev/null 2>&1 || RESULT=$?
check "a failing rclone is a failed backup" equals "${RESULT:-0}" 1
upload_command_wins() { [ "$(BACKUP_UPLOAD_COMMAND=true RCLONE_DEST=x upload_command)" = true ]; }
check "BACKUP_UPLOAD_COMMAND wins over RCLONE_DEST" upload_command_wins
rm -rf "$WORK"

echo "== Telegram is the default alert when its two settings are present"
new_case
dump_stub 1
export WORK
unset ALERT_COMMAND
stub curl 'printf "%s\n" "$*" > "$WORK/curl.args"; cat > "$WORK/curl.stdin"; exit 0'
export TELEGRAM_BOT_TOKEN='123456:SECRET-TOKEN' TELEGRAM_CHAT_ID='-100999' ALERT_SITE_NAME='Aling Nena'
take_backup > /dev/null 2>&1 || true
check "curl was called once for the failure alert" test -f "$WORK/curl.args"
check "the bot token is NOT on curl's command line" bash -c '! grep -q SECRET-TOKEN "'"$WORK"'/curl.args"'
check "the bot token and chat id reach curl on stdin" bash -c 'grep -q "bot123456:SECRET-TOKEN/sendMessage" "'"$WORK"'/curl.stdin" && grep -q "chat_id=-100999" "'"$WORK"'/curl.stdin"'
check "the message names the shop and the problem" bash -c 'grep -q "Aling Nena" "'"$WORK"'/curl.stdin" && grep -q "backup failed" "'"$WORK"'/curl.stdin"'
rm -f "$WORK/curl.args" "$WORK/curl.stdin"
take_backup > /dev/null 2>&1 || true
check "a second identical failure does not send a second message" missing "$WORK/curl.args"
export ALERT_COMMAND='printf "%s\n" "$1" >> "$WORK/custom.log"'
rm -f "$BACKUP_DIR/.status"
take_backup > /dev/null 2>&1 || true
check "ALERT_COMMAND takes precedence over Telegram" present "$WORK/custom.log"
check "and Telegram was not used then" missing "$WORK/curl.args"
rm -rf "$WORK"

echo "== heartbeat (dead-man's switch)"
new_case
export WORK
stub curl 'printf "%s\n" "$*" >> "$WORK/curl.args"; exit 0'
export HEARTBEAT_URL='https://hc.example/ping/abc'
dump_stub 1
take_backup > /dev/null 2>&1 || true
check "a failed backup sends no heartbeat" missing "$WORK/curl.args"
dump_stub 0
take_backup > /dev/null 2>&1
check "a good backup pings the URL once" equals "$(count_lines "$WORK/curl.args")" 1
check "and it is the configured URL" grep -q "https://hc.example/ping/abc" "$WORK/curl.args"
stub curl 'exit 22'
take_backup > /dev/null 2>&1
check "a failing ping never fails the backup" equals "$(status_get backup_state)" ok
rm -rf "$WORK"

echo "== retention still works (regression)"
new_case
BACKUP_NOW=20260920-120000
for stamp in 20260920-110000 20260920-030000 20260918-090000 20260918-150000 20260101-100000 20260101-200000; do
    touch "$BACKUP_DIR/tindaflow-${stamp}.dump"
done
prune > /dev/null 2>&1
check "a backup from an hour ago is kept" present "$BACKUP_DIR/tindaflow-20260920-110000.dump"
check "a backup inside the 24-hour window is kept" present "$BACKUP_DIR/tindaflow-20260920-030000.dump"
check "the first backup of an older day is kept" present "$BACKUP_DIR/tindaflow-20260918-090000.dump"
check "a second backup of an older day is pruned" missing "$BACKUP_DIR/tindaflow-20260918-150000.dump"
check "the first backup of an old month is kept and its second is pruned" present "$BACKUP_DIR/tindaflow-20260101-100000.dump"
check "the second backup of that day is pruned" missing "$BACKUP_DIR/tindaflow-20260101-200000.dump"
check "the newest is never deleted" present "$BACKUP_DIR/tindaflow-20260920-110000.dump"
rm -rf "$WORK"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
