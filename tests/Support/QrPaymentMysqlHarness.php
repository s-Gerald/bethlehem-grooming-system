<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Guard fixture writes and teardown; never accept an application database. */
function assertQrPaymentTestDatabase(): void
{
    $connection = DB::connection();
    if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') return;

    $expected = (string) getenv('QR_PAYMENT_TEST_DATABASE');
    $token = (string) getenv('QR_PAYMENT_TEST_TOKEN');
    if ($connection->getDriverName() !== 'mysql'
        || ! preg_match('/\Abethlehem_qr_[a-f0-9]{12}\z/', $expected)
        || ! preg_match('/\A[a-f0-9]{32}\z/', $token)
        || $connection->getDatabaseName() !== $expected
        || ! in_array($connection->getConfig('host'), ['127.0.0.1', '::1'], true)
        || $connection->getConfig('url') || $connection->getConfig('unix_socket')
        || $connection->getConfig('read') || $connection->getConfig('write')) {
        throw new RuntimeException('QR fixtures require in-memory SQLite or an owned disposable loopback MySQL database.');
    }
    $actual = $connection->selectOne('SELECT DATABASE() AS name, @@SESSION.sql_mode AS sql_mode');
    if ($actual->name !== $expected
        || ! str_contains($actual->sql_mode, 'STRICT_TRANS_TABLES')
        || ! str_contains($actual->sql_mode, 'NO_ZERO_DATE')) {
        throw new RuntimeException('The QR MySQL fixture must use the exact isolated schema with Laravel strict SQL modes.');
    }
    $owner = $connection->selectOne('SELECT run_token FROM qr_test_run');
    if (! hash_equals($token, $owner->run_token ?? '')) throw new RuntimeException('QR test database ownership check failed.');
}
