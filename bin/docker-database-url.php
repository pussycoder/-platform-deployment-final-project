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

function isUnresolvedTemplate(?string $value): bool
{
    return $value !== null && $value !== '' && (str_contains($value, '${{') || str_contains($value, '${'));
}

function isUsableUrl(?string $url, bool $onRailway, bool $strictHost = true): bool
{
    if ($url === null || $url === '') {
        return false;
    }

    if (isUnresolvedTemplate($url) || str_contains($url, 'change_me')) {
        return false;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    if ($strictHost && $onRailway && in_array($parts['host'], ['localhost', '127.0.0.1', 'db'], true)) {
        return false;
    }

    $database = trim($parts['path'] ?? '', '/');
    if ($database === '' || !isset($parts['user']) || $parts['user'] === '') {
        return false;
    }

    return true;
}

function appendParams(string $url): string
{
    if (!str_contains($url, 'serverVersion=')) {
        $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion=8.0.32&charset=utf8mb4';
    }

    if (!str_contains($url, 'connect_timeout=')) {
        $url .= '&connect_timeout=5';
    }

    return $url;
}

$onRailway = (bool) (getenv('RAILWAY_ENVIRONMENT') ?: getenv('RAILWAY_PROJECT_ID') ?: getenv('RAILWAY_SERVICE_ID'));

fwrite(STDERR, "=== Database environment check ===\n");
foreach (
    [
        'MYSQLHOST', 'MYSQL_HOST', 'MYSQLPORT', 'MYSQL_PORT',
        'MYSQLUSER', 'MYSQL_USER', 'MYSQLPASSWORD', 'MYSQL_PASSWORD',
        'MYSQLDATABASE', 'MYSQL_DATABASE', 'MYSQL_URL', 'MYSQL_PUBLIC_URL', 'DATABASE_URL',
    ] as $name
) {
    log_env($name);
}

$url = '';

// 1) Railway MySQL references (MYSQLHOST, not MYSQL_HOST=db from docker-compose)
$host = strip_quotes(getenv('MYSQLHOST') ?: '');
$user = strip_quotes(getenv('MYSQLUSER') ?: '');
$pass = strip_quotes(getenv('MYSQLPASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '') ?? '';
$db = strip_quotes(getenv('MYSQLDATABASE') ?: '');
$port = strip_quotes(getenv('MYSQLPORT') ?: '3306') ?? '3306';

if ($onRailway && $host === '') {
    $dockerHost = strip_quotes(getenv('MYSQL_HOST') ?: '');
    if ($dockerHost === 'db') {
        fwrite(STDERR, "WARNING: Remove MYSQL_HOST=db from Railway app variables (Docker Compose only).\n");
    }
}

if ($host && $user && $db && !isUnresolvedTemplate($host)) {
    $url = buildUrl($host, $user, $pass, $db, $port);
    fwrite(STDERR, "Built DATABASE_URL from MYSQLHOST/MYSQLUSER references.\n");
}

// 2) Full URL references from MySQL service
if ($url === '') {
    foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL'] as $var) {
        $candidate = strip_quotes(getenv($var) ?: '');
        if (isUsableUrl($candidate, $onRailway)) {
            $url = $candidate;
            fwrite(STDERR, sprintf("Using %s for database connection.\n", $var));
            break;
        }
    }
}

// 3) Public TCP URL (works when mysql.railway.internal private DNS times out)
if ($url === '' && $onRailway) {
    $public = strip_quotes(getenv('MYSQL_PUBLIC_URL') ?: '');
    if (isUsableUrl($public, $onRailway, false)) {
        $url = $public;
        fwrite(STDERR, "Using MYSQL_PUBLIC_URL for database connection.\n");
    }
}

// 4) Legacy DATABASE_URL on app (skip known-bad docker-compose host "db")
if ($url === '') {
    $candidate = strip_quotes(getenv('DATABASE_URL') ?: '');
    if (isUsableUrl($candidate, $onRailway)) {
        $url = $candidate;
        fwrite(STDERR, "Using DATABASE_URL for database connection.\n");
    }
}

if ($url === '') {
    fwrite(STDERR, "\nERROR: No valid database configuration.\n");
    if ($onRailway) {
        fwrite(STDERR, "On Railway APP service: delete MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE, MYSQL_PORT.\n");
        fwrite(STDERR, "Then add ONE variable reference from MySQL service:\n");
        fwrite(STDERR, "  DATABASE_URL → MYSQL_PUBLIC_URL  (recommended if private network times out)\n");
        fwrite(STDERR, "  OR DATABASE_URL → MYSQL_URL\n");
        fwrite(STDERR, "  OR references: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE\n");
    }
    exit(1);
}

$url = appendParams($url);

$parts = parse_url($url);
fwrite(STDERR, sprintf("Database host: %s (port %s)\n", $parts['host'] ?? 'unknown', $parts['port'] ?? '3306'));
fwrite(STDERR, "==================================\n");

echo $url;
