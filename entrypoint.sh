#!/bin/bash
set -e

export PORT="${PORT:-80}"
export APP_ENV="${APP_ENV:-prod}"
export APP_DEBUG="${APP_DEBUG:-0}"

# DEFAULT_URI for Symfony router (Railway sets RAILWAY_PUBLIC_DOMAIN)
if [ -z "$DEFAULT_URI" ] && [ -n "$RAILWAY_PUBLIC_DOMAIN" ]; then
  export DEFAULT_URI="https://${RAILWAY_PUBLIC_DOMAIN}"
fi
export DEFAULT_URI="${DEFAULT_URI:-http://localhost}"

# Build DATABASE_URL (debug info goes to stderr → Railway logs)
export DATABASE_URL="$(php /app/bin/docker-database-url.php)"

php /app/bin/write-env-local.php

echo "Clearing Symfony cache for production..."
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

echo "Running database migrations (with retries)..."
for i in $(seq 1 30); do
  if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
    echo "Migrations complete."
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "ERROR: Could not run migrations after 30 attempts."
    exit 1
  fi
  echo "Database not ready yet, retrying ($i/30)..."
  sleep 3
done

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx on port ${PORT}..."
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
