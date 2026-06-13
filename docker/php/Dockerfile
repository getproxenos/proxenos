# syntax=docker/dockerfile:1
# =============================================================================
# docker/php/Dockerfile — bug-free-happiness application image
# =============================================================================
# One parameterized Dockerfile, two variants (BASE_IMAGE set by docker-bake.hcl):
#   serversideup/php:8.5-frankenphp -> HTTP `app` service (FrankenPHP worker mode)
#   serversideup/php:8.5-cli        -> CLI `worker`/`scheduler`/`reactor` services
#
# serversideup ships FrankenPHP/Caddy (incl. the Mercure module), a built-in
# container healthcheck, install-php-extensions, and a non-root www-data user — so
# this file only layers project PHP extensions + the app. (X-ES's docker-php-xes-*
# scripts are NOT needed: the X-ES images wrapped serversideup; we use it directly.)
#
# Stages: base -> builder (composer + warmup) -> dev (xdebug) | prod (copy artifact)

ARG BASE_IMAGE=serversideup/php:8.5-frankenphp

FROM ${BASE_IMAGE} AS base
USER root
RUN install-php-extensions intl pdo_pgsql opcache
USER www-data
WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# builder — composer install (+ cache warmup) for the prod artifact
# -----------------------------------------------------------------------------
FROM base AS builder
USER root
COPY composer.json composer.lock* ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader
COPY . .
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer dump-autoload --classmap-authoritative --no-dev
RUN APP_ENV=prod APP_SECRET=build php bin/console cache:warmup
USER www-data

# -----------------------------------------------------------------------------
# dev — base + xdebug; source bind-mounted at runtime (compose.dev.yaml)
# -----------------------------------------------------------------------------
FROM base AS dev
USER root
RUN install-php-extensions xdebug
USER www-data

# -----------------------------------------------------------------------------
# prod — source + compiled vendor/, no dev tooling
# -----------------------------------------------------------------------------
FROM base AS prod
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
