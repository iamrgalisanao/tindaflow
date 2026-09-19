#!/bin/sh
# Starts the app container: waits for the database, caches the configuration, applies migrations, hands over to
# PHP-FPM. Artisan runs as www-data so nothing it writes is root-owned.
set -eu
cd /var/www/html

as_app() { runuser -u www-data -- "$@"; }

if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
fi

# The database may still be starting; do not race it.
tries=0
until php /usr/local/bin/healthcheck.php 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 60 ]; then
        echo "tindaflow: the database did not become reachable in 60 seconds" >&2
        exit 1
    fi
    sleep 1
done

# Environment is read once, here, and baked into the cached config (workers never need it).
as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan event:cache
as_app php artisan view:cache

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    as_app php artisan migrate --force
fi

exec "$@"
