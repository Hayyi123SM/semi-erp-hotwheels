# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — bundel aset frontend (Vite + Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — runtime: FrankenPHP (server HTTP + PHP worker dalam satu proses)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1.12.7-php8.4-bookworm AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN install-php-extensions gd intl zip bcmath exif sockets pcntl pdo_mysql pdo_pgsql

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
    && mkdir -p /data /config \
    && chown -R www-data:www-data /data /config \
    && chown -R www-data:www-data storage bootstrap/cache \
    && CADDY_GLOBAL_OPTIONS="auto_https disable_redirects" \
       CADDY_SERVER_ADMIN_HOST=localhost \
       CADDY_SERVER_ADMIN_PORT=2019 \
       CADDY_SERVER_SERVER_NAME="http://:8000" \
       CADDY_SERVER_LOG_LEVEL=WARN \
       CADDY_SERVER_LOGGER=json \
       CADDY_SERVER_WORKER_DIRECTIVE="num 2" \
       CADDY_SERVER_WATCH_DIRECTIVES="" \
       APP_PUBLIC_PATH=/var/www/html/public \
       frankenphp validate --config docker/Caddyfile --adapter caddyfile

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/up > /dev/null || exit 1

EXPOSE 8000

USER www-data

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8000", "--caddyfile=docker/Caddyfile"]
