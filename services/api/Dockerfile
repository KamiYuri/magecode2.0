# MageCode API — multi-stage build.
# Targets: api (nginx + PHP-FPM, D-86), reverb (WebSocket server), test (suite runner).
# Build context is this directory:
#   docker build -f services/api/Dockerfile services/api

# ── Stage 1: PHP runtime with every extension the app needs ──
# Dependencies are resolved inside this image (not the stock composer image)
# so composer sees the same extension set as production — php-amqplib
# requires ext-sockets, which the composer image lacks.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache postgresql-dev rabbitmq-c-dev libzip-dev icu-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pgsql bcmath zip intl pcntl sockets \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

# ── Stage 2: production dependencies ──
FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist
COPY . .
# `dump-autoload` fires `package:discover`, which boots the app, and booting it
# registers `routes/channels.php` -- which instantiates the broadcaster. Since
# E9 gave `config/broadcasting.php` a `reverb` default, that means Pusher's
# constructor runs at build time and rejects a null key. Throwaway values get
# it past discovery; they live only in this RUN and never reach the image,
# which reads the real REVERB_* from the environment at container start. The
# Makefile carries the same workaround for `artisan` outside Docker.
RUN REVERB_APP_ID=build REVERB_APP_KEY=build REVERB_APP_SECRET=build \
    composer dump-autoload --optimize --no-dev

# ── Stage 3: application image shared by the runtime targets ──
FROM base AS app
COPY --from=vendor /var/www/html /var/www/html
# Config/route caching happens at container start, not build time: caching
# here would bake build-time env into the image.
RUN chown -R www-data:www-data storage bootstrap/cache

# ── Target: api (nginx + PHP-FPM) ──
FROM app AS api

RUN apk add --no-cache nginx curl
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# PHP-FPM on a unix socket; port 9000 belongs to nginx, 9001 exposes the
# status page consumed by php-fpm-exporter.
RUN printf '%s\n' '[www]' \
    'listen = /var/run/php-fpm.sock' \
    'listen.owner = www-data' \
    'listen.group = www-data' \
    'pm.status_path = /status' \
    'pm.status_listen = 9001' > /usr/local/etc/php-fpm.d/zz-docker.conf

COPY <<'ENTRYPOINT' /usr/local/bin/start.sh
#!/bin/sh
set -e
php-fpm -D
exec nginx -g 'daemon off;'
ENTRYPOINT
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 9000 9001
CMD ["/usr/local/bin/start.sh"]

# ── Target: reverb (WebSocket server) ──
FROM app AS reverb
EXPOSE 8080
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]

# ── Target: test (extensions + composer; source is bind-mounted at runtime) ──
FROM base AS test
CMD ["php", "artisan", "test"]
