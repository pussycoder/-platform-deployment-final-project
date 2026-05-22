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

function isUsableUrl(?string $url, bool $strictHost = true): bool
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

    if ($strictHost && in_array($parts['host'], ['localhost', '127.0.0.1', 'db'], true)) {
        return false;
    }

    $database = trim($parts['path'] ?? '', '/');
    if ($database === '' || !isset($parts['user']) || $parts['user'] === '') {
        return false;
    }

    return true;
}

function isBrokenRailwayPrivateHost(?string $url): bool
{
    $parts = parse_url($url ?: '');

    return is_array($parts) && ($parts['host'] ?? '') === 'mysql.railway.internal';
}

function appendParams(string $url): string
{
    if (!str_contains($url, 'serverVersion=')) {
        $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion=8.0.32&charset=utf8mb4';
    }

    if (!str_contains($url, 'connect_timeout=')) {
        $url .= '&connect_timeout=10';
    }

    return $url;
}

$onRailway = (bool) (getenv('RAILWAY_ENVIRONMENT') ?: getenv('RAILWAY_PROJECT_ID') ?: getenv('RAILWAY_SERVICE_ID'));

fwrite(STDERR, "=== Database environment check ===\n");
foreach (
    [
        'MYSQLHOST', 'MYSQL_HOST', 'MYSQLPORT', 'MYSQL_PORT',
        'MYSQLUSER', 'MYSQL_USER', 'MYSQLPASSWORD', 'MYSQL_PASSWORD',
        'MYSQLDATABASE', 'MYSQL_DATABASE',
        'MYSQL_URL', 'MYSQL_PUBLIC_URL', 'DATABASE_URL',
        'RAILWAY_TCP_PROXY_DOMAIN', 'RAILWAY_TCP_PROXY_PORT',
    ] as $name
) {
    log_env($name);
}

$url = '';
$user = strip_quotes(getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: 'root') ?? 'root';
$pass = strip_quotes(getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: getenv('MYSQL_ROOT_PASSWORD') ?: '') ?? '';
$db = strip_quotes(getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'railway') ?? 'railway';

if ($onRailway) {
    if (strip_quotes(getenv('MYSQL_HOST') ?: '') === 'db') {
        fwrite(STDERR, "WARNING: Delete MYSQL_HOST=db from Railway app (Docker Compose only).\n");
    }

    // Railway: public TCP first (private mysql.railway.internal often times out)
    $public = strip_quotes(getenv('MYSQL_PUBLIC_URL') ?: '');
    if (isUsableUrl($public, false)) {
        $url = $public;
        fwrite(STDERR, "Using MYSQL_PUBLIC_URL.\n");
    }

    if ($url === '') {
        $tcpHost = strip_quotes(getenv('RAILWAY_TCP_PROXY_DOMAIN') ?: '');
        $tcpPort = strip_quotes(getenv('RAILWAY_TCP_PROXY_PORT') ?: '') ?? '3306';
        if ($tcpHost && $user && $db && !isUnresolvedTemplate($tcpHost)) {
            $url = buildUrl($tcpHost, $user, $pass, $db, $tcpPort);
            fwrite(STDERR, "Built DATABASE_URL from RAILWAY_TCP_PROXY_DOMAIN.\n");
        }
    }

    if ($url === '') {
        foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL'] as $var) {
            $candidate = strip_quotes(getenv($var) ?: '');
            if (isUsableUrl($candidate) && !isBrokenRailwayPrivateHost($candidate)) {
                $url = $candidate;
                fwrite(STDERR, sprintf("Using %s.\n", $var));
                break;
            }
        }
    }

    $host = strip_quotes(getenv('MYSQLHOST') ?: '');
    $port = strip_quotes(getenv('MYSQLPORT') ?: '3306') ?? '3306';
    if ($url === '' && $host && $user && $db && !isUnresolvedTemplate($host)) {
        $url = buildUrl($host, $user, $pass, $db, $port);
        fwrite(STDERR, "Built DATABASE_URL from MYSQLHOST reference.\n");
    }

    if ($url === '') {
        $legacy = strip_quotes(getenv('DATABASE_URL') ?: '');
        if (isUsableUrl($legacy) && !isBrokenRailwayPrivateHost($legacy)) {
            $url = $legacy;
            fwrite(STDERR, "Using DATABASE_URL.\n");
        } elseif (isBrokenRailwayPrivateHost($legacy)) {
            fwrite(STDERR, "ERROR: DATABASE_URL uses mysql.railway.internal (times out).\n");
            fwrite(STDERR, "Replace it with a reference to MySQL → MYSQL_PUBLIC_URL on the app service.\n");
        }
    }
} else {
    // Local Docker Compose
    $host = strip_quotes(getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: 'db') ?? 'db';
    $port = strip_quotes(getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306') ?? '3306';
    if ($host && $user && $db) {
        $url = buildUrl($host, $user, $pass, $db, $port);
        fwrite(STDERR, "Built DATABASE_URL for local Docker.\n");
    }

    if ($url === '') {
        $legacy = strip_quotes(getenv('DATABASE_URL') ?: '');
        if (isUsableUrl($legacy)) {
            $url = $legacy;
            fwrite(STDERR, "Using DATABASE_URL.\n");
        }
    }
}

if ($url === '') {
    fwrite(STDERR, "\nERROR: No valid database configuration.\n");
    exit(1);
}

$url = appendParams($url);
$parts = parse_url($url);
fwrite(STDERR, sprintf("Final DB host: %s (port %s)\n", $parts['host'] ?? '?', $parts['port'] ?? '?'));
fwrite(STDERR, "==================================\n");

echo $url;
