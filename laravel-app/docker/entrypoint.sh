#!/usr/bin/env bash
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Kalau APP_KEY sudah disuplai lewat environment variable asli (mis. diatur di
# Coolify), pakai itu dan JANGAN generate ulang -- supaya kuncinya stabil
# antar redeploy (file .env di dalam container bersifat sementara/ephemeral).
if [ -z "${APP_KEY:-}" ]; then
    if ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
        php artisan key:generate --force
    fi
fi

mkdir -p storage/app/videos/results/snapshots database
touch database/database.sqlite

php artisan migrate --force

php artisan sigap:ensure-admin

if [ "$1" = "queue" ]; then
    echo "Starting queue worker..."
    exec php artisan queue:work --tries=1 --timeout=1800
else
    echo "Starting web server on :8000..."
    # PENTING: jangan pakai "php artisan serve". Perintah itu menjalankan PHP
    # built-in server sebagai proses ANAK lewat Symfony Process, dan proses
    # anak itu terbukti TIDAK mewarisi semua environment variable milik
    # container (mis. APP_KEY hilang -> MissingAppKeyException) walau proses
    # induknya sendiri punya env yang benar. Dengan exec langsung ke `php -S`
    # memakai router.php yang sama yang dipakai artisan serve, proses PHP ini
    # SENDIRI yang jadi PID 1 dan otomatis mewarisi seluruh environment
    # container tanpa filtering.
    exec php -S 0.0.0.0:8000 vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
fi
