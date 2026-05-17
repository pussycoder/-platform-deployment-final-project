#!/bin/bash
set -e

export PORT="${PORT:-80}"

# stderr from the script logs the resolved host; stdout is the URL only
export DATABASE_URL="$(php /app/bin/docker-database-url.php)"

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx on port ${PORT}..."
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
