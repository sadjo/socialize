<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// ParaTest : une base SQLite par processus (TEST_TOKEN) ; MariaDB passe par dbname_suffix (config/packages/doctrine.yaml).
$token = getenv('TEST_TOKEN');
$url = $_SERVER['DATABASE_URL'] ?? '';
if (false !== $token && '' !== $token && is_string($url) && str_starts_with($url, 'sqlite:') && str_ends_with($url, '.db')) {
    $url = substr($url, 0, -3).'_'.$token.'.db';
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;
    putenv('DATABASE_URL='.$url);
}
