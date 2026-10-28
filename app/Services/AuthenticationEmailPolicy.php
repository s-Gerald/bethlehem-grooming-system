<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

class AuthenticationEmailPolicy
{
    // Holds both locks through durable queue insertion. Failed queueing consumes
    // no quota, and callers' issuance transactions roll back on rejection.
    public function send(string $email, callable $send): mixed
    {
        $this->assertSharedStore();
        $emailKey = $this->emailKey($email);
        $ipKey = 'auth-email:ip:'.hash('sha256', (string) request()->ip());
        $emailLock = Cache::lock($emailKey.':lock', 300);
        $ipLock = Cache::lock($ipKey.':lock', 300);
        if (! $emailLock->get()) $this->reject(1, false, 'email_sending');
        try {
            if (! $ipLock->get()) $this->reject(1, false, 'email_sending');
            try {
                $emails = $this->history($emailKey);
                $ips = $this->history($ipKey);
                [$wait, $limited] = $this->waiting($emails, $ips);
                if ($wait) $this->reject($wait, $limited);
                // Reserve before queueing: a cache outage must not queue an
                // email and then incorrectly report that queueing failed.
                Cache::put($emailKey, [...$emails, now()->timestamp], 86400);
                Cache::put($ipKey, [...$ips, now()->timestamp], 86400);
                try {
                    return $send();
                } catch (\Throwable $exception) {
                    Cache::put($emailKey, $emails, 86400);
                    Cache::put($ipKey, $ips, 86400);
                    throw $exception;
                }
            } finally {
                $ipLock->release();
            }
        } finally {
            $emailLock->release();
        }
    }

    public function status(string $email): array
    {
        [$wait, $limited] = $this->waiting($this->history($this->emailKey($email)),
            $this->history('auth-email:ip:'.hash('sha256', (string) request()->ip())));
        return ['resend_after' => $wait, 'retry_after' => $wait,
            'next_eligible_at' => now()->addSeconds($wait)->toIso8601String(),
            'resend_limited' => $limited];
    }

    private function waiting(array $emails, array $ips): array
    {
        $cooldown = $emails ? max(0, end($emails) + (int) config('authentication.email.cooldown', 60) - now()->timestamp) : 0;
        $long = 0;
        foreach ([[900, 'quarter_hour', $emails], [3600, 'hour', $emails], [86400, 'day', $emails],
            [3600, 'ip_hour', $ips], [86400, 'ip_day', $ips]] as [$window, $setting, $history]) {
            $recent = array_values(array_filter($history, fn ($at) => $at > now()->timestamp - $window));
            $limit = max(1, (int) config('authentication.email.'.$setting));
            if (count($recent) >= $limit) $long = max($long, $recent[count($recent) - $limit] + $window - now()->timestamp);
        }
        return [max($cooldown, $long), $long > $cooldown];
    }

    private function history(string $key): array
    {
        return array_values(array_filter(Cache::get($key, []), fn ($at) => $at > now()->timestamp - 86400));
    }

    private function emailKey(string $email): string
    {
        return 'auth-email:destination:'.hash('sha256', strtolower(trim($email)));
    }

    private function reject(int $seconds, bool $limited, ?string $code = null): never
    {
        throw new HttpResponseException(response()->json([
            'success' => false, 'code' => $code ?? ($limited ? 'email_rate_limited' : 'email_cooldown'),
            'message' => $limited ? 'Too many requests. Try again later.' : 'Please wait before requesting another email.',
            'retry_after' => $seconds, 'resend_after' => $seconds, 'resend_limited' => $limited,
            'next_eligible_at' => now()->addSeconds($seconds)->toIso8601String(),
        ], 429)->header('Retry-After', (string) $seconds)->header('Cache-Control', 'no-store'));
    }

    private function assertSharedStore(): void
    {
        if (! app()->environment(['local', 'testing'])
            && ! in_array(config('cache.stores.'.config('cache.default').'.driver'), ['database', 'redis'], true)) {
            throw new \RuntimeException('Authentication requires a shared persistent database or Redis cache.');
        }
    }
}
