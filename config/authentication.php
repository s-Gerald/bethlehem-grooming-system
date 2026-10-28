<?php

return [
    'magic_link_minutes' => (int) env('REGISTRATION_MAGIC_LINK_MINUTES', 30),
    'otp_minutes' => (int) env('LOGIN_CONFIRMATION_TTL_MINUTES', 10),
    'otp_attempts' => (int) env('AUTH_OTP_ATTEMPTS', 5),
    'otp_account_attempts' => (int) env('AUTH_OTP_ACCOUNT_ATTEMPTS', 5),
    'otp_ip_attempts' => (int) env('AUTH_OTP_IP_ATTEMPTS', 25),
    'otp_attempt_window' => 900,
    'email' => [
        'cooldown' => (int) env('AUTH_EMAIL_COOLDOWN_SECONDS', 60),
        'quarter_hour' => (int) env('AUTH_EMAIL_QUARTER_HOUR_LIMIT', 3),
        'hour' => (int) env('AUTH_EMAIL_HOURLY_LIMIT', 6),
        'day' => (int) env('AUTH_EMAIL_DAILY_LIMIT', 12),
        'ip_hour' => (int) env('AUTH_EMAIL_IP_HOURLY_LIMIT', 30),
        'ip_day' => (int) env('AUTH_EMAIL_IP_DAILY_LIMIT', 100),
    ],
    // Disabled by default. Any deliberate transition must have a fixed end.
    'legacy_links_until' => env('REGISTRATION_LEGACY_LINKS_UNTIL'),
];
