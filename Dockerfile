# Production image: FrankenPHP serving Laravel. Built for Google Cloud Run, which sets $PORT.
# Base images come through mirror.gcr.io, Google's public Docker Hub cache, to avoid Docker Hub pull limits.

FROM mirror.gcr.io/library/node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM mirror.gcr.io/library/composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --no-scripts --classmap-authoritative

FROM mirror.gcr.io/dunglas/frankenphp:1-php8.4-alpine
RUN install-php-extensions pdo_pgsql opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint \
    && mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && php artisan package:discover --ansi \
    && php artisan route:cache \
    && php artisan view:cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_ROOT=/app/public

EXPOSE 8080
ENTRYPOINT ["app-entrypoint"]
