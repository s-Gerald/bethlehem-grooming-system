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
        // SMS eligibility only: portability means prefixes do not prove the current carrier.
        'allowed_prefixes' => [
            '0905', '0906', '0915', '0916', '0917', '0926', '0927', '0935', '0936', '0937', '0945',
            '0953', '0954', '0955', '0956', '0957', '0958', '0959', '0965', '0966', '0967',
            '0975', '0976', '0977', '0978', '0979', '0991', '0992', '0993', '0994', '0995', '0996', '0997',
        ],
        'resend_seconds' => 60,
        'ip_hourly_limit' => 60,
        'global_hourly_limit' => 100,
        'global_daily_limit' => (int) env('SMS_GLOBAL_DAILY_LIMIT', 500),
    ],

    'grooming_completion_sms' => [
        'enabled' => env('GROOMING_COMPLETION_SMS_ENABLED', false),
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

    'paymongo' => [
        // Legacy credentials are Test-only defaults when the new variables are absent.
        'test' => [
            'secret_key' => env('PAYMONGO_TEST_SECRET_KEY', env('PAYMONGO_SECRET_KEY')),
            'webhook_secret' => env('PAYMONGO_TEST_WEBHOOK_SECRET', env('PAYMONGO_WEBHOOK_SECRET')),
        ],
        'live' => [
            'secret_key' => env('PAYMONGO_LIVE_SECRET_KEY'),
            'webhook_secret' => env('PAYMONGO_LIVE_WEBHOOK_SECRET'),
        ],
        'livemode' => env('PAYMONGO_LIVE_MODE', false),
        'payment_methods' => array_values(array_filter(array_map('trim', explode(',', env('PAYMONGO_PAYMENT_METHODS', 'qrph'))))),
        'return_url' => env('PAYMONGO_RETURN_URL', rtrim(env('FRONTEND_URL', env('APP_URL', 'http://127.0.0.1:8000')), '/').'/admin/grooming'),
    ],

];
