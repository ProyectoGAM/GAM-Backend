<?php

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$testEnvironment = [
    'APP_ENV' => 'testing',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($testEnvironment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');

if (! is_string($database) || $database === '') {
    foreach (['.env.testing', '.env'] as $environmentFile) {
        $environmentPath = $basePath.'/'.$environmentFile;

        if (! is_file($environmentPath)) {
            continue;
        }

        $environment = file_get_contents($environmentPath);

        if (is_string($environment) && preg_match('/^DB_DATABASE=(.*)$/m', $environment, $matches) === 1) {
            $database = trim($matches[1], " \t\"'");
            break;
        }
    }
}

if (! is_string($database) || $database === '') {
    throw new RuntimeException('Unable to determine the development database name for testing.');
}

if (! str_ends_with($database, '_testing')) {
    $database .= '_testing';
}

putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;
