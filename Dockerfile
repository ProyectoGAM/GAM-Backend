# syntax=docker/dockerfile:1.7

# La versión del cliente de PostgreSQL debe coincidir con la usada en Compose.
ARG POSTGRES_CLIENT_VERSION=18

FROM php:8.5-fpm AS php-base

ARG POSTGRES_CLIENT_VERSION

ENV APP_ENV=production \
    APP_DEBUG=false

# Extensiones necesarias para Laravel, PostgreSQL, Redis, Horizon,
# Media Library, backups y cálculos habituales del dominio.
COPY --from=ghcr.io/mlocati/php-extension-installer:2 \
    /usr/bin/install-php-extensions \
    /usr/local/bin/install-php-extensions

RUN set -eux; \
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
        redis \
        zip; \
    rm -f /usr/local/bin/install-php-extensions

# pg_dump es requerido por spatie/laravel-backup.
# Se utiliza el repositorio oficial para poder instalar la misma versión
# principal que tendrá el servidor PostgreSQL.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl tini; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl --fail --silent --show-error \
        --output /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
        https://www.postgresql.org/media/keys/ACCC4CF8.asc; \
    . /etc/os-release; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends "postgresql-client-${POSTGRES_CLIENT_VERSION}"; \
    rm -rf /var/lib/apt/lists/*

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
        /var/www/html/vendor \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

USER www-data


FROM php-base AS runtime

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html

# El mismo artefacto se reutilizará desde Compose:
# - API:       php-fpm -F
# - Worker:    php artisan horizon
# - Scheduler: php artisan schedule:work
USER www-data

EXPOSE 9000

STOPSIGNAL SIGQUIT

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["php-fpm", "-F"]
