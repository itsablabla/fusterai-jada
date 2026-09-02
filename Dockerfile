# syntax=docker/dockerfile:1.7
# FusterAI production image for Railway (also works on Fly, Coolify, plain Docker).
# Multi-stage: PHP deps -> frontend build -> FrankenPHP runtime with supervisord.

# ---- 1. Composer install (no dev, no scripts) -----------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- 2. Frontend build ----------------------------------------------------
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# Vite reads REVERB APP_KEY at build time; hostname/port/scheme fall back to
# window.location so the same image works on any Railway domain.
ARG REVERB_APP_KEY=fusterai-key
ENV VITE_REVERB_APP_KEY=$REVERB_APP_KEY
# Copy vendor so vite-module-loader (which reads Modules/) has its Laravel bits
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime -----------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS runtime

# System deps + PHP extensions matching upstream Sail image (minus imap; webklex/php-imap is pure PHP)
RUN install-php-extensions \
      pdo_pgsql pgsql \
      redis \
      gd intl zip bcmath \
      pcntl opcache exif

RUN apt-get update \
 && apt-get install -y --no-install-recommends supervisor postgresql-client tini gosu \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# App code + optimized vendor autoload
COPY --from=vendor /app /var/www/html
# Built frontend assets
COPY --from=assets /app/public/build /var/www/html/public/build

# Runtime config
COPY docker/railway/Caddyfile         /etc/caddy/Caddyfile
COPY docker/railway/supervisord.conf  /etc/supervisor/conf.d/fusterai.conf
COPY docker/railway/php.ini           /usr/local/etc/php/conf.d/99-fusterai.ini
COPY docker/railway/entrypoint.sh     /usr/local/bin/fusterai-entrypoint

# Framework dirs (missing from upstream) + storage skeleton preserved for volume boot
RUN chmod +x /usr/local/bin/fusterai-entrypoint \
 && mkdir -p storage/app/public storage/app/private \
             storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache \
 && cp -R storage /var/www/storage-skeleton \
 && chown -R www-data:www-data /var/www/html /var/www/storage-skeleton

ENV PORT=8000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    OCTANE_SERVER=frankenphp

EXPOSE 8000
ENTRYPOINT ["/usr/bin/tini","--","fusterai-entrypoint"]
