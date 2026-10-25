<?php

// Optional integration runner: reuse the feature cases on a disposable local
// MySQL database. Never run the fixture schema against the application's data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$config = config('database.connections.'.config('database.default'));
if (! app()->environment('local') || ($config['driver'] ?? '') !== 'mysql'
    || ! in_array($config['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
    || ! empty($config['url'])) {
    throw new RuntimeException('This runner requires the local development MySQL configuration.');
}

$database = 'bethlehem_email_test_'.bin2hex(random_bytes(6));
$configuration = tempnam(storage_path('framework'), 'staff-email-mysql-');
$created = false;
$exitCode = 1;
try {
    Illuminate\Support\Facades\DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $xml = new DOMDocument;
    $xml->load(base_path('phpunit.xml'));
    $xml->documentElement->setAttribute('bootstrap', base_path('tests/bootstrap.php'));
    foreach ($xml->getElementsByTagName('directory') as $directory) $directory->nodeValue = base_path($directory->nodeValue);
    foreach ($xml->getElementsByTagName('env') as $environment) {
        if ($environment->getAttribute('name') === 'DB_CONNECTION') $environment->setAttribute('value', 'mysql');
        if ($environment->getAttribute('name') === 'DB_DATABASE') $environment->setAttribute('value', $database);
    }
    $xml->save($configuration);
    $process = new Symfony\Component\Process\Process([
        PHP_BINARY, base_path('vendor/phpunit/phpunit/phpunit'), '--configuration', $configuration,
        '--filter', 'active_staff_verify|overlapping_email|email_change_migration|completed_email_change',
        base_path('tests/Feature/AdminCredentialChangeTest.php'),
    ], base_path(), [
        'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
    ]);
    $process->setTimeout(120);
    $exitCode = $process->run(fn ($type, $buffer) => print($buffer));
} finally {
    if ($created && preg_match('/\Abethlehem_email_test_[a-f0-9]{12}\z/', $database)) {
        Illuminate\Support\Facades\DB::statement('DROP DATABASE `'.$database.'`');
    }
    if (is_file($configuration)) unlink($configuration);
}
exit($exitCode);
