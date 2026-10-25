<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Exceptions\HttpResponseException;

class SmsSendBudget
{
    public function reserve(int $userId, string $phone, ?string $ip = null): void
    {
        $lock = Cache::lock('sms:send-budget', 5);
        if (! $lock->get()) {
            $this->fail();
        }
        try {
            $limits = [
                ['user:'.$userId, 'user_hourly_limit', 5, 3600],
                ['phone:'.hash('sha256', $phone), 'phone_hourly_limit', 5, 3600],
                ...($ip !== null ? [['ip:'.hash('sha256', $ip), 'ip_hourly_limit', 20, 3600]] : []),
                ['user-day:'.$userId, 'user_daily_limit', 20, 86400],
                ['phone-day:'.hash('sha256', $phone), 'phone_daily_limit', 20, 86400],
                ['global:hour', 'global_hourly_limit', 100, 3600],
                ['global:day', 'global_daily_limit', 500, 86400],
            ];
            foreach ($limits as [$key, $setting, $default, $seconds]) {
                if (RateLimiter::tooManyAttempts('sms:'.$key, config('services.sms_verification.'.$setting, $default))) {
                    $this->fail(RateLimiter::availableIn('sms:'.$key));
                }
            }
            foreach ($limits as [$key, $setting, $default, $seconds]) {
                RateLimiter::hit('sms:'.$key, $seconds);
            }
        } finally {
            $lock->release();
        }
    }

    private function fail(int $seconds = 1): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'code' => 'sms_send_limit',
            'message' => 'SMS sending limit reached. Please try again later.', 'resend_after' => $seconds], 429));
    }
}
