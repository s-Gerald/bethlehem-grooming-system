<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

final class ProgressiveAuthThrottle
{
    public const FAILURES_PER_STEP = 5;
    public const COOLDOWNS = [60, 180, 300];
    public const RESET_SECONDS = 1800;

    public static function cooldown(int $level): int
    {
        return self::COOLDOWNS[max(0, min(2, $level - 1))];
    }

    // Challenge IDs and session IDs deliberately do not reset a source's history.
    public static function key(string $purpose, int|string $account): string
    {
        return 'progressive-auth:'.$purpose.':'.hash_hmac('sha256', $account.'|'.request()->ip(), (string) config('app.key'));
    }

    public static function blocked(string $key): ?array
    {
        $seconds = max(0, (Cache::get($key)['blocked_until'] ?? 0) - now()->timestamp);
        return $seconds ? ['status' => 'verification_cooldown', 'retry_after' => $seconds] : null;
    }

    // Call within the consumer's existing row/source lock. Returning rather than
    // throwing lets database-backed cache writes commit with rejected attempts.
    public static function recordFailure(string $key): ?array
    {
        $lock = Cache::lock($key.':failure-lock', 10);
        if (! $lock->get()) return ['status' => 'verification_cooldown', 'retry_after' => 1];
        try {
            $state = Cache::get($key, ['failures' => 0, 'level' => 0, 'blocked_until' => 0]);
            if ($blocked = self::blocked($key)) return $blocked;
            $state['failures']++;
            if ($state['failures'] >= self::FAILURES_PER_STEP) {
                $state['failures'] = 0;
                $state['level'] = min(3, $state['level'] + 1);
                $state['blocked_until'] = now()->timestamp + self::cooldown($state['level']);
            }
            Cache::put($key, $state, self::RESET_SECONDS);
            return self::blocked($key);
        } finally { $lock->release(); }
    }

    public static function clear(string $key): void
    {
        Cache::forget($key);
    }

    public static function attemptsRemaining(string $key): int
    {
        return self::blocked($key) ? 0 : self::FAILURES_PER_STEP - (Cache::get($key)['failures'] ?? 0);
    }

    // Histories contain successful issuances only; emergency windows are separate.
    public static function resendAfter(array $history): int
    {
        if (! $history) return 0;
        $level = 0;
        $previous = null;
        foreach ($history as $at) {
            $level = $previous === null || $at - $previous >= self::RESET_SECONDS ? 1 : min(3, $level + 1);
            $previous = $at;
        }
        return max(0, $previous + self::cooldown($level) - now()->timestamp);
    }

    public static function reject(array $blocked): never
    {
        throw new HttpResponseException(response()->json(['success' => false,
            'code' => $blocked['status'], 'message' => 'Too many attempts. Please wait before trying again.',
            ...$blocked], 429)->header('Retry-After', (string) $blocked['retry_after'])->header('Cache-Control', 'no-store'));
    }
}
