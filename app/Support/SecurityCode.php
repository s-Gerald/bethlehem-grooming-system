<?php

namespace App\Support;

final class SecurityCode
{
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    public static function generate(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('app.privileged_credential_change_code_ttl_minutes', 10));
    }
}
