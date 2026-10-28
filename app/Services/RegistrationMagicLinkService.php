<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegistrationMagicLinkService
{
    public function send(User|PendingCustomerRegistration $registrant): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        return app(AuthenticationEmailPolicy::class)->send($registrant->email, fn () => DB::transaction(function () use ($registrant) {
            $locked = $registrant->newQuery()->whereKey($registrant->getKey())->lockForUpdate()->firstOrFail();
            if (($locked instanceof User && ! CustomerRegistrationService::recoverableUser($locked))
                || ($locked instanceof PendingCustomerRegistration && $locked->retentionExpired())) {
                throw new \RuntimeException('Account is not eligible for verification.');
            }
            $token = Str::random(64);
            $locked->update([
                'email_verification_token' => hash('sha256', $token),
                'email_verification_code_hash' => null,
                'email_verification_expires_at' => now()->addMinutes(max(1, (int) config('authentication.magic_link_minutes', 30))),
                'email_verification_last_sent_at' => now(),
                'registration_recovery_token_hash' => null,
                'registration_recovery_expires_at' => null,
            ]);
            return AuthenticationMailDelivery::queue($locked, new VerifyEmailNotification($token), $locked,
                'email_verification_token', 'email_verification_expires_at');
        }));
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
