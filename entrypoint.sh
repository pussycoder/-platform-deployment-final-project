#!/bin/bash
set -e

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx..."
exec nginx -g "daemon off;"