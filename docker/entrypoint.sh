#!/bin/sh
# Menyiapkan aplikasi sebelum proses utama dijalankan.
set -eu

# Semua service berjalan sebagai www-data (USER di Dockerfile + `user` di
# docker-compose), jadi artisan maupun FrankenPHP menulis log/cache view
# dengan hak tulis yang sama. Cabang root hanya dipakai bila kontainer sengaja
# dijalankan paksa sebagai root — image FrankenPHP tidak lagi membawa runuser,
# jadi beri pesan yang jelas alih-alih gagal dengan error membingungkan.
as_app() {
    if [ "$(id -u)" = "0" ]; then
        if ! command -v runuser > /dev/null 2>&1; then
            echo "[entrypoint] error: kontainer berjalan sebagai root tetapi runuser tidak tersedia; jalankan dengan user www-data" >&2
            exit 1
        fi
        runuser -u www-data -- "$@"
    else
        "$@"
    fi
}

# Config cache memakai environment container, jadi dipasang di setiap service
# (termasuk worker & scheduler yang tidak butuh route/view cache).
as_app php artisan config:cache

# Hanya instance utama yang memanaskan route/view cache dan menjalankan
# migrasi — worker/scheduler sengaja tidak, supaya tidak ada dua proses yang
# menulis file kompilasi view yang sama pada saat bersamaan.
if [ "${PRIMARY_APP:-false}" = "true" ]; then
    echo "[entrypoint] memanaskan route/view cache"
    as_app php artisan route:cache
    # Direktori framework bisa belum ada di volume storage yang masih kosong
    # (deploy pertama): `view:cache` menulis ke framework/views, session
    # (driver file) ke framework/sessions, cache (driver file) ke
    # framework/cache, dan Octane menulis file state proses ke storage/logs.
    as_app mkdir -p storage/framework/views storage/framework/sessions storage/framework/cache storage/logs
    as_app php artisan view:cache

    echo "[entrypoint] menjalankan migrasi database"
    as_app php artisan migrate --force
fi

exec "$@"
