<?php

declare(strict_types=1);

function strip_quotes(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return trim($value, " \t\n\r\"'");
}

function buildUrl(string $host, string $user, string $pass, string $db, string $port = '3306'): string
{
    // Use 127.0.0.1 instead of localhost so PDO uses TCP, not a Unix socket
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

function isInvalidUrl(?string $url): bool
{
    if ($url === null || $url === '') {
        return true;
    }

    if (str_contains($url, '${') || str_contains($url, 'change_me')) {
        return true;
    }

    $parts = parse_url($url);

    return $parts === false
        || empty($parts['scheme'])
        || empty($parts['host'])
        || in_array($parts['host'], ['localhost', 'db'], true);
}

$url = '';

// Prefer individual MySQL variables (Railway references or docker-compose .env)
$host = strip_quotes(getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: '');
$user = strip_quotes(getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: '');
$pass = strip_quotes(getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '') ?? '';
$db = strip_quotes(getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: '');
$port = strip_quotes(getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306') ?? '3306';

if ($host && $user && $db) {
    $url = buildUrl($host, $user, $pass, $db, $port);
    fwrite(STDERR, "Built DATABASE_URL from MySQL variables.\n");
}

// Fall back to Railway-provided full URL
if ($url === '') {
    foreach (['MYSQL_URL', 'MYSQL_PRIVATE_URL', 'DATABASE_URL'] as $var) {
        $candidate = strip_quotes(getenv($var) ?: '');
        if (!isInvalidUrl($candidate)) {
            $url = $candidate;
            fwrite(STDERR, sprintf("Using %s for database connection.\n", $var));
            break;
        }
    }
}

if ($url === '') {
    fwrite(STDERR, "ERROR: No valid database configuration found.\n");
    fwrite(STDERR, "On Railway app service, add REFERENCES from MySQL: MYSQLHOST, MYSQLPORT, MYSQLUSER, MYSQLPASSWORD, MYSQLDATABASE\n");
    fwrite(STDERR, "Remove any manual DATABASE_URL that uses localhost, db, or \${...} placeholders.\n");
    exit(1);
}

if (!str_contains($url, 'serverVersion=')) {
    $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion=8.0.32&charset=utf8mb4';
}

$parts = parse_url($url);
if ($parts === false || empty($parts['host'])) {
    fwrite(STDERR, "ERROR: Malformed DATABASE_URL after build.\n");
    exit(1);
}

fwrite(STDERR, sprintf("Database host: %s\n", $parts['host']));

echo $url;
