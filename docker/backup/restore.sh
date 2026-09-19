#!/usr/bin/env bash
# Restore a TindaFlow backup into a NEW database and report what came back.
#
#   restore.sh /backups/tindaflow-20260920-101500.dump[.enc] tindaflow_restored
#
# It refuses to write into a database that already exists, so a restore can never overwrite live data. To go live
# with the restored copy: stop the app, point DB_DATABASE at the new database (or rename the databases), start the
# app. Encrypted backups (.enc) need BACKUP_PASSPHRASE.
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "usage: restore.sh <backup file> <new database name>" >&2
    exit 2
fi
FILE="$1"
TARGET="$2"

DB_HOST="${DB_HOST:-postgres}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-tindaflow}"
export PGPASSWORD="${DB_PASSWORD:-${PGPASSWORD:-}}"
export PGHOST="$DB_HOST" PGPORT="$DB_PORT" PGUSER="$DB_USERNAME"

[ -f "$FILE" ] || { echo "no such file: $FILE" >&2; exit 1; }
[[ "$TARGET" =~ ^[A-Za-z0-9_]+$ ]] || { echo "the database name may only contain letters, digits and underscores" >&2; exit 2; }

if psql -d postgres -Atc "select 1 from pg_database where datname = '${TARGET}'" | grep -q 1; then
    echo "database '${TARGET}' already exists; restore only ever writes into a new one" >&2
    exit 1
fi

WORK="$FILE"
CLEANUP=""
if [[ "$FILE" == *.enc ]]; then
    [ -n "${BACKUP_PASSPHRASE:-}" ] || { echo "this backup is encrypted: set BACKUP_PASSPHRASE" >&2; exit 1; }
    WORK="$(mktemp)"
    CLEANUP="$WORK"
    openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$FILE" -out "$WORK"
fi
trap '[ -z "$CLEANUP" ] || rm -f "$CLEANUP"' EXIT

pg_restore --list "$WORK" > /dev/null
createdb "$TARGET"
pg_restore -d "$TARGET" --no-owner --exit-on-error "$WORK"

echo "restored into '${TARGET}'. What came back:"
psql -d "$TARGET" -At <<'SQL'
select 'tables: ' || count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE';
select 'indexes: ' || count(*) from pg_indexes where schemaname = 'public';
select 'migrations recorded: ' || count(*) from migrations;
select 'sales: ' || count(*) from sales;
select 'invoices: ' || count(*) from invoices;
select 'last invoice number: ' || coalesce(max(invoice_number), '(none)') from invoices;
SQL
