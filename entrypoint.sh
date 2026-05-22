#!/bin/bash
set -e

export PORT="${PORT:-80}"
export APP_ENV="${APP_ENV:-prod}"
export APP_DEBUG="${APP_DEBUG:-0}"

if [ -z "$DEFAULT_URI" ] && [ -n "$RAILWAY_PUBLIC_DOMAIN" ]; then
  export DEFAULT_URI="https://${RAILWAY_PUBLIC_DOMAIN}"
fi
export DEFAULT_URI="${DEFAULT_URI:-http://localhost}"

export DATABASE_URL="$(php /app/bin/docker-database-url.php)"

php /app/bin/write-env-local.php

mkdir -p /app/var/cache /app/var/log
chown www-data:www-data /app/.env.local
chown -R www-data:www-data /app/var
chmod -R 775 /app/var

echo "Clearing Symfony cache for production..."
su -s /bin/sh www-data -c "php bin/console cache:clear --env=prod --no-warmup"
su -s /bin/sh www-data -c "php bin/console cache:warmup --env=prod"
chown -R www-data:www-data /app/var

echo "Starting PHP-FPM..."
php-fpm -D

# Run migrations in background so Nginx can start immediately (avoids 502 while DB warms up)
(
  for i in $(seq 1 15); do
    if su -s /bin/sh www-data -c "php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration"; then
      echo "Migrations complete."
      exit 0
    fi
    echo "Database not ready, retry ($i/15)..."
    sleep 5
  done
  echo "WARNING: Migrations did not run. Check MySQL is Online and DATABASE_URL is a MySQL reference."
) &

echo "Starting Nginx on port ${PORT}..."
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
