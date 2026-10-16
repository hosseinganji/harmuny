# ---- Frontend (Vue PWA) ----
FROM node:22-alpine AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---- Backend dependencies ----
FROM composer:2 AS vendor
WORKDIR /build
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev

# ---- Runtime: FrankenPHP (Caddy + PHP) serves the API, the PWA and HTTPS ----
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions pdo_mysql gmp bcmath intl zip pcntl opcache

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

WORKDIR /app
COPY --from=vendor /build /app
COPY --from=frontend /build/dist /app/spa
RUN rm -f /app/.env && mkdir -p /app/storage/logs /app/bootstrap/cache

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
