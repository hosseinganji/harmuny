#!/bin/sh
set -e
cd /app

# Keys for the Mercure hub (Jam live updates, see Caddyfile). Unless set in .env, they are derived from
# APP_KEY, so they stay the same across restarts and Laravel signs subscriber tokens with the same one.
derive() { printf '%s|%s' "$APP_KEY" "$1" | sha256sum | cut -d' ' -f1; }
export MERCURE_PUBLISHER_JWT_KEY="${MERCURE_PUBLISHER_JWT_KEY:-$(derive mercure-publisher)}"
export MERCURE_SUBSCRIBER_JWT_KEY="${MERCURE_SUBSCRIBER_JWT_KEY:-$(derive mercure-subscriber)}"

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
