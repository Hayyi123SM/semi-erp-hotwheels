# syntax=docker/dockerfile:1

# ---------- Stage 1: aset frontend ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---------- Stage 2: base PHP (dipakai kedua target) ----------
FROM php:8.4-fpm-bookworm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions gd intl zip bcmath exif sockets pcntl pdo_mysql pdo_pgsql opcache

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/tmp/composer \
    /tmp/composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/tmp/composer \
    /tmp/composer dump-autoload --no-dev --optimize --no-interaction

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /entrypoint.sh

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && chmod +x /entrypoint.sh \
    && mkdir -p storage/logs storage/app/public storage/framework/cache/data \
                storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000
ENTRYPOINT ["/entrypoint.sh"]

# ---------- Target A: php artisan serve ----------
FROM base AS serve
ENV PHP_CLI_SERVER_WORKERS=4
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up >/dev/null || exit 1
USER www-data
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------- Target B: Nginx + PHP-FPM (default, last stage) ----------
FROM base AS fpm
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/supervisord.conf /etc/supervisord.conf
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up >/dev/null || exit 1
CMD ["supervisord", "-n", "-c", "/etc/supervisord.conf"]
