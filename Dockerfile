# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — bundel aset frontend (Vite + Tailwind)
# ---------------------------------------------------------------------------
FROM --platform=linux/amd64 node:22-alpine AS assets
WORKDIR /app

# package-lock.json dulu agar layer `npm ci` tetap ter-cache.
COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2 — runtime: FrankenPHP (server HTTP + PHP worker dalam satu proses)
# ---------------------------------------------------------------------------
# Tag dipetakan penuh supaya build bisa direproduksi. Varian bookworm (Debian
# 12) sama dengan basis sebelumnya; PHP 8.4 karena composer.lock membutuhkan
# >=8.4.1 (symfony 8.x) dan sama dengan versi PHP pengembangan lokal.
# FrankenPHP >=1.5 adalah syarat laravel/octane, dan varian ZTS-nya membuat
# `install-php-extensions` meng-compile ekstensi untuk ABI yang tepat.
# Digest dipin supaya rebuild deterministik (tag `:1.12.7` tetap mengarah ke
# image yang sama; bila mau update, ganti digest setelah `docker manifest inspect`).
FROM --platform=linux/amd64 dunglas/frankenphp:1.12.7-php8.4-bookworm@sha256:4c5abb38de56af73d7110c2facde95294bd7145793af3cf00c15cd6905921f6c AS runtime

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# Ekstensi mengikuti tuntutan composer.lock (gd, intl, zip) ditambah driver
# database yang tersedia di Dokploy (MySQL maupun Postgres), `pcntl` untuk
# sinyal proses worker queue, dan `sockets`/`bcmath` untuk perhitungan serta
# koneksi berbasis socket. pdo_sqlite, mbstring, dom, simplexml, curl, dan
# posix sudah bawaan image.
RUN install-php-extensions \
        mbstring fileinfo dom simplexml curl posix
RUN install-php-extensions \
        gd intl zip
RUN install-php-extensions \
        bcmath exif sockets
RUN install-php-extensions \
        pcntl
RUN install-php-extensions \
        pdo_mysql pdo_pgsql pdo_sqlite
RUN install-php-extensions \
        opcache

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

# Konfigurasi runtime. Caddyfile dipakai `frankenphp run` yang dilewatkan
# `php artisan octane:frankenphp --caddyfile=docker/Caddyfile` (satu-satunya
# salinan, tidak diduplikasi ke /etc/frankenphp); php.ini produksi bawaan
# image dijadikan basis lalu ditimpa docker/php.ini.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh /entrypoint.sh

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && chmod +x /entrypoint.sh \
    # /data dan /config adalah XDG dirs bawaan image yang dipakai Caddy;
    # harus dimiliki www-data karena kontainer tidak lagi jalan sebagai root.
    && mkdir -p /data /config \
    # -R supaya subdirektori bawaan image (mis. /data/caddy untuk autosave
    # konfigurasi Caddy) ikut bisa ditulis sebagai www-data.
    && chown -R www-data:www-data /data /config \
    # Milik www-data: worker Octane, queue, dan scheduler menulis log serta
    # cache view ke volume storage yang sama, jadi hak tulisnya harus seragam.
    && chown -R www-data:www-data storage bootstrap/cache \
    # Validasi Caddyfile saat build supaya kesalahan sintaks langsung
    # mematikan build. Variabel di bawah adalah placeholder yang sama yang
    # dikirim Octane ketika container berjalan.
    && CADDY_GLOBAL_OPTIONS="auto_https disable_redirects" \
       CADDY_SERVER_ADMIN_HOST=localhost \
       CADDY_SERVER_ADMIN_PORT=2019 \
       CADDY_SERVER_SERVER_NAME="http://:80" \
       CADDY_SERVER_LOG_LEVEL=WARN \
       CADDY_SERVER_LOGGER=json \
       CADDY_SERVER_WORKER_DIRECTIVE="num 2" \
       CADDY_SERVER_WATCH_DIRECTIVES="" \
       APP_PUBLIC_PATH=/var/www/html/public \
       frankenphp validate --config docker/Caddyfile --adapter caddyfile

# /up adalah health check bawaan Laravel (bootstrap/app.php). curl sudah
# bawaan image.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up > /dev/null || exit 1

EXPOSE 80

# Non-root sejak awal, juga saat `docker run` tanpa docker-compose. Binary
# frankenphp memegang cap_net_bind_service sehingga port 80 tetap bisa diikat
# oleh www-data.
USER www-data

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=80", "--caddyfile=docker/Caddyfile"]
