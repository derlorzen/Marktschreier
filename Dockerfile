# Marktschreier: ein Image für Web, Queue-Worker und Scheduler.
FROM serversideup/php:8.4-fpm-nginx AS base

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    LOG_CHANNEL=stderr \
    SSL_MODE=off

WORKDIR /var/www/html

# Abhängigkeiten zuerst, damit der Layer-Cache bei Codeänderungen greift.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && rm -rf tests storage/logs/*.log database/database.sqlite

USER www-data
