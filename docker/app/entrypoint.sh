#!/usr/bin/env bash
# Role selection: app (web) | worker (queue) | scheduler. Only the web role migrates.
set -euo pipefail
cd /app

role="${1:-app}"

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

case "$role" in
  app)
    # Safe to run on every boot; Laravel takes a lock so two containers can't migrate at once.
    php artisan migrate --force --isolated
    exec frankenphp run --config /etc/caddy/Caddyfile
    ;;
  worker)
    exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
