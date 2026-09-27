#!/usr/bin/env bash
# Copies the backup file in $1 to an rclone destination (Backblaze B2 recommended: docker/README.md). Used by backup.sh when
# RCLONE_DEST is set and BACKUP_UPLOAD_COMMAND is not. The remote is configured entirely through RCLONE_CONFIG_* environment
# variables (no config file), for example:
#   RCLONE_DEST=b2:my-bucket/shop1  RCLONE_CONFIG_B2_TYPE=b2  RCLONE_CONFIG_B2_ACCOUNT=<keyID>  RCLONE_CONFIG_B2_KEY=<applicationKey>
#
# --immutable: an existing remote file is never overwritten or changed, so this only ever ADDS files. Give the bucket key no
# delete permission and a compromised server cannot erase or replace its own off-machine backups.
set -euo pipefail

: "${RCLONE_DEST:?RCLONE_DEST is not set}"
file="${1:?usage: upload-rclone.sh <backup file>}"

rclone copyto "$file" "${RCLONE_DEST%/}/$(basename "$file")" \
    --immutable --retries 3 --low-level-retries 5 --contimeout 30s --timeout 5m --stats 0
