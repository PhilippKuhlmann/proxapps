# ProxApps – one container with nginx, php-fpm and the queue worker (supervisord),
# modelled on the DokuVault image: "docker run" and it works.

# ---------------------------------------------------------------- PHP packages
FROM --platform=$BUILDPLATFORM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts --no-autoloader

# ------------------------------------------------------------------- Frontend
FROM --platform=$BUILDPLATFORM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources resources
COPY app/Livewire app/Livewire
COPY vite.config.js .gitignore ./
RUN npm run build

# -------------------------------------------------------------------- Runtime
FROM php:8.4-fpm-alpine

RUN apk add --no-cache nginx supervisor iputils-ping sqlite tzdata \
    && docker-php-ext-enable opcache \
    && docker-php-ext-install -j1 pcntl

WORKDIR /app

COPY --from=vendor /app/vendor vendor
COPY . .
COPY --from=frontend /app/public/build public/build

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-interaction --no-scripts \
    && rm -f /usr/bin/composer bootstrap/cache/*.php \
    && rm -rf storage/logs/* storage/framework/cache/data/* storage/framework/sessions/* storage/framework/views/* \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/proxapps.ini
RUN mkdir -p /run/nginx /var/lib/nginx/tmp && chown -R www-data:www-data /var/lib/nginx

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/database.sqlite \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database \
    DB_QUEUE_RETRY_AFTER=7600 \
    CACHE_STORE=database \
    SESSION_LIFETIME=720

VOLUME ["/app/storage"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1
ENTRYPOINT ["entrypoint"]
