<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Static files never need a session; starting one per file made each asset cost a DB round-trip.
$staticFileMiddleware = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
];

// Keep the page outside the public static tree; its HTML is admin-authorized too.
Route::get('/pages/admin/activity-log.html', fn () => redirect('api/admin/activity-log/page'))
    ->withoutMiddleware($staticFileMiddleware);

Route::get('/', function () {
    return response()->file(base_path('index.html'), [
        'Cache-Control' => 'no-store, private, max-age=0, must-revalidate',
    ]);
})->withoutMiddleware($staticFileMiddleware);

Route::get('/index.html', function () {
    return response()->file(base_path('index.html'), [
        'Cache-Control' => 'no-store, private, max-age=0, must-revalidate',
    ]);
})->withoutMiddleware($staticFileMiddleware);

Route::get('/{directory}/{path}', function (string $directory, string $path) {
    $root = realpath(base_path($directory));
    $file = realpath(base_path($directory . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)));

    if (!$root || !$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !File::isFile($file)) {
        abort(404);
    }

    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'html' => 'text/html; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $cacheControl = match (true) {
        $extension === 'html' => 'no-store, private, max-age=0, must-revalidate',
        default => 'public, max-age=3600',
    };

    return response()->file($file, [
        'Cache-Control' => $cacheControl,
        'Content-Type' => $contentTypes[$extension] ?? File::mimeType($file),
    ]);
})->where('directory', 'assets|css|pages|scripts')->where('path', '.*')->withoutMiddleware($staticFileMiddleware);
