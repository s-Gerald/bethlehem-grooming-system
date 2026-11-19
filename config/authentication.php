<?php

return [
    'magic_link_minutes' => (int) env('REGISTRATION_MAGIC_LINK_MINUTES', 30),
    'otp_minutes' => (int) env('LOGIN_CONFIRMATION_TTL_MINUTES', 10),
    'otp_source_attempts' => (int) env('AUTH_OTP_SOURCE_ATTEMPTS', 5),
    'otp_source_cooldowns' => [60, 180, 300],
    'otp_source_reset_seconds' => (int) env('AUTH_OTP_SOURCE_RESET_SECONDS', 1800),
    'otp_account_attempts' => (int) env('AUTH_OTP_ACCOUNT_ATTEMPTS', 50),
    'otp_ip_attempts' => (int) env('AUTH_OTP_IP_ATTEMPTS', 100),
    'otp_attempt_window' => (int) env('AUTH_OTP_ATTEMPT_WINDOW', 900),
    'outstanding_challenges' => (int) env('AUTH_LOGIN_OUTSTANDING_CHALLENGES', 10),
    'security' => [
        'failure_threshold' => (int) env('AUTH_SECURITY_FAILURE_THRESHOLD', 3),
        'source_threshold' => (int) env('AUTH_SECURITY_SOURCE_THRESHOLD', 3),
        'source_window' => (int) env('AUTH_SECURITY_SOURCE_WINDOW', 1800),
        'incomplete_seconds' => (int) env('AUTH_SECURITY_INCOMPLETE_SECONDS', 120),
        'retention_days' => (int) env('AUTH_SECURITY_RETENTION_DAYS', 90),
    ],
    'email' => [
        'cooldown' => (int) env('AUTH_EMAIL_COOLDOWN_SECONDS', 60),
        'hour' => (int) env('AUTH_EMAIL_HOURLY_LIMIT', 12),
        'day' => (int) env('AUTH_EMAIL_DAILY_LIMIT', 50),
        'ip_hour' => (int) env('AUTH_EMAIL_IP_HOURLY_LIMIT', 60),
        'ip_day' => (int) env('AUTH_EMAIL_IP_DAILY_LIMIT', 200),
        // Aggregate windows above remain authoritative across every purpose.
        'purposes' => [
            'registration' => [],
            'login' => [],
            'password_recovery' => [],
            'customer_security_change' => [],
            'privileged_change' => [],
            'security_notice' => ['cooldown' => 0],
            'security_alert' => ['cooldown' => 86400],
        ],
    ],
];
