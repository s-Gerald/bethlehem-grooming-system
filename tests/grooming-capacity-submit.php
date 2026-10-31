<?php

use App\Http\Controllers\BookingController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/bootstrap-capacity-mysql.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
\Tests\Support\assertCapacityConnection(DB::connection());
Carbon::setTestNow('2026-10-16 13:00:00');
$action = $argv[4] ?? 'grooming-submit';
$user = User::findOrFail((int) $argv[1]);
$request = Request::create('/api/booking/store', 'POST', json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
$request->setUserResolver(fn () => $user);
$role = $argv[3] ?? '';
if (! in_array($role, ['first', 'second'], true)) throw new RuntimeException('A capacity worker role is required.');
$directory = \Tests\Support\capacityRunDirectory();
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$timing = [];
DB::connection()->beforeExecuting(function ($sql) use (&$timing, $role, $directory, $connectionId) {
    if (str_contains($sql, 'GET_LOCK(')) {
        $timing['attempted_at'] = microtime(true);
        if ($role === 'second') file_put_contents($directory.'/second-attempt.json', json_encode([
            'connection_id' => $connectionId, 'attempted_at' => $timing['attempted_at'],
        ], JSON_THROW_ON_ERROR), LOCK_EX);
    }
    if (str_contains($sql, 'RELEASE_LOCK(')) $timing['release_started_at'] = microtime(true);
});
DB::listen(function ($query) use (&$timing, $role, $directory, $connectionId) {
    if (! str_contains($query->sql, 'GET_LOCK(')) return;
    $owner = DB::selectOne('SELECT IS_USED_LOCK(?) AS owner', ['bethlehem-shared-intake']);
    if ((int) $owner->owner !== $connectionId) throw new RuntimeException('Worker did not acquire the shared intake lock.');
    $timing['acquired_at'] = microtime(true);
    if ($role !== 'first') return;
    file_put_contents($directory.'/first-lock.json', json_encode(['connection_id' => $connectionId], JSON_THROW_ON_ERROR), LOCK_EX);
    $deadline = microtime(true) + 10;
    do {
        clearstatcache();
        $second = is_file($directory.'/second-attempt.json') ? json_decode(file_get_contents($directory.'/second-attempt.json'), true) : null;
        if (isset($second['connection_id'])) {
            $pending = DB::selectOne('SELECT INFO AS statement FROM information_schema.PROCESSLIST WHERE ID = ?', [$second['connection_id']]);
            if ($pending && str_contains(strtoupper($pending->statement ?? ''), 'GET_LOCK(')) {
                $timing['contention_observed_at'] = microtime(true);
                return;
            }
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Second worker was not observed waiting for the shared MySQL lock.');
});
$started = microtime(true);
try {
    if ($action === 'grooming-submit') {
        $response = $app->call([app(BookingController::class), 'store'], ['request' => $request]);
    } elseif ($action === 'grooming-walk-in') {
        $form = \App\Http\Requests\StoreWalkinRequest::createFrom($request);
        $form->setContainer($app)->setRedirector($app['redirect']);
        $form->validateResolved();
        $response = app(\App\Http\Controllers\WalkinController::class)->store($form);
    } elseif ($action === 'clinic-submit') {
        $form = \App\Http\Requests\StoreClinicPreRegistrationRequest::createFrom($request);
        $form->setContainer($app)->setRedirector($app['redirect']);
        $form->validateResolved();
        $response = $app->call([app(\App\Http\Controllers\ClinicWalkinController::class), 'preRegister'], ['request' => $form]);
    } elseif (in_array($action, ['grooming-check-in', 'clinic-check-in'], true)) {
        $app->instance('request', $request);
        $response = $action === 'grooming-check-in'
            ? app(\App\Http\Controllers\AdminBookingController::class)->checkIn((int) $request->input('id'))
            : $app->call([app(\App\Http\Controllers\AdminClinicController::class), 'checkIn'], ['id' => (int) $request->input('id')]);
    } elseif ($action === 'capacity-update') {
        $response = app(\App\Http\Controllers\ClinicSettingController::class)->updateCapacity($request);
    } elseif ($action === 'intake-stop') {
        $response = app(\App\Http\Controllers\ClinicClosureController::class)->stopToday($request);
    } else throw new RuntimeException('Unsupported isolated test operation.');
} catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
    $response = $exception->getResponse();
} catch (ValidationException $exception) {
    $response = $exception->response ?? response()->json(['errors' => $exception->errors()], 422);
}
echo json_encode(['status' => $response->getStatusCode(), 'body' => $response->getData(true),
    'started_at' => $started, 'finished_at' => microtime(true), 'lock' => $timing,
    'driver' => DB::getDriverName(), 'database' => DB::connection()->getDatabaseName()], JSON_THROW_ON_ERROR);
