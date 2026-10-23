<?php

// No Laravel bootstrap or application credentials in the database-creating process.
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$host = getenv('QR_PAYMENT_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('QR_PAYMENT_TEST_MYSQL_PORT') ?: '3306';
if (! in_array($host, ['127.0.0.1', '::1'], true) || ! ctype_digit($port)
    || (int) $port < 1 || (int) $port > 65535) throw new RuntimeException('QR MySQL tests require a literal loopback host and valid TCP port.');
$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", getenv('QR_PAYMENT_TEST_MYSQL_USERNAME') ?: 'root',
    getenv('QR_PAYMENT_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
$identity = $server->query('SELECT @@hostname AS hostname, @@port AS port, VERSION() AS version, @@GLOBAL.explicit_defaults_for_timestamp AS explicit_defaults, @@GLOBAL.sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
if (strcasecmp(explode('.', $identity['hostname'])[0], explode('.', gethostname())[0]) !== 0 || (int) $identity['port'] !== (int) $port) {
    throw new RuntimeException('MySQL identity does not match this local computer and port.');
}
echo 'Verified local MySQL/MariaDB: '.json_encode($identity, JSON_THROW_ON_ERROR).PHP_EOL;
$database = 'bethlehem_qr_'.bin2hex(random_bytes(6));
$token = bin2hex(random_bytes(16));
$directory = sys_get_temp_dir().'/'.$database;
if (! mkdir($directory, 0700)) throw new RuntimeException('Could not create the isolated test directory.');
$created = $markerReady = false;
$status = 1;
try {
    // A name collision never grants ownership of an existing schema.
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $server->exec("CREATE TABLE `{$database}`.qr_test_run (run_token CHAR(32) NOT NULL)");
    $server->prepare("INSERT INTO `{$database}`.qr_test_run VALUES (?)")->execute([$token]);
    $markerReady = true;
    $xml = new DOMDocument;
    $xml->load(dirname(__DIR__).'/phpunit.xml');
    $xml->documentElement->setAttribute('bootstrap', dirname(__DIR__).'/tests/bootstrap.php');
    $xml->documentElement->setAttribute('cacheResult', 'false');
    foreach ((new DOMXPath($xml))->query('//php/env') as $entry) {
        if (str_starts_with($entry->getAttribute('name'), 'DB_') || $entry->getAttribute('name') === 'APP_CONFIG_CACHE') $entry->parentNode->removeChild($entry);
    }
    foreach ($xml->getElementsByTagName('directory') as $entry) $entry->nodeValue = dirname(__DIR__).'/'.$entry->nodeValue;
    $xml->save($directory.'/phpunit.xml');
    $process = new Process([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--configuration', $directory.'/phpunit.xml',
        '--no-progress', '--fail-on-skipped', '--fail-on-incomplete', '--log-junit', $directory.'/junit.xml',
        'tests/Feature/QrPaymentSettingsTest.php', 'tests/Feature/GroomingPaymentPriceSafetyTest.php'], dirname(__DIR__), [
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
        'QR_PAYMENT_TEST_DATABASE' => $database, 'QR_PAYMENT_TEST_TOKEN' => $token,
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $host, 'DB_PORT' => $port,
        'DB_DATABASE' => $database, 'DB_USERNAME' => getenv('QR_PAYMENT_TEST_MYSQL_USERNAME') ?: 'root',
        'DB_PASSWORD' => getenv('QR_PAYMENT_TEST_MYSQL_PASSWORD') ?: '', 'DB_URL' => '', 'DB_SOCKET' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    ]);
    $process->setTimeout(120);
    try { $status = $process->run(fn ($type, $buffer) => print($buffer)); }
    finally { if ($process->isRunning()) $process->stop(1); }
    $report = new DOMDocument;
    if (! is_file($directory.'/junit.xml') || ! $report->load($directory.'/junit.xml')
        || $report->getElementsByTagName('testcase')->length < 1
        || $report->getElementsByTagName('failure')->length || $report->getElementsByTagName('error')->length
        || $report->getElementsByTagName('skipped')->length) $status = 1;
} finally {
    if ($created) {
        if (! preg_match('/\Abethlehem_qr_[a-f0-9]{12}\z/', $database) || basename($directory) !== $database) throw new RuntimeException('Refusing unexpected database cleanup.');
        if ($markerReady && ! hash_equals($token, (string) $server->query("SELECT run_token FROM `{$database}`.qr_test_run")->fetchColumn())) {
            throw new RuntimeException('QR test ownership changed; refusing cleanup.');
        }
        $server->exec("DROP DATABASE `{$database}`");
        $check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $check->execute([$database]);
        if ((int) $check->fetchColumn() !== 0) throw new RuntimeException('Isolated QR test database cleanup failed.');
        echo "Cleanup verified: {$database} removed.\n";
    }
    foreach (['phpunit.xml', 'junit.xml'] as $file) if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
    rmdir($directory);
}
exit($status);
