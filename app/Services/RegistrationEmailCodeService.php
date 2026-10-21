<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationEmailCodeService
{
    public const COOLDOWN_SECONDS = 45;
    public const TTL_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;

    public function send(User|PendingCustomerRegistration $registrant): string
    {
        self::assertMailerDoesNotLogCodes();
        EmailVerificationController::assertMailCanBeDelivered();
        return DB::transaction(function () use ($registrant): string {
            $locked = $registrant->newQuery()->whereKey($registrant->getKey())->lockForUpdate()->firstOrFail();
            if ($locked instanceof User && ($locked->email_verified_at || ! $locked->is_active || $locked->is_archived)) {
                throw new \RuntimeException('Account is not eligible for verification.');
            }
            $remaining = $locked->email_verification_last_sent_at
                ? max(0, $locked->email_verification_last_sent_at->timestamp + self::COOLDOWN_SECONDS - now()->timestamp)
                : 0;
            if ($remaining > 0) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'Please wait before requesting another code.',
                    'retry_after' => $remaining,
                ], 429)->header('Retry-After', (string) $remaining));
            }
            $attempts = $locked->email_verification_expires_at && now()->isBefore($locked->email_verification_expires_at)
                ? $locked->email_verification_attempts : 0;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $remaining = max(1, $locked->email_verification_expires_at->timestamp - now()->timestamp);
                throw new HttpResponseException(response()->json([
                    'success' => false, 'message' => 'Too many attempts. Try again later.', 'retry_after' => $remaining,
                ], 429)->header('Retry-After', (string) $remaining));
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($locked->email_verification_code_hash && Hash::check($code, $locked->email_verification_code_hash));
            $locked->update([
                'email_verification_token' => null,
                'email_verification_code_hash' => Hash::make($code),
                'email_verification_expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'email_verification_attempts' => $attempts,
                'email_verification_last_sent_at' => now(),
            ]);
            return AuthenticationMailDelivery::queue($locked, new VerifyEmailNotification($code), $locked,
                'email_verification_code_hash', 'email_verification_expires_at');
        });
    }

    public static function assertMailerDoesNotLogCodes(): void
    {
        // Includes Laravel's default SMTP-to-log failover configuration.
        $mailers = [(string) config('mail.default')];
        $checked = [];
        while ($name = array_pop($mailers)) {
            if (isset($checked[$name])) continue;
            $checked[$name] = true;
            $settings = config('mail.mailers.'.$name, []);
            $transport = $settings['transport'] ?? null;
            if ($transport === 'log'
                || (! app()->environment(['local', 'testing']) && ($transport === 'array' || ! $transport))) {
                throw new \RuntimeException('Registration codes require a deliverable mailer.');
            }
            foreach ($settings['mailers'] ?? [] as $child) $mailers[] = $child;
        }
    }
}
