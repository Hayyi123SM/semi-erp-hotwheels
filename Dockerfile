# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — bundel aset frontend (Vite + Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app

# package-lock.json dulu agar layer `npm ci` tetap ter-cache.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — runtime: PHP-FPM + nginx + supervisor dalam satu container
# ---------------------------------------------------------------------------
# 8.4 karena composer.lock membutuhkan >=8.4.1 (symfony 8.x) dan sama dengan
# versi PHP yang dipakai pengembangan lokal.
FROM php:8.4-fpm-bookworm AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# Ekstensi mengikuti tuntutan composer.lock (gd, intl, zip, mbstring, xml)
# ditambah driver database yang tersedia di Dokploy (MySQL maupun Postgres),
# `pcntl` untuk sinyal proses worker queue, dan `sockets`/`bcmath` untuk
# perhitungan serta koneksi berbasis socket. `pdo_sqlite`, `mbstring`, `dom`,
# dan `simplexml` sudah bawaan image resmi PHP.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        curl \
        ca-certificates \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libpq-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        zip \
        bcmath \
        exif \
        sockets \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Dependensi PHP dipasang sebelum source disalin supaya layer ini tetap
# ter-cache selama composer.json/composer.lock tidak berubah. Skrip tidak
# dijalankan di sini karena file aplikasi belum ada. Composer hanya di-mount
# selama perintah berjalan, jadi binarinya tidak ikut tersimpan di image.
COPY composer.json composer.lock ./
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/tmp/composer \
    /tmp/composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts

COPY . .
# Hasil build Vite tidak ikut konteks (di-.dockerignore), jadi disalin dari
# stage aset.
COPY --from=assets /app/public/build ./public/build

# `dump-autoload` menjalankan kembali post-autoload-dump (package:discover)
# yang tadi ditunda.
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/tmp/composer \
    /tmp/composer dump-autoload --no-dev --optimize --no-interaction

# Konfigurasi runtime. nginx memakai layout Debian, jadi config disimpan di
# sites-available/default yang sudah ditautkan oleh paketnya.
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-app.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    # Milik www-data: proses php-fpm/queue menulis log & cache view, sementara
    # entrypoint menjalankan artisan dengan user yang sama.
    && chown -R www-data:www-data storage bootstrap/cache

# /up adalah health check bawaan Laravel (bootstrap/app.php).
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up > /dev/null || exit 1

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
