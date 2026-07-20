#!/bin/sh
set -e

if [ "$APP_ENV" = "production" ]; then
    composer install --no-dev --no-interaction --optimize-autoloader
else
    composer install --no-interaction
fi

chown -R www-data:www-data /var/www/html

exec "$@"