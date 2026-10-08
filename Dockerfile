# syntax=docker/dockerfile:1

FROM php:8.5-apache AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev libonig-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pdo_pgsql zip \
    && a2enmod rewrite setenvif \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM php-base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/views \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

FROM node:24-bookworm-slim AS assets

WORKDIR /app
COPY --from=dependencies /var/www/html /app
# The project currently has no npm lockfile.
RUN npm install --package-lock=false --no-audit --no-fund \
    && npm run build

FROM php-base AS production

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=pgsql \
    SESSION_DRIVER=file \
    SESSION_ENCRYPT=true \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=file \
    QUEUE_CONNECTION=sync \
    PORT=10000

COPY --from=dependencies /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN <<'SH'
set -eu
cat > /etc/apache2/sites-available/000-default.conf <<'APACHE'
<VirtualHost *:${PORT}>
    ServerName localhost
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    # Render terminates TLS before forwarding requests to this container.
    SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on
    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
APACHE
cat > /etc/apache2/ports.conf <<'APACHE'
Listen ${PORT}
APACHE
cat > /usr/local/bin/start-app <<'ENTRYPOINT'
#!/bin/sh
set -eu
: "${APP_KEY:?Set APP_KEY in the Render environment variables}"
mkdir -p storage/framework/cache/data storage/framework/cache/locks \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
ENTRYPOINT
chmod +x /usr/local/bin/start-app
SH

EXPOSE 10000

CMD ["/usr/local/bin/start-app"]
