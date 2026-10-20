<?php

namespace Tests\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Database\Connection;
use RuntimeException;

function capacityMysqlSettings(array $environment): array
{
    // Deliberately never read the application's DB_* settings or .env credentials.
    $host = $environment['CAPACITY_TEST_MYSQL_HOST'] ?? '127.0.0.1';
    if ($host === 'localhost') $host = '127.0.0.1';
    $port = (string) ($environment['CAPACITY_TEST_MYSQL_PORT'] ?? '3306');
    if (! in_array($host, ['127.0.0.1', '::1'], true)
        || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Capacity tests require a literal local loopback host and a valid TCP port.');
    }

    return ['host' => $host, 'port' => $port,
        'username' => $environment['CAPACITY_TEST_MYSQL_USERNAME'] ?? 'root',
        'password' => $environment['CAPACITY_TEST_MYSQL_PASSWORD'] ?? ''];
}

function assertCapacityDatabase(string $actual, string $expected): void
{
    if (! preg_match('/\Abethlehem_capacity_[a-f0-9]{12}\z/', $expected) || $actual !== $expected) {
        throw new RuntimeException('Refusing a database other than this run\'s exact disposable capacity database.');
    }
}

function capacityRunDirectory(): string
{
    $database = (string) getenv('CAPACITY_TEST_DATABASE');
    assertCapacityDatabase((string) getenv('DB_DATABASE'), $database);
    $directory = realpath((string) getenv('CAPACITY_TEST_RUN_DIR'));
    if (! $directory || basename($directory) !== $database
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || ! preg_match('/\A[a-f0-9]{32}\z/', (string) getenv('CAPACITY_TEST_RUN_TOKEN'))) {
        throw new RuntimeException('An isolated capacity runner directory and ownership token are required.');
    }

    return $directory;
}

function assertCapacityConnection(Connection $connection): void
{
    capacityRunDirectory();
    $settings = capacityMysqlSettings(getenv());
    assertCapacityDatabase($connection->getDatabaseName(), (string) getenv('CAPACITY_TEST_DATABASE'));
    if ($connection->getDriverName() !== 'mysql' || $connection->getConfig('host') !== $settings['host']
        || (string) $connection->getConfig('port') !== $settings['port']
        || $connection->getConfig('username') !== $settings['username']
        || $connection->getConfig('password') !== $settings['password']
        || $connection->getConfig('url') || $connection->getConfig('unix_socket')
        || $connection->getConfig('read') || $connection->getConfig('write')) {
        throw new RuntimeException('Laravel did not resolve the isolated local MySQL connection.');
    }
    // Verify the actual selected schema and the marker created by this runner before any fixture DDL.
    $actual = $connection->selectOne('SELECT DATABASE() AS name');
    assertCapacityDatabase($actual->name, (string) getenv('CAPACITY_TEST_DATABASE'));
    $marker = $connection->selectOne('SELECT run_token FROM capacity_test_run');
    if (! hash_equals((string) getenv('CAPACITY_TEST_RUN_TOKEN'), $marker->run_token ?? '')) {
        throw new RuntimeException('The capacity database is not owned by this test execution.');
    }
}

function capacityPhpunitResult(?string $report, string $events, ?int $exitCode): string
{
    if (! $report) return 'configuration_error';
    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    try { $valid = $document->loadXML($report, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    if (! $valid) return 'configuration_error';
    $cases = (new DOMXPath($document))->query('//testcase');
    if ($cases->length !== 1) return 'configuration_error';
    $case = $cases->item(0);
    $method = 'test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql';
    $class = 'Tests\\Feature\\GroomingAdministrationAuthorizationTest';
    if ($case->getAttribute('name') !== $method || $case->getAttribute('class') !== $class) return 'configuration_error';
    if ($case->getElementsByTagName('failure')->length) return 'failed';
    if ($case->getElementsByTagName('error')->length) return 'configuration_error';
    if ($case->getElementsByTagName('skipped')->length) {
        // PHPUnit 11 writes identical JUnit elements for skipped and incomplete tests.
        if (str_contains($events, "Test Marked Incomplete ({$class}::{$method})")) return 'incomplete';
        if (str_contains($events, "Test Skipped ({$class}::{$method})")) return 'skipped';
        return 'configuration_error';
    }

    return $exitCode === 0 && (int) $case->getAttribute('assertions') > 0 ? 'passed' : 'configuration_error';
}
