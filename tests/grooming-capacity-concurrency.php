<?php

use Symfony\Component\Process\Process;

use function Tests\Support\assertCapacityDatabase;
use function Tests\Support\capacityMysqlSettings;
use function Tests\Support\capacityPhpunitResult;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/Support/CapacityMysqlHarness.php';

$server = null;
$created = $markerReady = $directoryCreated = false;
$directory = null;
$status = 2;
try {
    $settings = capacityMysqlSettings(getenv());
    echo "Local capacity test target: {$settings['host']}:{$settings['port']} (test-specific credentials only).\n";
    $server = new PDO("mysql:host={$settings['host']};port={$settings['port']};charset=utf8mb4",
        $settings['username'], $settings['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
    $identity = $server->query('SELECT @@hostname AS hostname, @@port AS port, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
    if (strcasecmp(explode('.', $identity['hostname'])[0], explode('.', gethostname())[0]) !== 0
        || (int) $identity['port'] !== (int) $settings['port']) {
        throw new RuntimeException('MySQL server identity does not match this local computer/port.');
    }
    echo "Verified local MySQL/MariaDB: {$identity['hostname']} {$identity['version']}\n";
    $database = 'bethlehem_capacity_'.bin2hex(random_bytes(6));
    assertCapacityDatabase($database, $database);
    $token = bin2hex(random_bytes(16));
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
    if (! mkdir($directory, 0700)) throw new RuntimeException('Could not create the isolated run directory.');
    $directoryCreated = true;
    // No IF NOT EXISTS: a name collision must never grant ownership of an existing schema.
    $server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $server->exec("CREATE TABLE `{$database}`.capacity_test_run (run_token CHAR(32) NOT NULL)");
    $marker = $server->prepare("INSERT INTO `{$database}`.capacity_test_run VALUES (?)");
    $marker->execute([$token]);
    $markerReady = true;
    echo "Created disposable database: {$database}\n";
    $process = new Process([PHP_BINARY, 'vendor/phpunit/phpunit/phpunit', '--configuration', 'phpunit.capacity-mysql.xml',
        '--no-progress', '--filter', '::test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql$',
        '--log-junit', $directory.'/junit.xml', '--log-events-text', $directory.'/events.log'], dirname(__DIR__), [
        'CAPACITY_TEST_DATABASE' => $database, 'CAPACITY_TEST_RUN_TOKEN' => $token, 'CAPACITY_TEST_RUN_DIR' => $directory,
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $database, 'DB_URL' => '', 'DB_SOCKET' => '',
        'DB_HOST' => $settings['host'], 'DB_PORT' => $settings['port'],
        'DB_USERNAME' => $settings['username'], 'DB_PASSWORD' => $settings['password'],
        'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/laravel-config.php',
    ]);
    $process->setTimeout(90);
    try { $exit = $process->run(fn ($type, $buffer) => print($buffer)); }
    finally { if ($process->isRunning()) $process->stop(1); }
    $result = capacityPhpunitResult(is_file($directory.'/junit.xml') ? file_get_contents($directory.'/junit.xml') : null,
        is_file($directory.'/events.log') ? file_get_contents($directory.'/events.log') : '', $exit);
    if ($result === 'passed') {
        if (! is_file($directory.'/verification.json')) throw new RuntimeException('Missing real MySQL execution proof.');
        $proof = json_decode(file_get_contents($directory.'/verification.json'), true, flags: JSON_THROW_ON_ERROR);
        assertCapacityDatabase($proof['database'], $database);
        if ($proof['driver'] !== 'mysql') throw new RuntimeException('Missing real MySQL execution proof.');
        echo 'Verified execution: '.json_encode($proof, JSON_THROW_ON_ERROR).PHP_EOL;
    }
    echo 'MySQL concurrency result: '.$result.PHP_EOL;
    $status = match ($result) { 'passed' => 0, 'configuration_error' => 2, default => 1 };
} catch (PDOException $error) {
    fwrite(STDERR, 'Local MySQL unavailable/database operation failed (SQLSTATE '.($error->errorInfo[0] ?? $error->getCode())
        .', driver code '.($error->errorInfo[1] ?? 'unknown')."). No remote fallback.\n");
    $status = 3;
} catch (Throwable $error) {
    fwrite(STDERR, 'Capacity runner/configuration error: '.$error->getMessage().PHP_EOL);
    $status = 2;
} finally {
    try {
        if ($created) {
            assertCapacityDatabase($database, basename($directory));
            if ($markerReady) {
                $owner = $server->query("SELECT run_token FROM `{$database}`.capacity_test_run")->fetchColumn();
                if (! hash_equals($token, (string) $owner)) throw new RuntimeException('Disposable database ownership changed; refusing cleanup.');
            }
            $server->exec("DROP DATABASE `{$database}`");
            $check = $server->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
            $check->execute([$database]);
            if ((int) $check->fetchColumn() !== 0) throw new RuntimeException('Disposable database still exists.');
            echo "Cleanup verified: {$database} removed.\n";
        }
    } catch (Throwable $error) {
        fwrite(STDERR, 'CLEANUP FAILED: '.$error->getMessage().PHP_EOL);
        $status = 4;
    }
    if ($directoryCreated && is_dir($directory)) {
        foreach (['junit.xml', 'events.log', 'first-lock.json', 'second-attempt.json', 'verification.json'] as $file) {
            if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
        }
        if (! rmdir($directory)) { fwrite(STDERR, "Run directory cleanup failed: {$directory}\n"); $status = 4; }
    }
}
exit($status);
