#!/bin/bash
set -e

# Railway injects PORT; default to 80 for local Docker
export PORT="${PORT:-80}"

# Build a valid DATABASE_URL (handles quotes, special chars in password, Railway vars)
export DATABASE_URL="$(php -r '
function strip_quotes(?string $v): ?string {
    if ($v === null || $v === "") {
        return null;
    }
    return trim($v, " \t\n\r\"'");
}

$url = strip_quotes(getenv("DATABASE_URL") ?: "");

// Reject unexpanded .env/docker-compose templates and placeholder values
if ($url !== "" && (str_contains($url, "${") || str_contains($url, "change_me"))) {
    $url = "";
}

if ($url === "") {
    $url = strip_quotes(getenv("MYSQL_URL") ?: "");
}

if ($url === "") {
    $host = strip_quotes(getenv("MYSQLHOST") ?: getenv("MYSQL_HOST") ?: "");
    $user = strip_quotes(getenv("MYSQLUSER") ?: getenv("MYSQL_USER") ?: "");
    $pass = strip_quotes(getenv("MYSQLPASSWORD") ?: getenv("MYSQL_PASSWORD") ?: "");
    $db   = strip_quotes(getenv("MYSQLDATABASE") ?: getenv("MYSQL_DATABASE") ?: "");
    $port = strip_quotes(getenv("MYSQLPORT") ?: getenv("MYSQL_PORT") ?: "3306");

    if ($host && $user && $db) {
        $url = sprintf(
            "mysql://%s:%s@%s:%s/%s?serverVersion=8.0.32&charset=utf8mb4",
            rawurlencode($user),
            rawurlencode($pass ?? ""),
            $host,
            $port,
            $db
        );
    }
}

if ($url === "") {
    fwrite(STDERR, "ERROR: Could not build DATABASE_URL. Add MySQL variable references on Railway.\n");
    exit(1);
}

if (!str_contains($url, "serverVersion=")) {
    $url .= (str_contains($url, "?") ? "&" : "?") . "serverVersion=8.0.32&charset=utf8mb4";
}

$parts = parse_url($url);
if ($parts === false || empty($parts["scheme"]) || empty($parts["host"])) {
    fwrite(STDERR, "ERROR: Malformed DATABASE_URL. Remove manual DATABASE_URL from Railway and use MySQL references only.\n");
    exit(1);
}

echo $url;
')"

echo "Database host: $(php -r '$p = parse_url(getenv("DATABASE_URL")); echo $p["host"] ?? "unknown";')"

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx on port ${PORT}..."
if [ "$PORT" != "80" ]; then
  sed -i "s/listen 80;/listen ${PORT};/" /etc/nginx/conf.d/default.conf
fi

exec nginx -g "daemon off;"
