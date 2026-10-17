# Versions
# https://hub.docker.com/r/serversideup/php/tags?name=8.4-fpm-nginx-alpine
ARG SERVERSIDEUP_PHP_VERSION=8.4-fpm-nginx-alpine
# https://github.com/minio/mc/releases
ARG MINIO_VERSION=RELEASE.2025-08-13T08-35-41Z
# https://github.com/cloudflare/cloudflared/releases
ARG CLOUDFLARED_VERSION=2026.7.3
# https://www.postgresql.org/support/versioning/
# Note: We are using version 18 of the postgres client (while still using postgres 15 for the postgres server) as version 15 has been removed from Alpine 3.23+ https://pkgs.alpinelinux.org/packages?name=postgresql*-client&branch=v3.23&repo=&arch=x86_64&origin=&flagged=&maintainer=
ARG POSTGRES_VERSION=18
# https://nginx.org/en/linux_packages.html
ARG NGINX_VERSION=1.31.2-r1

# Add user/group
ARG USER_ID=9999
ARG GROUP_ID=9999

# =================================================================
# Stage 1: Composer dependencies
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS base

USER root

# Install patched Nginx from the official nginx.org Alpine repository
ARG NGINX_VERSION
RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

ARG USER_ID
ARG GROUP_ID

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

WORKDIR /var/www/html
COPY --chown=www-data:www-data composer.json composer.lock ./
# No BuildKit cache mounts: many self-hosted hosts only have the legacy builder
# (DOCKER_BUILDKIT=1 alone is not enough without the buildx plugin).
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

USER www-data

# =================================================================
# Stage 2: Frontend assets compilation
# =================================================================
FROM node:24-alpine AS static-assets

WORKDIR /app
COPY package*.json vite.config.js postcss.config.cjs ./
RUN npm ci
COPY . .
RUN npm run build

# =================================================================
# Stage 3: Get MinIO client
# dl.min.io returns 410 for this archived release. The binary comes from the
# official minio/mc GitHub release for MINIO_VERSION.
# =================================================================
FROM alpine:3 AS minio-client

ARG MINIO_VERSION
# Empty default: BuildKit/buildx fills TARGETARCH; legacy docker build leaves it empty.
ARG TARGETARCH=

RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    arch="${TARGETARCH}"; \
    if [ -z "${arch}" ]; then \
        case "$(uname -m)" in \
            x86_64|amd64) arch=amd64 ;; \
            aarch64|arm64) arch=arm64 ;; \
            *) echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;; \
        esac; \
    fi; \
    curl -fsSL "https://github.com/minio/mc/releases/download/${MINIO_VERSION}/mc.linux-${arch}.${MINIO_VERSION}" -o /usr/bin/mc; \
    chmod +x /usr/bin/mc; \
    mc --version

# =================================================================
# Final Stage: Production image
# =================================================================
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION}

ARG USER_ID
ARG GROUP_ID
# Empty default for legacy docker build (no buildx); BuildKit fills linux/amd64|arm64.
ARG TARGETPLATFORM=
ARG POSTGRES_VERSION
ARG CLOUDFLARED_VERSION
ARG NGINX_VERSION
ARG COOLIFY_VERSION
ARG CI=true

ENV COOLIFY_VERSION=${COOLIFY_VERSION}

WORKDIR /var/www/html

USER root

# Install patched Nginx from the official nginx.org Alpine repository
RUN set -eux; \
    apk add --no-cache ca-certificates curl; \
    NGINX_ALPINE_VERSION="$(egrep -o '^[0-9]+\.[0-9]+' /etc/alpine-release)"; \
    NGINX_REPOSITORY="https://nginx.org/packages/mainline/alpine/v${NGINX_ALPINE_VERSION}/main"; \
    sed -i 's|http://nginx.org/packages|https://nginx.org/packages|g' /etc/apk/repositories; \
    grep -qxF "@nginx ${NGINX_REPOSITORY}" /etc/apk/repositories || echo "@nginx ${NGINX_REPOSITORY}" >> /etc/apk/repositories; \
    curl -fsSL https://nginx.org/keys/nginx_signing.rsa.pub -o /etc/apk/keys/nginx_signing.rsa.pub; \
    apk add --no-cache --upgrade "nginx@nginx=${NGINX_VERSION}"; \
    rm -f /etc/nginx/nginx.conf /etc/nginx/conf.d/default.conf; \
    nginx -v

RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID --service nginx

# Install PostgreSQL repository and keys
RUN apk add --no-cache gnupg && \
    mkdir -p /usr/share/keyrings && \
    curl -fSsL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor > /usr/share/keyrings/postgresql.gpg

# Install system dependencies
RUN apk upgrade && \
    apk add --no-cache \
    postgresql${POSTGRES_VERSION}-client \
    openssh-client \
    git \
    git-lfs \
    jq \
    lsof \
    vim

# Configure shell aliases
RUN echo "alias ll='ls -al'" >> /etc/profile && \
    echo "alias a='php artisan'" >> /etc/profile && \
    echo "alias logs='tail -f storage/logs/laravel.log'" >> /etc/profile

# Install Cloudflared based on architecture
RUN set -eux; \
    mkdir -p /usr/local/bin; \
    platform="${TARGETPLATFORM}"; \
    if [ -z "${platform}" ]; then \
        case "$(uname -m)" in \
            x86_64|amd64) platform=linux/amd64 ;; \
            aarch64|arm64) platform=linux/arm64 ;; \
            *) echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;; \
        esac; \
    fi; \
    if [ "${platform}" = "linux/amd64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-amd64" -o /usr/local/bin/cloudflared; \
    elif [ "${platform}" = "linux/arm64" ]; then \
        curl -sSL "https://github.com/cloudflare/cloudflared/releases/download/${CLOUDFLARED_VERSION}/cloudflared-linux-arm64" -o /usr/local/bin/cloudflared; \
    else \
        echo "Unsupported TARGETPLATFORM: ${platform}" >&2; \
        exit 1; \
    fi; \
    chmod +x /usr/local/bin/cloudflared

# Configure PHP
COPY docker/production/etc/php/conf.d/zzz-custom-php.ini /usr/local/etc/php/conf.d/zzz-custom-php.ini
ENV PHP_OPCACHE_ENABLE=1

# Configure entrypoint (chmod via RUN: COPY --chmod needs BuildKit)
COPY docker/production/entrypoint.d/ /etc/entrypoint.d
RUN chmod -R 755 /etc/entrypoint.d

# Copy application files from previous stages
COPY --from=base --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=static-assets --chown=www-data:www-data /app/public/build ./public/build

# Copy application source code
COPY --chown=www-data:www-data composer.json composer.lock ./
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data lang ./lang
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data storage ./storage
COPY --chown=www-data:www-data templates ./templates
COPY --chown=www-data:www-data resources/views ./resources/views
COPY --chown=www-data:www-data scripts ./scripts
COPY --chown=www-data:www-data artisan artisan
COPY --chown=www-data:www-data openapi.yaml ./openapi.yaml
COPY --chown=www-data:www-data changelogs/ ./changelogs/

RUN composer dump-autoload && chmod 755 scripts/upgrade-local.sh

# Configure Nginx and S6 overlay
COPY docker/production/etc/nginx/conf.d/custom.conf /etc/nginx/conf.d/custom.conf
COPY docker/production/etc/nginx/site-opts.d/http.conf /etc/nginx/site-opts.d/http.conf
COPY docker/production/etc/s6-overlay/ /etc/s6-overlay/

RUN mkdir -p /etc/nginx/conf.d /etc/s6-overlay/s6-rc.d/user && \
    printf 'bundle\n' > /etc/s6-overlay/s6-rc.d/user/type && \
    chown -R www-data:www-data /etc/nginx && \
    chmod -R 755 /etc/nginx /etc/s6-overlay

# Install MinIO client
COPY --from=minio-client /usr/bin/mc /usr/bin/mc
RUN chmod +x /usr/bin/mc

# Stay as root for s6 init. PHP-FPM/nginx still run as www-data via their
# service configs. USER www-data here makes s6-rc fail with
# "can't create /etc/s6-overlay/s6-rc.d/user/type: Permission denied".
USER root
