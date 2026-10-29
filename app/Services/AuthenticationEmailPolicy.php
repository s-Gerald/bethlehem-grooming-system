<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthenticationEmailPolicy
{
    // Public feedback describes only requests by this source for the submitted
    // address. Private destination sending history is never returned here.
    public function publicRecoveryRequest(string $email): array
    {
        $this->assertSharedStore();
        $key = 'public-auth-recovery:'.hash_hmac('sha256', strtolower(trim($email)).'|'.request()->ip(), (string) config('app.key'));
        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) $this->reject(1, false, 'email_sending');
        try {
            $history = $this->history($key);
            [$wait, $limited] = $this->waiting($history, [], $history, [], 'password_recovery');
            if ($wait) $this->reject($wait, $limited);
            $history[] = now()->timestamp;
            Cache::put($key, $history, 86400);
            [$wait, $limited] = $this->waiting($history, [], $history, [], 'password_recovery');
            return ['resend_after' => $wait, 'retry_after' => $wait, 'resend_limited' => $limited,
                'next_eligible_at' => now()->addSeconds($wait)->toIso8601String()];
        } finally { $lock->release(); }
    }

    // Reserve under shared locks through durable insertion; a failed queue
    // restores every purpose, destination, source and provider reservation.
    public function send(string $email, callable $send, string $purpose = 'registration'): mixed
    {
        $this->assertSharedStore();
        $this->assertPurpose($purpose);
        $emailKey = $this->emailKey($email);
        $ipKey = 'auth-email:ip:'.hash('sha256', (string) request()->ip());
        $locks = [];
        $deferredRelease = false;
        try {
            foreach (['auth-email:provider', $emailKey, $ipKey] as $key) {
                $lock = Cache::lock($key.':lock', 300);
                if (! $lock->get()) $this->reject(1, false, 'email_sending');
                $locks[] = $lock;
            }
            $histories = [];
            foreach ([$emailKey, $ipKey, $emailKey.':'.$purpose, $ipKey.':'.$purpose, 'auth-email:provider'] as $key) {
                $histories[$key] = $this->history($key);
            }
            [$wait, $limited] = $this->waiting($histories[$emailKey], $histories[$ipKey], $histories[$emailKey.':'.$purpose],
                $histories[$ipKey.':'.$purpose], $purpose, $histories['auth-email:provider']);
            if ($wait) $this->reject($wait, $limited);
            try {
                foreach ($histories as $key => $history) Cache::put($key, [...$history, now()->timestamp], 86400);
                $result = $send();
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
        [$wait, $limited] = $this->waiting($this->history($emailKey), $this->history($ipKey),
            $this->history($emailKey.':'.$purpose), $this->history($ipKey.':'.$purpose), $purpose, $this->history('auth-email:provider'));
        return ['resend_after' => $wait, 'retry_after' => $wait,
            'next_eligible_at' => now()->addSeconds($wait)->toIso8601String(),
            'resend_limited' => $limited];
    }

    private function waiting(array $emails, array $ips, array $purposes, array $sources, string $purpose, array $provider = []): array
    {
        $settings = config('authentication.email.purposes.'.$purpose);
        $cooldown = $purposes ? max(0, end($purposes) + (int) ($settings['cooldown'] ?? config('authentication.email.cooldown', 60)) - now()->timestamp) : 0;
        $long = 0;
        if ($provider) {
            $limit = max(1, (int) config('authentication.email.provider_day', 100));
            if (count($provider) >= $limit) $long = max(1, $provider[count($provider) - $limit] + 86400 - now()->timestamp);
        }
        foreach ([[900, 'quarter_hour', $emails], [3600, 'hour', $emails], [86400, 'day', $emails],
            [3600, 'ip_hour', $ips], [86400, 'ip_day', $ips]] as [$window, $setting, $history]) {
            $recent = array_values(array_filter($history, fn ($at) => $at > now()->timestamp - $window));
            $limit = max(1, (int) config('authentication.email.'.$setting));
            if (count($recent) >= $limit) $long = max($long, $recent[count($recent) - $limit] + $window - now()->timestamp);
        }
        foreach ([[900, 'quarter_hour', $purposes], [3600, 'hour', $purposes], [86400, 'day', $purposes],
            [900, 'source_quarter_hour', $sources]] as [$window, $setting, $history]) {
            if (! isset($settings[$setting])) continue;
            $recent = array_values(array_filter($history, fn ($at) => $at > now()->timestamp - $window));
            $limit = max(1, (int) $settings[$setting]);
            if (count($recent) >= $limit) $long = max($long, $recent[count($recent) - $limit] + $window - now()->timestamp);
        }
        return [max($cooldown, $long), $long > $cooldown];
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
