#!/bin/bash
set -e

export PORT="${PORT:-80}"
export APP_ENV="${APP_ENV:-prod}"
export APP_DEBUG="${APP_DEBUG:-0}"

# Build DATABASE_URL (debug info goes to stderr → Railway logs)
export DATABASE_URL="$(php /app/bin/docker-database-url.php)"

# Symfony must see DATABASE_URL at runtime (prod cache is built without it)
php -r 'file_put_contents("/app/.env.local", "DATABASE_URL=".var_export(getenv("DATABASE_URL"), true).PHP_EOL);'

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
