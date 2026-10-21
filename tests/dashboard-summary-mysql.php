<?php

// Run only against a new disposable schema on a literal loopback address.
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$host = getenv('DASHBOARD_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('DASHBOARD_TEST_MYSQL_PORT') ?: '3306';
if (! in_array($host, ['127.0.0.1', '::1'], true) || ! ctype_digit($port)) {
    throw new RuntimeException('Dashboard tests require a loopback MySQL server.');
}
$username = getenv('DASHBOARD_TEST_MYSQL_USERNAME') ?: 'root';
$password = getenv('DASHBOARD_TEST_MYSQL_PASSWORD') ?: '';
$database = 'bethlehem_dashboard_'.bin2hex(random_bytes(6));
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo 'Dashboard test server: '.$server->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$configuration = sys_get_temp_dir().'/'.$database.'-phpunit.xml';
$environment = [
    'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.$database.'-config.php',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
    'DB_DATABASE' => $database, 'DB_USERNAME' => $username, 'DB_PASSWORD' => $password,
    'DB_URL' => '', 'DB_SOCKET' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'DASHBOARD_TEST_DATABASE' => $database,
];
$status = 1;
try {
    $xml = new DOMDocument;
    $xml->load(dirname(__DIR__).'/phpunit.xml');
    $xml->documentElement->setAttribute('bootstrap', dirname(__DIR__).'/tests/bootstrap.php');
    $xml->documentElement->setAttribute('cacheResult', 'false');
    foreach ((new DOMXPath($xml))->query('//php/env') as $entry) {
        if (str_starts_with($entry->getAttribute('name'), 'DB_') || $entry->getAttribute('name') === 'APP_CONFIG_CACHE') {
            $entry->parentNode->removeChild($entry);
        }
    }
    foreach ($xml->getElementsByTagName('directory') as $directory) {
        $directory->nodeValue = dirname(__DIR__).'/'.$directory->nodeValue;
    }
    $xml->save($configuration);
    $migrate = new Process([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], dirname(__DIR__), $environment);
    $migrate->setTimeout(120);
    $migrate->mustRun();
    echo "Current migrations applied to disposable schema.\n";
    $tests = new Process([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--configuration', $configuration,
        '--no-progress', '--fail-on-skipped', 'tests/Feature/AdminDashboardMysqlTest.php'], dirname(__DIR__), $environment);
    $tests->setTimeout(120);
    $status = $tests->run(fn ($type, $buffer) => print($buffer));
} finally {
    if (is_file($configuration)) unlink($configuration);
    // This identifier was generated here, never read from application settings.
    $server->exec("DROP DATABASE `{$database}`");
}
exit($status);
