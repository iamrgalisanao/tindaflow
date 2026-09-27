#!/usr/bin/env bash
# Sends the message in $1 to a Telegram chat (a bot in a group that includes the owner and the manager). Used by backup.sh
# when TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID are set and ALERT_COMMAND is not. Free, needs no server of the shop's own.
#
#   TELEGRAM_BOT_TOKEN  from @BotFather      TELEGRAM_CHAT_ID  the group's id (see docker/README.md)
#   ALERT_SITE_NAME     prefix so a message says which shop it is from (default: TindaFlow)
#   TELEGRAM_API_BASE   only for testing (default https://api.telegram.org)
#
# The bot token is passed to curl on standard input, never on its command line, so it does not show in `ps`.
set -euo pipefail

: "${TELEGRAM_BOT_TOKEN:?TELEGRAM_BOT_TOKEN is not set}"
: "${TELEGRAM_CHAT_ID:?TELEGRAM_CHAT_ID is not set}"
message="${1:?usage: alert-telegram.sh <message>}"
site="${ALERT_SITE_NAME:-TindaFlow}"
api="${TELEGRAM_API_BASE:-https://api.telegram.org}"

curl -fsS -m 15 --retry 2 -o /dev/null -K - <<CURL_CONFIG
url = "${api}/bot${TELEGRAM_BOT_TOKEN}/sendMessage"
data-urlencode = "chat_id=${TELEGRAM_CHAT_ID}"
data-urlencode = "text=[${site}] ${message}"
CURL_CONFIG
