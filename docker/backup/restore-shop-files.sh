#!/usr/bin/env bash
# Opens a shop-files archive (backup.sh) into a NEW or empty folder: the settings file, the HTTPS certificate and the
# app's stored files, which a database dump does not hold.
#
#   restore-shop-files.sh /backups/tindaflow-20260920-101500.files.tar.enc /backups/restored
#
# BACKUP_PASSPHRASE must be set (it is needed here and, afterwards, in the restored settings). It only unpacks: nothing
# of a running shop is touched. What it writes is plain text and secret; delete the folder when the restore is done.
# The steps around it are in docker/README.md, "Restoring a whole shop".
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "usage: restore-shop-files.sh <files archive> <new or empty folder>" >&2
    exit 2
fi
ARCHIVE="$1"
TARGET="$2"

[ -f "$ARCHIVE" ] || { echo "no such file: $ARCHIVE" >&2; exit 1; }
[ -n "${BACKUP_PASSPHRASE:-}" ] || { echo "the shop's files are always encrypted: set BACKUP_PASSPHRASE" >&2; exit 1; }
if [ -e "$TARGET" ] && { [ ! -d "$TARGET" ] || [ -n "$(ls -A "$TARGET")" ]; }; then
    echo "'$TARGET' already exists and is not an empty folder; restore only ever writes into a new or empty one" >&2
    exit 1
fi

umask 077
CREATED=""
[ -d "$TARGET" ] || CREATED="$TARGET"
mkdir -p "$TARGET"

# Decrypted to a private temporary file first, so a wrong passphrase leaves nothing half-unpacked behind.
PLAIN="$(mktemp)"
DONE=""
cleanup() {
    rm -f -- "$PLAIN"
    if [ -z "$DONE" ] && [ -n "$CREATED" ]; then rm -rf -- "$CREATED"; fi
}
trap cleanup EXIT

if ! openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_PASSPHRASE -in "$ARCHIVE" -out "$PLAIN" 2> /dev/null || ! tar -tf "$PLAIN" > /dev/null 2>&1; then
    echo "could not open the archive: the passphrase is wrong, or the file is damaged" >&2
    exit 1
fi
tar -C "$TARGET" -xf "$PLAIN"
if ! (cd "$TARGET" && sha256sum --check --quiet SHA256SUMS); then
    echo "the files do not match their checksums, so do not use them" >&2
    exit 1
fi
DONE=yes

echo "opened into '$TARGET':"
sed 's/^/  /' "$TARGET/manifest.txt"
echo "  settings file: env    certificate: certs/    stored files: storage/app/"
echo "Restore the database from the dump named above, then follow docker/README.md, \"Restoring a whole shop\"."
echo "This folder holds secrets in plain text: delete it when you are done."
