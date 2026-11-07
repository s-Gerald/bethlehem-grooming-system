<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationMagicLinkService
{
    public const MIN_DELIVERY_SECONDS = 60;

    public static function lifetimeMinutes(): int
    {
        return min(30, max(1, (int) config('authentication.magic_link_minutes', 30)));
    }

    public static function expiresAt(PendingCustomerRegistration $registrant): ?\Illuminate\Support\Carbon
    {
        $expiry = $registrant->email_verification_expires_at?->copy();
        if ($expiry && $registrant->email_verification_last_sent_at) {
            $expiry = $expiry->min($registrant->email_verification_last_sent_at->copy()->addMinutes(self::lifetimeMinutes()));
        }
        if ($expiry) {
            $deadline = $registrant->retentionExpiresAt();
            return $deadline ? $expiry->min($deadline) : null;
        }
        return $expiry;
    }

    public function send(PendingCustomerRegistration $registrant): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        return app(AuthenticationEmailPolicy::class)->send($registrant->email, fn () => $this->issue($registrant));
    }

    private function issue(PendingCustomerRegistration $registrant): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        return DB::transaction(function () use ($registrant) {
            $locked = $registrant->newQuery()->whereKey($registrant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->retentionExpired() || $locked->registration_approved_at || $locked->registration_completed_at) {
                throw new \RuntimeException('Account is not eligible for verification.');
            }
            $issuedAt = now()->startOfSecond();
            $expiresAt = $issuedAt->copy()->addMinutes(self::lifetimeMinutes());
            $expiresAt = $expiresAt->min($locked->retentionExpiresAt());
            if ($expiresAt->lessThan($issuedAt->copy()->addSeconds(self::MIN_DELIVERY_SECONDS))) {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                    'success' => false, 'code' => 'registration_restart_required',
                    'message' => 'This registration is almost expired. Start again.',
                ], 422)->header('Cache-Control', 'no-store'));
            }
            $token = Str::random(64);
            $locked->update([
                'email_verification_token' => hash('sha256', $token),
                'email_verification_expires_at' => $expiresAt,
                'email_verification_last_sent_at' => $issuedAt,
            ]);
            return AuthenticationMailDelivery::queue($locked, new VerifyEmailNotification($token), $locked,
                'email_verification_token', 'email_verification_expires_at');
        });
    }

    public static function assertMailerDoesNotLogSecrets(): void
    {
        $mailers = [(string) config('mail.default')];
        $seen = [];
        while ($mailers) {
            $name = array_pop($mailers);
            if (isset($seen[$name])) continue;
            $seen[$name] = true;
            $settings = config('mail.mailers.'.$name, []);
            if ($name === 'log' || ($settings['transport'] ?? null) === 'log') {
                throw new \RuntimeException('Authentication emails cannot use a logging mail transport.');
            }
            foreach ($settings['mailers'] ?? [] as $child) $mailers[] = $child;
        }
    }
}
