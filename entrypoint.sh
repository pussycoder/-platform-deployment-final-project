#!/bin/bash
set -e

# Railway injects PORT; default to 80 for local Docker
export PORT="${PORT:-80}"

# Build DATABASE_URL from Railway MySQL service variables when not set directly
if [ -z "$DATABASE_URL" ] && [ -n "$MYSQLHOST" ]; then
  export DATABASE_URL="mysql://${MYSQLUSER}:${MYSQLPASSWORD}@${MYSQLHOST}:${MYSQLPORT:-3306}/${MYSQLDATABASE}?serverVersion=8.0.32&charset=utf8mb4"
  echo "Built DATABASE_URL from MySQL service variables."
elif [ -z "$DATABASE_URL" ] && [ -n "$MYSQL_URL" ]; then
  export DATABASE_URL="$MYSQL_URL"
  echo "Using MYSQL_URL as DATABASE_URL."
fi

if [ -n "$DATABASE_URL" ]; then
  echo "Running database migrations..."
  php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
else
  echo "ERROR: DATABASE_URL is not set."
  echo "In Railway: App service → Variables → add references from your MySQL service"
  echo "  (MYSQLHOST, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE, MYSQLPORT)"
  echo "  OR set DATABASE_URL directly."
  exit 1
fi

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx on port ${PORT}..."
# Use Railway's PORT when provided
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
