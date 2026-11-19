<?php

namespace App\Services;

use App\Support\ProgressiveAuthThrottle;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthenticationEmailPolicy
{
    // Reserve under shared locks through durable insertion; a failed queue
    // restores every purpose, destination and source reservation.
    public function send(string $email, callable $send, string $purpose = 'registration'): mixed
    {
        $this->assertSharedStore();
        $this->assertPurpose($purpose);
        $emailKey = $this->emailKey($email);
        $ipKey = 'auth-email:ip:'.hash('sha256', (string) request()->ip());
        $locks = [];
        $deferredRelease = false;
        try {
            foreach ([$emailKey, $ipKey] as $key) {
                $lock = Cache::lock($key.':lock', 300);
                if (! $lock->get()) $this->reject(1, false, 'email_sending');
                $locks[] = $lock;
            }
            $histories = [];
            foreach ([$emailKey.':interactive', $ipKey, $emailKey.':'.$purpose] as $key) {
                $histories[$key] = $this->history($key);
            }
            [$wait, $limited] = $this->waiting($histories[$emailKey.':interactive'], $histories[$ipKey], $histories[$emailKey.':'.$purpose], $purpose);
            if ($wait) $this->reject($wait, $limited);
            try {
                foreach ($histories as $key => $history) {
                    if ($key === $emailKey.':interactive' && ! $this->interactive($purpose)) continue;
                    Cache::put($key, [...$history, now()->timestamp], 86400);
                }
                $result = $send();
                if (is_array($result) && isset($result['status']) && ! in_array($result['status'], ['sent', 'queued', 'resent', 'pending', 'authorizing'], true)) {
                    foreach ($histories as $key => $history) Cache::put($key, $history, 86400);
                    return $result;
                }
                $connection = DB::connection();
                $cache = config('cache.stores.'.config('cache.default'), []);
                $transactionalCache = ($cache['driver'] ?? null) === 'database'
                    && ($cache['connection'] ?? DB::getDefaultConnection()) === $connection->getName();
                if ($connection->transactionLevel() > 0 && ! $transactionalCache) {
                    // Redis/array (or a separate cache DB) cannot roll back with
                    // the issuance DB. Keep the locks until its outer outcome.
                    $release = static function () use ($locks): void {
                        foreach (array_reverse($locks) as $lock) $lock->release();
                    };
                    $connection->afterCommit($release);
                    $connection->afterRollBack(static function () use ($histories, $release): void {
                        try {
                            foreach ($histories as $key => $history) Cache::put($key, $history, 86400);
                        } finally { $release(); }
                    });
                    $deferredRelease = true;
                }
                return $result;
            } catch (\Throwable $exception) {
                foreach ($histories as $key => $history) Cache::put($key, $history, 86400);
                throw $exception;
            }
        } finally {
            if (! $deferredRelease) foreach (array_reverse($locks) as $lock) $lock->release();
        }
    }

    public function status(string $email, string $purpose = 'registration'): array
    {
        $this->assertPurpose($purpose);
        $emailKey = $this->emailKey($email);
        $ipKey = 'auth-email:ip:'.hash('sha256', (string) request()->ip());
        [$wait, $limited] = $this->waiting($this->history($emailKey.':interactive'), $this->history($ipKey),
            $this->history($emailKey.':'.$purpose), $purpose);
        return ['resend_after' => $wait, 'retry_after' => $wait,
            'next_eligible_at' => now()->addSeconds($wait)->toIso8601String(),
            'resend_limited' => $limited];
    }

    private function waiting(array $emails, array $ips, array $purposes, string $purpose): array
    {
        $settings = config('authentication.email.purposes.'.$purpose);
        $cooldown = $this->interactive($purpose) ? ProgressiveAuthThrottle::resendAfter($emails) : ($purposes ? max(0, end($purposes) + (int) ($settings['cooldown'] ?? config('authentication.email.cooldown', 60)) - now()->timestamp) : 0);
        $long = 0;
        foreach ([[3600, 'ip_hour', $ips], [86400, 'ip_day', $ips]] as [$window, $setting, $history]) {
            $recent = array_values(array_filter($history, fn ($at) => $at > now()->timestamp - $window));
            $limit = max(1, (int) config('authentication.email.'.$setting));
            if (count($recent) >= $limit) $long = max($long, $recent[count($recent) - $limit] + $window - now()->timestamp);
        }
        return [max($cooldown, $long), $long > $cooldown];
    }

    private function interactive(string $purpose): bool
    {
        return ! in_array($purpose, ['security_notice', 'security_alert'], true);
    }

    private function assertPurpose(string $purpose): void
    {
        if (! is_array(config('authentication.email.purposes.'.$purpose))) {
            throw new \InvalidArgumentException('Unknown authentication email purpose.');
        }
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

    public function assertSharedStore(): void
    {
        $stores = [config('cache.default'), config('cache.limiter') ?: config('cache.default')];
        if (! app()->environment(['local', 'testing']) && array_filter($stores,
            fn ($store) => ! in_array(config('cache.stores.'.$store.'.driver'), ['database', 'redis'], true))) {
            throw new \RuntimeException('Authentication requires a shared persistent database or Redis cache.');
        }
    }
}
