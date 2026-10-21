<?php

return [

    // Preparation only: a real provider and backend enforcement do not exist yet.
    'sms_verification' => [
        'available' => false,
        'enforced' => false,
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'livemode' => env('PAYMONGO_LIVE_MODE', false),
        'payment_methods' => explode(',', env('PAYMONGO_PAYMENT_METHODS', 'qrph,gcash,paymaya,card')),
        'return_url' => env('PAYMONGO_RETURN_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://127.0.0.1:8000')), '/').'/pages/admin/appointments.html'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
