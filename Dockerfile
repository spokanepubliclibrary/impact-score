# ─────────────────────────────────────────────────────────────────────────────
# Impact Score Dashboard — Web image
#
# Single-stage build with Composer installed in the php:8.2-apache image.
# This avoids the platform requirement mismatch where composer:2 lacks extensions
# required by composer.json (mysqli).
#
# The image is self-contained: no source bind-mounts are required at runtime
# (those live in docker-compose.override.yml for dev).
# ─────────────────────────────────────────────────────────────────────────────

# ── Runtime stage with Composer ──────────────────────────────────────────────
FROM php:8.2-apache

# MySQLi + dependencies for Composer + curl for healthcheck.
# Installing mysqli BEFORE composer ensures platform requirements are met.
RUN docker-php-ext-install mysqli \
    && a2enmod rewrite headers \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# ── Application source ───────────────────────────────────────────────────────
# Flat repo layout: PHP files live at the repo root. .dockerignore excludes
# build artifacts, secrets, db/, and the bundled phpMyAdmin tree if any.
COPY . .

# Install dependencies with Composer. Now running in php:8.2-apache, so
# platform requirements (mysqli) are already available.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader \
    && composer clear-cache

# Docker-specific DB credentials shim (env-driven). Replaces the placeholder
# committed at secure/db_connection.php.
COPY docker/db_connection.php ./secure/db_connection.php

# ── Apache config ────────────────────────────────────────────────────────────
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# ── Filesystem layout + permissions ──────────────────────────────────────────
# `touch .env` ensures the bind-mount target file exists in the image. Without
# it, Docker creates `/var/www/html/.env` as a *directory* on first run, and
# on macOS with virtiofs the nested `./env:/var/www/html/.env:ro` bind fails
# with "mountpoint is outside rootfs".
RUN mkdir -p logs uploads \
    && touch .env \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod 775 uploads logs

# ── Healthcheck ──────────────────────────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/ >/dev/null || exit 1

EXPOSE 80
