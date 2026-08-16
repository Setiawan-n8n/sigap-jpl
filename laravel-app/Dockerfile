FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer config policy.advisories.block false && composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs

FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libsqlite3-dev libzip-dev libonig-dev \
    && docker-php-ext-install pdo pdo_sqlite mbstring zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY docker/php.ini /usr/local/etc/php/conf.d/99-sigap-jpl.ini
COPY --from=vendor /usr/bin/composer /usr/bin/composer

RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
       storage/logs storage/app/videos/results/snapshots database bootstrap/cache \
    && touch database/database.sqlite \
    && chmod -R 775 storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["entrypoint.sh"]
CMD ["serve"]
