# syntax=docker/dockerfile:1.7

# La versión del cliente de PostgreSQL debe coincidir con la usada en Compose.
ARG POSTGRES_CLIENT_VERSION=18

# El checkout de PhpRedis 6.3.0 trae el submódulo liblzf requerido por package.xml.
FROM scratch AS phpredis-source
ADD https://github.com/phpredis/phpredis.git#df4fab2de7fc327c54c94a13af2b9542e4fbd720 /phpredis/

FROM php:8.5.13-fpm-alpine3.24@sha256:51414d5291758fe27f58a64c7e8a078465d32cc7dcef7bda803f5b36019802e0 AS php-base

ARG POSTGRES_CLIENT_VERSION

ENV APP_ENV=production \
    APP_DEBUG=false

# Extensiones necesarias para Laravel, PostgreSQL, Redis, Horizon,
# Media Library, backups y cálculos habituales del dominio.
COPY --from=mlocati/php-extension-installer:2 \
    /usr/bin/install-php-extensions \
    /usr/local/bin/install-php-extensions

RUN --mount=type=bind,from=phpredis-source,source=/phpredis,target=/tmp/phpredis,rw \
    set -eux; \
    install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        posix \
        /tmp/phpredis \
        zip; \
    rm -f /usr/local/bin/install-php-extensions

# Mantiene UID/GID 33 para los volúmenes persistentes creados en Debian.
RUN set -eux; \
    test -z "$(awk -F: '$3 == 33' /etc/passwd /etc/group)"; \
    sed -i 's/^www-data:x:82:82:/www-data:x:33:33:/' /etc/passwd; \
    sed -i 's/^www-data:x:82:/www-data:x:33:/' /etc/group; \
    test "$(id -u www-data):$(id -g www-data)" = 33:33; \
    apk upgrade --no-cache; \
    apk add --no-cache ca-certificates curl tini tzdata "postgresql${POSTGRES_CLIENT_VERSION}-client"

WORKDIR /var/www/html


FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

# Primero se instalan dependencias para aprovechar el cache de capas.
COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-autoloader \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist

# Se copian únicamente los archivos necesarios en runtime.
# Esto evita incluir .env, tests, documentación y archivos locales en la imagen.
COPY artisan ./artisan
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY public ./public
COPY resources ./resources
COPY routes ./routes

# Los manifiestos de bootstrap/cache pueden haber sido generados en el host
# con paquetes require-dev (por ejemplo Laravel Pail). Se eliminan para que
# package:discover los reconstruya usando sólo las dependencias de producción.
RUN set -eux; \
    mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache; \
    rm -f bootstrap/cache/*.php; \
    composer dump-autoload \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --classmap-authoritative; \
    php artisan package:discover --ansi; \
    composer check-platform-reqs --no-dev


FROM vendor AS development

ENV APP_ENV=local \
    APP_DEBUG=true

# La imagen de desarrollo incorpora require-dev durante el build. Así los
# contenedores comparten un vendor completo sin necesitar un servicio init.
RUN --mount=type=cache,target=/tmp/composer-cache \
    set -eux; \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-interaction \
        --no-progress \
        --prefer-dist; \
    chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

USER www-data


FROM php-base AS runtime

RUN set -eux; \
    php-fpm -t; \
    pg_dump --version; \
    pg_restore --version

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# El mismo artefacto se reutilizará desde Compose:
# - API:       php-fpm -F
# - Worker:    php artisan horizon
# - Scheduler: php artisan schedule:work
USER www-data

EXPOSE 9000

STOPSIGNAL SIGQUIT

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm", "-F"]
