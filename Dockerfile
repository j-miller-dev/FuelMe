# syntax=docker/dockerfile:1.7
# FUEL production image: one image, three roles (app | worker | scheduler) chosen by the container command.

# ---- 1. Front-end build (Vite + Inertia + PWA service worker) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json components.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- 2. PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---- 3. Runtime: FrankenPHP (Caddy + PHP 8.3). TLS is terminated by Coolify/Traefik in front. ----
FROM dunglas/frankenphp:1-php8.3 AS app
RUN install-php-extensions pdo_pgsql intl zip bcmath gmp pcntl opcache redis \
 && apt-get update && apt-get install -y --no-install-recommends curl tzdata \
 && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr SERVER_NAME=":80" TZ=UTC
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/app/Caddyfile /etc/caddy/Caddyfile
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 CMD curl -fsS http://localhost/up || exit 1
ENTRYPOINT ["entrypoint"]
CMD ["app"]
