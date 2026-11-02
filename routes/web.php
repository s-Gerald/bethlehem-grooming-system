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

// Ordinary HTML remains session-free. The two protected documents reuse the API
// cookie transport, Sanctum, account/session checks and existing permissions.
$protectedPages = [
    '/admin/activity-log' => [\App\Http\Controllers\ActivityLogController::class, ['role:admin']],
    '/admin/availability' => [\App\Http\Controllers\ClinicSettingController::class, ['role:admin,staff', 'staff:availability_view']],
];

foreach (config('frontend.pages') as $url => $file) {
    $name = 'pages.'.($url === '/' ? 'home' : str_replace('/', '.', trim($url, '/')));
    if (isset($protectedPages[$url])) {
        [$controller, $permissions] = $protectedPages[$url];
        // No bindings are needed here; the web binding priority must not move
        // authentication ahead of the API's established cookie transport.
        Route::get($url, [$controller, 'page'])->name($name)
            ->middleware(['api', 'auth:sanctum', 'account.usable', 'session.active', ...$permissions])
            ->withoutMiddleware([...$staticFileMiddleware, \Illuminate\Routing\Middleware\SubstituteBindings::class]);
    } else {
        Route::get($url, [\App\Http\Controllers\FrontendPageController::class, 'show'])
            ->defaults('file', $file)->name($name)->withoutMiddleware($staticFileMiddleware);
    }
    Route::get('/'.$file, [\App\Http\Controllers\FrontendPageController::class, 'redirect'])
        ->defaults('destination', $url)->withoutMiddleware($staticFileMiddleware);
}

foreach (config('frontend.aliases') as $legacy => $destination) {
    Route::get($legacy, [\App\Http\Controllers\FrontendPageController::class, 'redirect'])
        ->defaults('destination', $destination)->withoutMiddleware($staticFileMiddleware);
}

Route::get('/{directory}/{path}', function (string $directory, string $path) {
    if ($directory === 'pages' && !in_array('pages/'.$path, config('frontend.fragments'), true)) abort(404);

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
