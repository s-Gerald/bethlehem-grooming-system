<?php

// Select a test-only runtime, then verify execution through PHPUnit's reports.
use Symfony\Component\Process\Process;

use function Tests\Support\concurrencyPhpunitResult;

$directory = null;
$directoryCreated = false;
$process = null;
$result = 'infrastructure_error';
try {
    require dirname(__DIR__).'/vendor/autoload.php';
    require __DIR__.'/Support/CapacityMysqlHarness.php';
    $configured = getenv('NO_SHOW_TEST_PHP');
    $persistent = PHP_OS_FAMILY === 'Windows' && getenv('LOCALAPPDATA')
        ? getenv('LOCALAPPDATA').'/Programs/BethlehemTestTools/php84/php.exe' : null;
    $php = $configured !== false && $configured !== '' ? $configured
        : ($persistent && is_file($persistent) ? $persistent : PHP_BINARY);
    if (! is_file($php)) {
        throw new RuntimeException('PHP executable not found: '.$php.'. Set NO_SHOW_TEST_PHP to a persistent PHP executable.');
    }
    $probe = new Process([$php, '-r', <<<'PHP'
        echo json_encode(['binary' => PHP_BINARY, 'version' => PHP_VERSION,
            'extensions' => get_loaded_extensions(), 'processes' => function_exists('proc_open'),
            'sqlite' => extension_loaded('pdo_sqlite') ? (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn() : null], JSON_THROW_ON_ERROR);
        PHP], dirname(__DIR__));
    $probe->setTimeout(10);
    $probe->mustRun();
    $runtime = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if (strcasecmp((string) realpath($php), (string) realpath($runtime['binary'])) !== 0) {
        throw new RuntimeException('Selected PHP did not identify itself as the expected executable.');
    }
    $missing = array_diff(['pdo_sqlite', 'dom', 'mbstring', 'xml', 'xmlwriter', 'tokenizer', 'fileinfo', 'openssl'], $runtime['extensions']);
    echo "Test runtime: {$runtime['binary']}\nPHP {$runtime['version']}; SQLite ".($runtime['sqlite'] ?? 'unavailable').PHP_EOL;
    if ($missing || ! $runtime['processes']) {
        echo 'Runtime unsupported: '.($missing ? 'enable '.implode(', ', $missing).'. ' : '')
            .(! $runtime['processes'] ? 'proc_open must be enabled. ' : '').PHP_EOL;
        $result = 'unsupported';
    } else {
        $directory = sys_get_temp_dir().'/bethlehem-expiry-report-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700)) throw new RuntimeException('Cannot create the disposable PHPUnit report directory.');
        $directoryCreated = true;
        $method = 'test_overlapping_expiry_calls_create_one_event_per_registration_on_sqlite';
        $process = new Process([$runtime['binary'], 'vendor/phpunit/phpunit/phpunit', '--configuration', 'phpunit.xml',
            '--no-progress', '--do-not-cache-result', '--filter', '::'.$method.'$',
            '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky', '--fail-on-warning',
            '--log-junit', $directory.'/junit.xml', '--log-events-text', $directory.'/events.log',
            'tests/Feature/QueuePreRegistrationModelTest.php'], dirname(__DIR__), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            ]);
        $process->setTimeout(60);
        $exit = $process->run(fn ($type, $buffer) => print($buffer));
        $report = is_file($directory.'/junit.xml') ? file_get_contents($directory.'/junit.xml') : null;
        $events = is_file($directory.'/events.log') ? file_get_contents($directory.'/events.log') : '';
        $result = concurrencyPhpunitResult($report, $events, $exit,
            'Tests\\Feature\\QueuePreRegistrationModelTest', $method, 40);
        if ($result === 'skipped' && str_contains($events, 'does not enforce and track IMMEDIATE transactions')) {
            $result = 'unsupported';
        }
        if (in_array($result, ['configuration_error', 'incomplete'], true)) {
            fwrite(STDERR, "PHPUnit must report exactly the intended completed test with at least 40 successful assertions; see its output above.\n");
            $result = 'infrastructure_error';
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Concurrency infrastructure error: '.$error->getMessage().PHP_EOL);
    $result = 'infrastructure_error';
} finally {
    $process?->stop();
    if ($directoryCreated && is_dir($directory)) {
        try {
            foreach (['junit.xml', 'events.log'] as $file) {
                if (is_file($directory.'/'.$file) && ! unlink($directory.'/'.$file)) throw new RuntimeException('Report cleanup failed.');
            }
            if (! rmdir($directory)) throw new RuntimeException('Report directory cleanup failed.');
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage().PHP_EOL);
            $result = 'infrastructure_error';
        }
    }
}
echo 'No show SQLite concurrency: '.$result.PHP_EOL;
if ($result === 'unsupported') echo "Use a compatible PHP runtime (PHP 8.4 recommended) with SQLite transaction support; set NO_SHOW_TEST_PHP if needed.\n";
exit(match ($result) { 'passed' => 0, 'failed' => 1, 'skipped' => 2, 'unsupported' => 3, default => 4 });
