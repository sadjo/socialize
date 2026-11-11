#syntax=docker/dockerfile:1

# Image unique Socialize : FrankenPHP (Caddy + PHP 8.5) en mode worker
# et consommateur Symfony Messenger dans le même conteneur.

FROM dunglas/frankenphp:1-php8.5 AS frankenphp_upstream

# ---------------------------------------------------------------------------
# Base commune
# ---------------------------------------------------------------------------
FROM frankenphp_upstream AS frankenphp_base

ARG APP_UID=1000
ARG APP_GID=1000

WORKDIR /app

# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get install -y --no-install-recommends file git unzip libvips42t64 ffmpeg \
    && rm -rf /var/lib/apt/lists/*

RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        exif \
        ffi \
        intl \
        opcache \
        zip \
        pdo_sqlite \
        pdo_mysql \
    ;

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d" \
    DATABASE_URL="sqlite:////data/socialize.db" \
    MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=0" \
    STORAGE_DIR="/media" \
    WORKERS=1 \
    SERVER_NAME="localhost"

COPY --link docker/frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link --chmod=755 docker/frankenphp/messenger-supervisor.sh /usr/local/bin/messenger-supervisor
COPY --link docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

# Utilisateur non root, autorisé à écouter sur les ports 80 et 443.
RUN set -eux; \
    groupadd --gid "$APP_GID" socialize; \
    useradd --uid "$APP_UID" --gid "$APP_GID" --create-home --shell /bin/sh socialize; \
    setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp; \
    mkdir -p /data /media /config; \
    chown -R socialize:socialize /data /media /config /app

VOLUME ["/data", "/media"]

EXPOSE 80 443 443/udp

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s --interval=30s --timeout=5s \
    CMD curl -fsS http://localhost:2019/metrics > /dev/null || exit 1

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ---------------------------------------------------------------------------
# Développement : code monté depuis l'hôte, rechargement à chaud
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev \
    XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

RUN set -eux; \
    install-php-extensions xdebug

COPY --link docker/frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

USER socialize

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch"]

# ---------------------------------------------------------------------------
# Production
# ---------------------------------------------------------------------------
FROM frankenphp_base AS frankenphp_prod

ARG APP_UID=1000
ARG APP_GID=1000

ENV APP_ENV=prod \
    FRANKENPHP_CONFIG="worker /app/public/index.php"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --link docker/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

USER socialize

# Dépendances d'abord, pour profiter du cache Docker.
COPY --link --chown=${APP_UID}:${APP_GID} composer.* symfony.* ./
RUN set -eux; \
    composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY --link --chown=${APP_UID}:${APP_GID} . ./

RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer dump-env prod; \
    composer run-script --no-dev post-install-cmd; \
    php bin/console importmap:install; \
    php bin/console asset-map:compile; \
    chmod +x bin/console; \
    sync

# Version affichée dans l'interface (tag v0.X.Y passé par la CI) ; en dernier pour garder le cache des étapes précédentes.
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
