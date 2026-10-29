<?php

namespace App\Services;

use App\Jobs\Middleware\TrackAuthenticationMailDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuthenticationMailDelivery
{
    public static function queue(
        object $recipient,
        Notification $notification,
        ?Model $issuance = null,
        string $hashColumn = 'token_hash',
        string $expiryColumn = 'expires_at',
        bool $allowImmediate = true,
        ?string $purpose = null,
    ): string
    {
        if ($purpose !== null) {
            $email = $recipient->routeNotificationFor('mail', $notification);
            if (! is_string($email)) throw new \RuntimeException('Authentication mail requires one destination.');
            return app(AuthenticationEmailPolicy::class)->send($email,
                fn () => self::queue($recipient, $notification, $issuance, $hashColumn, $expiryColumn, $allowImmediate), $purpose);
        }
        $receipt = Str::random(64);
        self::record($receipt, 'queued', [
            'request_ms' => max(0, (int) round((microtime(true) - (float) request()->server('REQUEST_TIME_FLOAT', microtime(true))) * 1000)),
        ]);
        $notification->deliveryReceipt = $receipt;
        $guard = $issuance ? [
            'model' => $issuance::class,
            'key' => $issuance->getKey(),
            'hash_column' => $hashColumn,
            'hash' => $issuance->getAttribute($hashColumn),
            'expiry_column' => $expiryColumn,
        ] : null;
        $tracking = new TrackAuthenticationMailDelivery($receipt, $guard);
        $notification->through([$tracking]);
        if ($queue = config('mail.authentication.queue')) {
            $notification->onQueue($queue);
        }

        try {
            if ($allowImmediate && config('mail.authentication.delivery') === 'immediate') {
                $notification->onConnection('database');
            }
            $connection = $notification->connection ?? config('queue.default');
            $settings = config('queue.connections.'.$connection, []);
            if (($settings['driver'] ?? null) === 'database') {
                // Insert the encrypted job on the issuance transaction, not in an
                // after-commit callback that can fail after invalidating the code.
                if (($settings['connection'] ?? config('database.default')) !== DB::getDefaultConnection()) {
                    throw new \RuntimeException('Authentication mail must share the issuance database.');
                }
                $notification->beforeCommit();
            } elseif (! app()->environment('testing')) {
                throw new \RuntimeException('Authentication mail requires the durable database queue.');
            }
            // Persist encrypted work before responding, even in immediate mode.
            $recipient->notify($notification);
            if ($allowImmediate && config('mail.authentication.delivery') === 'immediate') {
                DB::afterCommit(function () use ($recipient, $notification, $tracking): void {
                    app()->terminating(function () use ($recipient, $notification, $tracking): void {
                        try {
                            $tracking->handle(new SendQueuedNotifications($recipient, $notification, ['mail']),
                                fn () => $recipient->notifyNow($notification));
                        } catch (Throwable) {
                            // The encrypted job already exists. Let the normal worker
                            // retry; never expose SMTP details through a public response.
                        }
                    });
                });
            }
        } catch (Throwable $exception) {
            Cache::forget(self::key($receipt));
            throw $exception;
        }

        return $receipt;
    }

    public static function record(string $receipt, string $status, array $timing = []): void
    {
        $previous = Cache::get(self::key($receipt));
        $requestedAt = is_array($previous) ? $previous['requested_at'] : microtime(true);
        Cache::put(self::key($receipt), [
            'status' => $status,
            'requested_at' => $requestedAt,
        ], now()->addMinutes(max(
            1440, (int) config('authentication.otp_minutes', 10),
            (int) config('auth.passwords.users.expire', 15),
        ) + 5));
        Log::info('Authentication mail delivery', [
            'delivery_id' => substr(hash('sha256', $receipt), 0, 16),
            'status' => $status,
            'elapsed_ms' => (int) round((microtime(true) - $requestedAt) * 1000),
            ...$timing,
        ]);
    }

    public static function status(string $receipt): string
    {
        $value = Cache::get(self::key($receipt), 'unavailable');
        return is_array($value) ? $value['status'] : $value;
    }

    private static function key(string $receipt): string
    {
        return 'auth-mail-delivery:'.hash('sha256', $receipt);
    }
}
