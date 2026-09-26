#!/usr/bin/env bash
#
# Regenerates every picture in the Help guides (resources/js/pages/help/shots) from a real browser.
#
# It builds a SCRATCH store: its own PostgreSQL database (tindaflow_help), its own server on port 8001 and its own
# browser profile. Your development data is never touched, and no real store data can end up in a screenshot.
# Run it again whenever a screen the guides show has changed, then check resources/js/pages/help/shots/annotations.json
# still has every target a guide names (node scripts/help-shots/validate.mjs).
#
# Needs: PostgreSQL running locally with a role that may create databases, Google Chrome (or CHROME=/path/to/chrome),
# and puppeteer-core, installed WITHOUT touching package.json:   npm install --no-save puppeteer-core
#
#   scripts/help-shots/run.sh              # everything, in order
#   scripts/help-shots/run.sh 09 10        # only the scripts whose names start with these numbers (the database
#                                          # must already be in the state the earlier scripts leave it in)
#
# Note: the steps are slow (about 30 minutes in all) and each one changes the scratch database. If a browser refuses to close and a
# step stalls, stop the run (Ctrl-C) and start it again from the top; a partial run is never safe to resume.
#
# Environment: PGUSER (default: your login), DB_USERNAME/DB_PASSWORD for the app's own role (default tindaflow/tindaflow).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PORT="${HELP_SHOTS_PORT:-8001}"
DB="${HELP_SHOTS_DB:-tindaflow_help}"
export HELP_SHOTS_BASE="http://localhost:$PORT"
PROFILE="${HELP_SHOTS_PROFILE:-${TMPDIR:-/tmp}/tindaflow-help-shots-profile}"
export HELP_SHOTS_PROFILE="$PROFILE"

export DB_DATABASE="$DB"
export APP_URL="$HELP_SHOTS_BASE"
export SESSION_COOKIE=tindaflow_help_session
export TINDAFLOW_INITIAL_STORE_NAME="Aling Nena's Sari-Sari Store"
export TINDAFLOW_INITIAL_ADMIN_EMAIL=admin@tindaflow.test
export TINDAFLOW_INITIAL_ADMIN_PASSWORD='Guide-Admin-2026!'

stop_browsers() { pkill -f "$PROFILE" 2>/dev/null || true; sleep 1; rm -f "$PROFILE"/Singleton* 2>/dev/null || true; }

if [ "$#" -eq 0 ]; then
  echo "== resetting the scratch database $DB"
  psql -q -d postgres -c "DROP DATABASE IF EXISTS $DB WITH (FORCE)" -c "CREATE DATABASE $DB OWNER ${DB_USERNAME:-tindaflow}"
  stop_browsers
  rm -rf "$PROFILE"
  (cd "$ROOT" && php artisan migrate --force --no-interaction >/dev/null && php artisan db:seed --force --no-interaction >/dev/null)
  echo '{}' > "$ROOT/resources/js/pages/help/shots/annotations.json"
  rm -f "$ROOT"/resources/js/pages/help/shots/*.webp
fi

# The pages are served from a static build so a running Vite dev server cannot reload them in the middle of a capture.
if [ -f "$ROOT/public/hot" ]; then
  echo "Stop 'composer run dev' (and delete public/hot) first: a live Vite server reloads the page mid-capture." >&2
  exit 1
fi
[ -f "$ROOT/public/build/manifest.json" ] || (cd "$ROOT" && npm run build)

if ! curl -fsS "$HELP_SHOTS_BASE/up" >/dev/null 2>&1; then
  echo "== starting the scratch server on $HELP_SHOTS_BASE"
  (cd "$ROOT/public" && PHP_CLI_SERVER_WORKERS=4 nohup php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1 &)
  for _ in $(seq 1 20); do curl -fsS "$HELP_SHOTS_BASE/up" >/dev/null 2>&1 && break; sleep 1; done
fi

cd "$HERE"
if [ "$#" -eq 0 ]; then set -- ""; fi
for prefix in "$@"; do
  for script in "$prefix"*.mjs; do
    [ "$script" = "lib.mjs" ] && continue
    [ -f "$script" ] || continue
    echo "== $script"
    node "$script" || { echo "FAILED: $script" >&2; stop_browsers; exit 1; }
  done
done
echo "== done. Now run: node scripts/help-shots/validate.mjs"
