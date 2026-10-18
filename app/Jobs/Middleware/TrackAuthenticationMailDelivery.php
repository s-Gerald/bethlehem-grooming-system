<?php

namespace App\Jobs\Middleware;

use App\Http\Controllers\EmailVerificationController;
use App\Services\AuthenticationMailDelivery;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TrackAuthenticationMailDelivery
{
    public function __construct(public readonly string $receipt, public readonly ?array $issuance = null) {}

    public function handle(object $job, callable $next): void
    {
        // The post-response attempt and database worker share the same lock.
        // This timeout exceeds the existing SMTP and worker timeouts.
        $lock = Cache::lock('auth-mail-send:'.hash('sha256', $this->receipt), 60);
        if (! $lock->get()) {
            $job->release(60);
            return;
        }
        try {
            if (in_array(AuthenticationMailDelivery::status($this->receipt), ['sent', 'superseded'], true)) return;
            $this->deliver($job, $next);
        } finally {
            $lock->release();
        }
    }

    private function deliver(object $job, callable $next): void
    {
        if ($job->notifiables->isEmpty()) {
            AuthenticationMailDelivery::record($this->receipt, 'superseded');
            return;
        }
        if (method_exists($job->notification, 'shouldSend')
            && ! $job->notification->shouldSend($job->notifiables->first(), 'mail')) {
            AuthenticationMailDelivery::record($this->receipt, 'superseded');
            return;
        }
        // Old serialized middleware has no issuance metadata; preserve its format.
        if (isset($this->issuance)) {
            $guard = $this->issuance;
            $current = $guard['model']::query()->find($guard['key']);
            if (! $current || ! $guard['hash']
                || ! hash_equals($guard['hash'], (string) $current->getAttribute($guard['hash_column']))
                || ! $current->getAttribute($guard['expiry_column'])
                || now()->greaterThanOrEqualTo($current->getAttribute($guard['expiry_column']))) {
                AuthenticationMailDelivery::record($this->receipt, 'superseded');
                return;
            }
        }
        $startedAt = microtime(true);
        AuthenticationMailDelivery::record($this->receipt, 'sending');
        try {
            // The worker must use the same deliverable configuration as the web app.
            EmailVerificationController::assertMailCanBeDelivered();
            $next($job);
        } catch (Throwable $exception) {
            AuthenticationMailDelivery::record($this->receipt, 'retrying');
            // Laravel retains the notification's existing attempts and backoff.
            throw $exception;
        }
        AuthenticationMailDelivery::record($this->receipt, 'sent', [
            'transport_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
