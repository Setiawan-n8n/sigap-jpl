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
    exec php artisan serve --host=0.0.0.0 --port=8000
fi
