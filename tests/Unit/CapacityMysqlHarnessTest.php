<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Tests\Support\capacityMysqlSettings;
use function Tests\Support\capacityPhpunitResult;
use function Tests\Support\assertCapacityDatabase;

require_once __DIR__.'/../Support/CapacityMysqlHarness.php';

class CapacityMysqlHarnessTest extends TestCase
{
    public function test_connection_settings_ignore_application_credentials_and_urls(): void
    {
        $settings = capacityMysqlSettings(['DB_HOST' => 'production.invalid', 'DB_USERNAME' => 'production',
            'DB_PASSWORD' => 'must-not-use', 'DB_URL' => 'mysql://production.invalid/live']);
        $this->assertSame('127.0.0.1', $settings['host']);
        $this->assertSame('3306', $settings['port']);
        $this->assertSame('root', $settings['username']);
        $this->assertSame('', $settings['password']);
    }

    #[DataProvider('unsafeConnections')]
    public function test_unsafe_connection_settings_are_rejected(array $settings): void
    {
        $this->expectException(RuntimeException::class);
        capacityMysqlSettings($settings);
    }

    public static function unsafeConnections(): array
    {
        return [
            [['CAPACITY_TEST_MYSQL_HOST' => 'production.invalid']],
            [['CAPACITY_TEST_MYSQL_HOST' => '127.0.0.1;dbname=development']],
            [['CAPACITY_TEST_MYSQL_HOST' => '/tmp/mysql.sock']],
            [['CAPACITY_TEST_MYSQL_PORT' => '3306;dbname=development']],
            [['CAPACITY_TEST_MYSQL_PORT' => '0']],
        ];
    }

    #[DataProvider('unsafeDatabases')]
    public function test_database_must_be_the_exact_disposable_name(string $actual, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        assertCapacityDatabase($actual, $expected);
    }

    public static function unsafeDatabases(): array
    {
        return [
            ['development', 'bethlehem_capacity_012345abcdef'],
            ['bethlehem_capacity_wrong', 'bethlehem_capacity_wrong'],
            ['bethlehem_capacity_012345abcdef', 'bethlehem_capacity_abcdef012345'],
            ['bethlehem_capacity_012345abcdef`', 'bethlehem_capacity_012345abcdef`'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_zero_exit_alone_never_proves_concurrency_passed(?string $xml, string $events, int $exit, string $expected): void
    {
        $this->assertSame($expected, capacityPhpunitResult($xml, $events, $exit));
    }

    public static function outcomes(): array
    {
        $case = '<testcase class="Tests\Feature\GroomingAdministrationAuthorizationTest" name="test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql" assertions="20">%s</testcase>';
        $report = fn ($content) => '<testsuites><testsuite>'.sprintf($case, $content).'</testsuite></testsuites>';

        return [
            [null, '', 0, 'configuration_error'],
            ['<invalid', '', 0, 'configuration_error'],
            ['<testsuites/>', '', 0, 'configuration_error'],
            [$report(''), '', 0, 'passed'],
            [$report('<skipped/>'), '', 0, 'configuration_error'],
            [$report('<skipped/>'), 'Test Skipped (Tests\Feature\GroomingAdministrationAuthorizationTest::test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql)', 0, 'skipped'],
            [$report('<skipped/>'), 'Test Marked Incomplete (Tests\Feature\GroomingAdministrationAuthorizationTest::test_concurrent_grooming_submissions_serialize_the_last_capacity_on_mysql)', 0, 'incomplete'],
            [$report('<failure/>'), '', 1, 'failed'],
            [$report('<error/>'), '', 2, 'configuration_error'],
            [$report(''), '', 1, 'configuration_error'],
            [str_replace('assertions="20"', 'assertions="0"', $report('')), '', 0, 'configuration_error'],
            [str_replace('serialize_the_last_capacity', 'unrelated', $report('')), '', 0, 'configuration_error'],
            ['<testsuites>'.$report('').$report('').'</testsuites>', '', 0, 'configuration_error'],
        ];
    }
}
