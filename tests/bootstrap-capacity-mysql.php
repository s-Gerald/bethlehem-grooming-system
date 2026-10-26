<?php

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/Support/CapacityMysqlHarness.php';

$directory = \Tests\Support\capacityRunDirectory();
if (getenv('DB_CONNECTION') !== 'mysql' || ($_SERVER['DB_CONNECTION'] ?? null) !== 'mysql') {
    throw new RuntimeException('The capacity runner requires its dedicated MySQL PHPUnit configuration.');
}
$settings = \Tests\Support\capacityMysqlSettings(getenv());
$cache = $directory.'/laravel-config.php';
if (is_file($cache)) throw new RuntimeException('Refusing cached Laravel configuration for the capacity test.');
foreach (['DB_HOST' => $settings['host'], 'DB_PORT' => $settings['port'],
    'DB_USERNAME' => $settings['username'], 'DB_PASSWORD' => $settings['password'],
    'DB_URL' => '', 'DB_SOCKET' => '', 'APP_CONFIG_CACHE' => $cache,
    'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'IPROGSMS_API_TOKEN' => '', 'SMS_VERIFICATION_AVAILABLE' => 'false',
    'SMS_VERIFICATION_ENFORCED' => 'false', 'GROOMING_COMPLETION_SMS_ENABLED' => 'false'] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $_SERVER[$name] = $value;
}
