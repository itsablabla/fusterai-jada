# syntax=docker/dockerfile:1.7
# FusterAI production image for Railway (and any Docker host).
# Multi-stage: composer deps -> vite build -> php-fpm + nginx runtime with supervisord.

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
ARG REVERB_APP_KEY=fusterai-key
ENV VITE_REVERB_APP_KEY=$REVERB_APP_KEY
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ---- 3. Runtime: PHP 8.4 FPM + Nginx + Supervisor -------------------------
FROM php:8.4-fpm-bookworm AS runtime

# Extensions matching upstream Sail image (imap omitted — webklex/php-imap is pure PHP)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
      pdo_pgsql pgsql \
      redis \
      gd intl zip bcmath \
      pcntl opcache exif

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx supervisor postgresql-client tini gosu curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# App code + optimized vendor autoload; built frontend assets
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

# Runtime config
COPY docker/railway/nginx.conf         /etc/nginx/nginx.conf
COPY docker/railway/supervisord.conf   /etc/supervisor/conf.d/fusterai.conf
COPY docker/railway/php.ini            /usr/local/etc/php/conf.d/99-fusterai.ini
COPY docker/railway/php-fpm.conf       /usr/local/etc/php-fpm.d/zz-fusterai.conf
COPY docker/railway/entrypoint.sh      /usr/local/bin/fusterai-entrypoint

RUN chmod +x /usr/local/bin/fusterai-entrypoint \
 && mkdir -p storage/app/public storage/app/private \
             storage/framework/cache/data storage/framework/sessions storage/framework/views \
             storage/logs bootstrap/cache \
             /run/php /var/log/nginx /var/lib/nginx/body /var/lib/nginx/proxy /var/lib/nginx/fastcgi \
 && cp -R storage /var/www/storage-skeleton \
 && chown -R www-data:www-data /var/www/html /var/www/storage-skeleton /run/php /var/log/nginx /var/lib/nginx

ENV PORT=8000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_MEMORY_LIMIT=512M

EXPOSE 8000
ENTRYPOINT ["/usr/bin/tini","--","fusterai-entrypoint"]
