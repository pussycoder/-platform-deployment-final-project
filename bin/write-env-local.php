<?php

declare(strict_types=1);

$databaseUrl = getenv('DATABASE_URL') ?: '';
$defaultUri = getenv('DEFAULT_URI') ?: '';
$railwayDomain = getenv('RAILWAY_PUBLIC_DOMAIN') ?: '';

if ($defaultUri === '' && $railwayDomain !== '') {
    $defaultUri = 'https://'.$railwayDomain;
}

if ($defaultUri === '') {
    $defaultUri = 'http://localhost';
}

$appSecret = getenv('APP_SECRET') ?: '';
if ($appSecret === '') {
    fwrite(STDERR, "WARNING: APP_SECRET is not set.\n");
}

$lines = [
    'APP_ENV='.(getenv('APP_ENV') ?: 'prod'),
    'APP_DEBUG='.(getenv('APP_DEBUG') ?: '0'),
    'APP_SECRET='.var_export($appSecret, true),
    'DATABASE_URL='.var_export($databaseUrl, true),
    'DEFAULT_URI='.var_export($defaultUri, true),
];

file_put_contents('/app/.env.local', implode(PHP_EOL, $lines).PHP_EOL);

fwrite(STDERR, 'Wrote /app/.env.local (DEFAULT_URI='.$defaultUri.").\n");
