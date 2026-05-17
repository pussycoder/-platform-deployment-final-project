<?php

declare(strict_types=1);

function strip_quotes(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return trim($value, " \t\n\r\"'");
}

function log_env(string $name): void
{
    $value = getenv($name);
    if ($value === false) {
        fwrite(STDERR, sprintf("  %s = (not set)\n", $name));

        return;
    }

    if (str_contains(strtoupper($name), 'PASSWORD') || str_contains(strtoupper($name), 'SECRET')) {
        fwrite(STDERR, sprintf("  %s = ***\n", $name));

        return;
    }

    fwrite(STDERR, sprintf("  %s = %s\n", $name, $value));
}

function buildUrl(string $host, string $user, string $pass, string $db, string $port = '3306'): string
{
    if ($host === 'localhost') {
        $host = '127.0.0.1';
    }

    return sprintf(
        'mysql://%s:%s@%s:%s/%s?serverVersion=8.0.32&charset=utf8mb4',
        rawurlencode($user),
        rawurlencode($pass),
        $host,
        $port,
        $db
    );
}

function isUsableUrl(?string $url, bool $onRailway): bool
{
    if ($url === null || $url === '') {
        return false;
    }

    if (str_contains($url, '${') || str_contains($url, 'change_me')) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    if ($onRailway && in_array($parts['host'], ['localhost', '127.0.0.1', 'db'], true)) {
        return false;
    }

    // Reject empty user/password/database (e.g. mysql://:@host:3306/)
    $database = trim($parts['path'] ?? '', '/');
    if ($database === '' || !isset($parts['user']) || $parts['user'] === '') {
        return false;
    }

    return true;
}

$onRailway = (bool) (getenv('RAILWAY_ENVIRONMENT') ?: getenv('RAILWAY_PROJECT_ID') ?: getenv('RAILWAY_SERVICE_ID'));

fwrite(STDERR, "=== Database environment check ===\n");
foreach (
    [
        'MYSQLHOST', 'MYSQL_HOST', 'MYSQLPORT', 'MYSQL_PORT',
        'MYSQLUSER', 'MYSQL_USER', 'MYSQLPASSWORD', 'MYSQL_PASSWORD',
        'MYSQLDATABASE', 'MYSQL_DATABASE', 'MYSQL_URL', 'DATABASE_URL',
    ] as $name
) {
    log_env($name);
}

$url = '';

$host = strip_quotes(getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: '');
$user = strip_quotes(getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: '');
$pass = strip_quotes(getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '') ?? '';
$db = strip_quotes(getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: '');
$port = strip_quotes(getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306') ?? '3306';

// On Railway, never use docker-compose hostname "db"
if ($onRailway && $host === 'db') {
    fwrite(STDERR, "WARNING: MYSQL_HOST=db is for Docker Compose only. Ignoring on Railway.\n");
    $host = '';
}

if ($host && $user && $db) {
    $url = buildUrl($host, $user, $pass, $db, $port);
    fwrite(STDERR, "Built DATABASE_URL from MySQL variables.\n");
}

if ($url === '') {
    foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL', 'DATABASE_URL'] as $var) {
        $candidate = strip_quotes(getenv($var) ?: '');
        if (isUsableUrl($candidate, $onRailway)) {
            $url = $candidate;
            fwrite(STDERR, sprintf("Using %s for database connection.\n", $var));
            break;
        }
    }
}

if ($url === '') {
    fwrite(STDERR, "\nERROR: DATABASE_URL is invalid or incomplete.\n");
    fwrite(STDERR, "Your URL looks like: mysql://:@host:3306/  (missing user, password, database).\n");
    if ($onRailway) {
        fwrite(STDERR, "\nRailway fix:\n");
        fwrite(STDERR, "  1. DELETE the manual DATABASE_URL on your app service.\n");
        fwrite(STDERR, "  2. Open MySQL service → Variables → copy MYSQL_URL (full mysql://... string).\n");
        fwrite(STDERR, "  3. App service → New variable DATABASE_URL → paste that value exactly.\n");
        fwrite(STDERR, "  OR add references: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE\n");
    }
    exit(1);
}

if (!str_contains($url, 'serverVersion=')) {
    $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion=8.0.32&charset=utf8mb4';
}

$parts = parse_url($url);
fwrite(STDERR, sprintf("Database host: %s\n", $parts['host'] ?? 'unknown'));
fwrite(STDERR, "==================================\n");

echo $url;
