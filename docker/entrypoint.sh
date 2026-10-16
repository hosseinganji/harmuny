#!/bin/sh
set -e
cd /app

mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# Only the web container migrates, so workers never race it.
if [ "${CONTAINER_ROLE:-app}" = "app" ]; then
  php artisan migrate --force
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
