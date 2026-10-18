<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = config('database.connections.mysql');
$database = 'bethlehem_reports_test_'.bin2hex(random_bytes(6));
if (! preg_match('/^bethlehem_reports_test_[a-f0-9]{12}$/', $database)
    || $database === $connection['database']) {
    throw new RuntimeException('Refusing to use an application database for report fixtures.');
}
$server = new PDO("mysql:host={$connection['host']};port={$connection['port']};charset=utf8mb4",
    $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo 'Report regression server: '.$server->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $process = new Process([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--no-progress',
        'tests/Feature/GroomingPerformanceReportTest.php'], dirname(__DIR__), [
        'APP_ENV' => 'testing',
        // Ignore a cached local/production connection without changing its cache.
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.$database.'-config.php',
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'DB_HOST' => $connection['host'], 'DB_PORT' => $connection['port'],
        'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    ]);
    $process->setTimeout(120);
    $status = $process->run(fn ($type, $buffer) => print($buffer));
} finally {
    // Only the exact random database created by this process is ever removed.
    $server->exec("DROP DATABASE `{$database}`");
}
exit($status);
