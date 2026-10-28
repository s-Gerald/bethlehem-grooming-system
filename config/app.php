<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    | The browser-facing origin used in links sent by email. Keep this
    | separate from APP_URL when the API and static pages use different hosts.
    */
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost')),


    'privileged_credential_change_code_ttl_minutes' => (int) env(
        'PRIVILEGED_CREDENTIAL_CHANGE_CODE_TTL_MINUTES',
        10,
    ),

    // The hours setting wins; explicitly configured legacy days remain supported.
    'pending_registration_retention_hours' => (int) env('PENDING_REGISTRATION_RETENTION_HOURS',
        env('PENDING_REGISTRATION_RETENTION_DAYS') !== null
            ? max(1, (int) env('PENDING_REGISTRATION_RETENTION_DAYS')) * 24 : 48),

    'privileged_seed_accounts' => [
        'admin' => [
            'username' => env('ADMIN_SEED_USERNAME', 'Admin'),
            'email' => env('ADMIN_SEED_EMAIL'),
            'phone' => env('ADMIN_SEED_PHONE'),
            'password' => env('ADMIN_SEED_PASSWORD'),
        ],
        'staff' => [
            'username' => env('STAFF_SEED_USERNAME', 'groomingstaff'),
            'email' => env('STAFF_SEED_EMAIL', 'bethlehem.staff.test@gmail.com'),
            'phone' => env('STAFF_SEED_PHONE'),
            'password' => env('STAFF_SEED_PASSWORD'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'Asia/Manila',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
