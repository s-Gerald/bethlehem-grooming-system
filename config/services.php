<?php

return [

    'iprogsms' => [
        'base_url' => 'https://www.iprogsms.com',
        'api_token' => env('IPROGSMS_API_TOKEN'),
        'otp_expires_in_minutes' => 5,
    ],

    'sms_verification' => [
        'available' => env('SMS_VERIFICATION_AVAILABLE', false),
        'enforced' => env('SMS_VERIFICATION_ENFORCED', false),
        'resend_seconds' => 60,
        'max_attempts' => 5,
        'attempt_window_seconds' => 3600,
        'user_hourly_limit' => 5,
        'phone_hourly_limit' => 5,
        'ip_hourly_limit' => 20,
        'global_hourly_limit' => 100,
        'global_daily_limit' => 500,
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
