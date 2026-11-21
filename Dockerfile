# The production image: FrankenPHP (PHP + Caddy, automatic HTTPS) serving the app.
# Secrets are not in the image: compose.prod.yaml mounts .env.prod.local at run time.
FROM dunglas/frankenphp:1-php8.5-trixie

WORKDIR /app

RUN install-php-extensions apcu intl opcache pdo_sqlite zip

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, for the layer cache.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console importmap:install \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && php bin/console cache:clear \
    && mkdir -p var/data \
    && chown -R www-data:www-data var
