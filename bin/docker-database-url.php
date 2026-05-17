<?php

declare(strict_types=1);

function strip_quotes(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    return trim($value, " \t\n\r\"'");
}

$url = strip_quotes(getenv('DATABASE_URL') ?: '');

// Reject unexpanded .env/docker-compose templates and placeholder values
if ($url !== '' && (str_contains($url, '${') || str_contains($url, 'change_me'))) {
    $url = '';
}

if ($url === '') {
    $url = strip_quotes(getenv('MYSQL_URL') ?: '');
}

if ($url === '') {
    $host = strip_quotes(getenv('MYSQLHOST') ?: getenv('MYSQL_HOST') ?: '');
    $user = strip_quotes(getenv('MYSQLUSER') ?: getenv('MYSQL_USER') ?: '');
    $pass = strip_quotes(getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '');
    $db = strip_quotes(getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: '');
    $port = strip_quotes(getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: '3306');

    if ($host && $user && $db) {
        $url = sprintf(
            'mysql://%s:%s@%s:%s/%s?serverVersion=8.0.32&charset=utf8mb4',
            rawurlencode($user),
            rawurlencode($pass ?? ''),
            $host,
            $port,
            $db
        );
    }
}

if ($url === '') {
    fwrite(STDERR, "ERROR: Could not build DATABASE_URL. Add MySQL variable references on Railway.\n");
    exit(1);
}

if (!str_contains($url, 'serverVersion=')) {
    $url .= (str_contains($url, '?') ? '&' : '?').'serverVersion=8.0.32&charset=utf8mb4';
}

$parts = parse_url($url);
if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
    fwrite(STDERR, "ERROR: Malformed DATABASE_URL. Remove manual DATABASE_URL from Railway and use MySQL references only.\n");
    exit(1);
}

echo $url;
