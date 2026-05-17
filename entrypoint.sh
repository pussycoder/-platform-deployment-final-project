#!/bin/bash
set -e

# Railway injects PORT; default to 80 for local Docker
export PORT="${PORT:-80}"

export DATABASE_URL="$(php /app/bin/docker-database-url.php)"

DB_HOST="$(php -r 'echo parse_url(getenv("DATABASE_URL"), PHP_URL_HOST) ?: "unknown";')"
echo "Database host: ${DB_HOST}"

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx on port ${PORT}..."
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
