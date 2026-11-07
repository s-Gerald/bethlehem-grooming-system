<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait ConcurrentAuthenticationRequests
{
    // Two real processes must overlap under the writer lock, not merely run in order.
    private function assertConcurrentAuthenticationRequests(array $firstParameters, array $secondParameters, int $secondStatus, int $pendingCount, int $expectedAccounts = 1): array
    {
        $payload=json_encode($firstParameters,JSON_THROW_ON_ERROR);
        $secondPayload=json_encode($secondParameters,JSON_THROW_ON_ERROR);
        $directory = sys_get_temp_dir().'/bethlehem-signup-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory, 0700));
        $database = $directory.'/registration.sqlite';
        $owner = bin2hex(random_bytes(16));
        file_put_contents($directory.'/owner', $owner);
        $first = $second = $connection = $probe = $contender = null;
        try {
            // Match the existing SQLite concurrency tests: prove runtime support,
            // rather than treating a PHP version or configuration flag as evidence.
            $probePath = $directory.'/probe.sqlite';
            $probe = new \Illuminate\Database\SQLiteConnection(new \PDO('sqlite:'.$probePath), $probePath, '', ['transaction_mode' => 'IMMEDIATE']);
            $contender = new \PDO('sqlite:'.$probePath);
            $contender->exec('PRAGMA busy_timeout=0');
            $probe->beginTransaction();
            $tracked = $probe->getPdo()->inTransaction();
            $writerLocked = false;
            try {
                $contender->exec('BEGIN IMMEDIATE');
                $contender->exec('ROLLBACK');
            } catch (\PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                $writerLocked = true;
            }
            $probe->rollBack();
            $probe->disconnect();
            $probe = $contender = null;
            if (! $tracked || ! $writerLocked) {
                $this->markTestSkipped('This Laravel/PDO SQLite runtime cannot enforce and track IMMEDIATE transactions. Use the documented compatible PHP runtime.');
            }
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($database));
            $worker = <<<'PHP'
                require 'tests/bootstrap.php';
                [$script, $database, $role, $owner, $payload] = $argv;
                $directory = realpath(dirname($database));
                if (!$directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
                    || !preg_match('/\Abethlehem-signup-[a-f0-9]{12}\z/', basename($directory))
                    || basename($database) !== 'registration.sqlite' || !is_file($database)
                    || !hash_equals(file_get_contents($directory.'/owner'), $owner)) {
                    throw new RuntimeException('Worker requires its owned disposable registration database.');
                }
                $app = require 'bootstrap/app.php';
                // Keep route flood counters out of the writer-lock barrier. The
                // email issuance policy below still uses the shared database cache.
                $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,
                    fn ($app) => $app['config']->set('cache.limiter', 'array'));
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database,
                    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
                    'database.connections.sqlite.transaction_mode' => 'IMMEDIATE', 'cache.limiter' => 'array']);
                Illuminate\Support\Carbon::setTestNow(getenv('BETHLEHEM_TEST_CLOCK'));
                Illuminate\Support\Facades\Notification::fake();
                Illuminate\Support\Facades\DB::purge('sqlite');
                $connection = Illuminate\Support\Facades\DB::connection();
                if (realpath($connection->selectOne('PRAGMA database_list')->file) !== realpath($database)) {
                    throw new RuntimeException('Worker opened an unexpected database.');
                }
                $wait = function ($file) {
                    $deadline = microtime(true) + 5;
                    while (!is_file($file) && microtime(true) < $deadline) { clearstatcache(); usleep(1000); }
                    if (!is_file($file)) throw new RuntimeException('Verification barrier timed out.');
                };
                Illuminate\Support\Facades\Http::fake(['api.pwnedpasswords.com/*' => Illuminate\Support\Facades\Http::response(str_repeat('A',35).':0')]);
                $busy = false;
                $connection->beforeStartingTransaction(function () use ($role, $directory, $database, $wait, &$busy) {
                    if ($role !== 'second') return;
                    $wait($directory.'/first-lock');
                    $probe = new PDO('sqlite:'.$database);
                    $probe->exec('PRAGMA busy_timeout=0');
                    try {
                        $probe->exec('BEGIN IMMEDIATE'); $probe->exec('ROLLBACK');
                        throw new RuntimeException('The first verification must hold the writer lock.');
                    } catch (PDOException $error) {
                        if (($error->errorInfo[1] ?? null) !== 5) throw $error;
                        $busy = true;
                    }
                    touch($directory.'/second-attempt');
                });
                $app['events']->listen(Illuminate\Database\Events\TransactionBeginning::class,
                    function () use ($role, $directory, $wait) {
                        if ($role !== 'first') return;
                        touch($directory.'/first-lock');
                        $wait($directory.'/second-attempt');
                        usleep(200000);
                    });
                $parameters = json_decode($payload, true);
                $request = Illuminate\Http\Request::create($parameters['_verification_endpoint'] ?? '/api/email/verify', 'POST', $parameters,
                    ['bethlehem_registration_browser' => $parameters['registration_browser'] ?? ''],
                    server: ['REMOTE_ADDR' => $role === 'first' ? '192.0.2.1' : '192.0.2.2',
                        'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
                $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
                echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'busy' => $busy,
                    'link_hash' => Illuminate\Support\Facades\DB::table('pending_customer_registrations')->value('email_verification_token')]);
                PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => config('cache.default'),
                'BETHLEHEM_TEST_CLOCK' => now()->toIso8601String(),
                'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'];
            $first = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'first', $owner, $payload], base_path(), $environment);
            $second = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker, $database, 'second', $owner, $secondPayload], base_path(), $environment);
            $first->setTimeout(15); $second->setTimeout(15);
            $first->start(); $second->start();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame($one['pid'], $two['pid']);
            $this->assertTrue($two['busy'], 'The requests must overlap while the first owns the database lock.');
            $this->assertSame(200, $one['status']);
            $this->assertSame($secondStatus, $two['status']);
            $connection = new \PDO('sqlite:'.$database);
            $this->assertSame($expectedAccounts, (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn());
            $this->assertSame($pendingCount, (int) $connection->query('SELECT COUNT(*) FROM pending_customer_registrations')->fetchColumn());
            $this->assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM login_email_challenges')->fetchColumn());
            $this->assertSame($expectedAccounts, (int) $connection->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn());
            $pending = $connection->query('SELECT email_verification_token, registration_approved_at FROM pending_customer_registrations ORDER BY id')->fetch(\PDO::FETCH_ASSOC);
            return ['first' => $one, 'second' => $two, 'pending' => $pending];
        } finally {
            $first?->stop(); $second?->stop();
            if ($probe?->transactionLevel()) $probe->rollBack();
            $probe?->disconnect();
            $connection = $probe = $contender = null;
            foreach (['registration.sqlite', 'registration.sqlite-journal', 'registration.sqlite-wal', 'registration.sqlite-shm',
                'probe.sqlite', 'probe.sqlite-journal', 'owner', 'first-lock', 'second-attempt'] as $file) {
                if (is_file($directory.'/'.$file)) unlink($directory.'/'.$file);
            }
            rmdir($directory);
        }
    }
}
