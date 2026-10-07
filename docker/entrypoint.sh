#!/bin/sh
# Menyiapkan aplikasi sebelum proses utama dijalankan.
set -eu

# Cache view/log ditulis oleh www-data, jadi artisan dijalankan dengan user
# yang sama supaya php-fpm tidak kalah hak tulis. Service worker/scheduler
# sudah diturunkan ke www-data lewat docker-compose, service app masih root
# karena supervisord dan nginx master membutuhkannya.
as_app() {
    if [ "$(id -u)" = "0" ]; then
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
    as_app php artisan view:cache

    echo "[entrypoint] menjalankan migrasi database"
    as_app php artisan migrate --force
fi

exec "$@"
