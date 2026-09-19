# syntax=docker/dockerfile:1
#
# TindaFlow: two images from one file.
#   target "app"  PHP-FPM with the application (the one that talks to PostgreSQL).
#   target "web"  nginx with the compiled public/ files baked in, so an upgrade replaces the assets together with
#                 the code (a shared volume would keep serving the previous release's files).
# docs/06-backend/stage-23-production-readiness.md and docker/README.md.

ARG PHP_VERSION=8.4

# ------------------------------------------------------------------ base: PHP with the extensions the app needs
FROM php:${PHP_VERSION}-fpm-bookworm AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath intl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

# ------------------------------------------------------------------ vendor: production dependencies only
FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# ------------------------------------------------------------------ assets: the compiled React app
FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ------------------------------------------------------------------ app: PHP-FPM
FROM base AS app
COPY --from=vendor /var/www/html/vendor ./vendor
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY resources/views ./resources/views
COPY public ./public
COPY storage ./storage
COPY artisan composer.json ./
COPY --from=assets /build/public/build ./public/build

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-tindaflow.ini
COPY docker/app/entrypoint.sh /usr/local/bin/tindaflow-entrypoint
COPY docker/app/healthcheck.php /usr/local/bin/healthcheck.php

# Discover the packages once, with a throwaway key (no database is touched), then make the writable paths writable.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/tindaflow-entrypoint

ENTRYPOINT ["tindaflow-entrypoint"]
CMD ["php-fpm"]
EXPOSE 9000

# ------------------------------------------------------------------ web: nginx
FROM nginx:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
