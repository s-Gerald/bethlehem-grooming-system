<?php

namespace App\Jobs\Middleware;

use App\Http\Controllers\EmailVerificationController;
use App\Services\AuthenticationMailDelivery;
use App\Services\LoginEmailChallengeService;
use App\Services\PasswordResetService;
use App\Services\PrivilegedCredentialChangeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
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
        // Receipt-only OTP jobs predate issuance guards and cannot prove validity.
        if (isset($job->notification->code) && ! isset($this->issuance)) {
            AuthenticationMailDelivery::record($this->receipt, 'superseded');
            return;
        }
        if (isset($this->issuance)) {
            $guard = $this->issuance;
            $current = $guard['model']::query()->find($guard['key']);
            if (! $current || ! $guard['hash']
                || ($current instanceof \App\Models\PendingCustomerRegistration && $current->retentionExpired())
                || $current->getAttribute('approved_at')
                || $current->getAttribute('confirmed_at')
                || $current->getAttribute('verified_token_hash')
                || $current->getAttribute('verification_attempts') >= PasswordResetService::MAX_ATTEMPTS
                || $current->getAttribute('failed_attempts') >= ($current instanceof \App\Models\LoginEmailChallenge
                    ? LoginEmailChallengeService::maxAttempts() : PrivilegedCredentialChangeService::MAX_CODE_ATTEMPTS)
                || ($current->getAttribute('poll_token_hash') && RateLimiter::tooManyAttempts(
                    'login-code:'.$current->getAttribute('poll_token_hash'), LoginEmailChallengeService::maxAttempts()))
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
