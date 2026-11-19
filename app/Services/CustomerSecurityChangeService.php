<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\CustomerSecurityChange;
use App\Models\LoginEmailChallenge;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\CustomerEmailChangedNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\VerifyCustomerSecurityChangeNotification;
use App\Support\AccountEmail;
use App\Support\SecurityCode;
use App\Support\ProgressiveAuthThrottle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class CustomerSecurityChangeService
{
    public function pending(User $user, string $kind): ?array
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! app(AccountSessionService::class)->isUsable($token, $user)) return null;
        $change = CustomerSecurityChange::where('user_id', $user->getKey())->where('kind', $kind)->first();

        return $change && $this->isPending($change, $user, $token) ? $this->payload($change, $user) : null;
    }

    public function start(User $requester, string $kind, string $currentPassword, string $proposed): array
    {
        return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($kind, $currentPassword, $proposed): array {
            if ($error = $this->checkCurrentPassword($user, $currentPassword)) return $error;

            if ($kind === 'email') {
                if (strcasecmp($proposed, $user->email) === 0) {
                    throw ValidationException::withMessages(['email' => 'Enter a different email address.']);
                }
                AccountEmail::assertAvailable($proposed, $user, true);
            } elseif (Hash::check($proposed, $user->password_hash)) {
                throw ValidationException::withMessages(['password' => 'Choose a different password.']);
            }

            $previous = CustomerSecurityChange::where('user_id', $user->getKey())->where('kind', $kind)->lockForUpdate()->first();
            $retained = $previous && now()->lt($previous->expires_at);
            if ($retained) {
                if ($this->isPending($previous, $user, $token) && ($kind === 'email'
                    ? strcasecmp($proposed, $previous->new_email) === 0
                    : Hash::check($proposed, $previous->new_password_hash))) {
                    return ['status' => 'pending', 'challenge' => $this->payload($previous, $user)];
                }
                if ($blocked = $this->sendLimit($previous, $user)) return $blocked;
            }

            $change = new CustomerSecurityChange([
                'id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'kind' => $kind,
                'session_token_id' => $token->getKey(), 'account_fingerprint' => $this->fingerprint($user),
                'new_email' => $kind === 'email' ? $proposed : null,
                'new_password_hash' => $kind === 'password' ? Hash::make($proposed) : null,
                'expires_at' => $retained ? $previous->expires_at : now()->addMinutes(2 * SecurityCode::ttlMinutes()),
                'failed_attempts' => $retained ? $previous->failed_attempts : 0,
                'send_count' => $retained ? $previous->send_count : 0,
            ]);
            $previous?->delete();

            return $this->issueCode($change, $user, $previous?->code_hash);
        });
    }

    public function resend(User $requester, string $kind, string $id): array
    {
        return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($kind, $id): array {
            $change = $this->findPending($user, $token, $kind, $id);
            if (! $change) return ['status' => 'invalid'];
            if ($blocked = $this->sendLimit($change, $user)) return $blocked;

            return $this->issueCode($change, $user, $change->code_hash);
        });
    }

    public function cancel(User $requester, string $kind, string $id): array
    {
        return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($kind, $id): array {
            $change = $this->findPending($user, $token, $kind, $id);
            if (! $change) return ['status' => 'invalid'];
            // Keep the account's attempts and sending budget until the original deadline.
            $change->update(['code_hash' => null, 'new_password_hash' => null, 'new_email' => null, 'delivery_receipt' => null]);

            return ['status' => 'cancelled'];
        });
    }

    public function confirm(User $requester, string $kind, string $id, string $code): array
    {
        try {
            return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($kind, $id, $code): array {
                $change = $this->findPending($user, $token, $kind, $id);
                if (! $change) return ['status' => 'invalid'];
                if (now()->gte($change->code_expires_at)) return ['status' => 'expired'];
                if ($change->delivery_receipt && AuthenticationMailDelivery::status($change->delivery_receipt) === 'failed') {
                    return ['status' => 'delivery_failed'];
                }
                $key = ProgressiveAuthThrottle::key('customer-security-code', $user->getKey());
                if ($blocked = ProgressiveAuthThrottle::blocked($key)) return $blocked;
                if (! Hash::check($code, $change->code_hash)) {
                    $change->increment('failed_attempts');

                    return ProgressiveAuthThrottle::recordFailure($key) ?? ['status' => 'invalid_code'];
                }

                $oldEmail = $user->email;
                if ($kind === 'email') {
                    app(AccountEmailChangeService::class)->complete($user, $change->new_email);
                } else {
                    $user->password_hash = $change->new_password_hash;
                    $user->save();
                    $user->tokens()->delete();
                    PasswordResetRequest::where('user_id', $user->getKey())->delete();
                    LoginEmailChallenge::where('user_id', $user->getKey())->delete();
                }
                CustomerSecurityChange::where('user_id', $user->getKey())->delete();
                ProgressiveAuthThrottle::clear($key);
                DB::afterCommit(function () use ($user, $kind, $oldEmail): void {
                    try {
                        if ($kind === 'email') {
                            AuthenticationMailDelivery::queue(Notification::route('mail', $oldEmail),
                                new CustomerEmailChangedNotification, allowImmediate: false, purpose: 'security_notice');
                        } else {
                            AuthenticationMailDelivery::queue($user, new PasswordChangedNotification,
                                allowImmediate: false, purpose: 'security_notice');
                        }
                    } catch (Throwable) {
                        Log::warning("Customer {$kind} confirmation could not be queued.", ['user_id' => $user->getKey()]);
                    }
                });

                return ['status' => 'completed'];
            });
        } catch (UniqueConstraintViolationException $error) {
            if ($kind !== 'email') throw $error;

            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        }
    }

    public function canDeliver(string $id): bool
    {
        $change = CustomerSecurityChange::find($id);
        if (! $change) return false;
        $user = User::find($change->user_id);
        $token = PersonalAccessToken::find($change->session_token_id);

        return $user && $token && app(AccountSessionService::class)->isUsable($token, $user)
            && $this->isPending($change, $user, $token);
    }

    public function checkCurrentPassword(User $user, string $password): ?array
    {
        $key = ProgressiveAuthThrottle::key('customer-security-password', $user->getKey());
        if ($blocked = ProgressiveAuthThrottle::blocked($key)) return $blocked;
        if (! Hash::check($password, $user->password_hash)) {
            return ProgressiveAuthThrottle::recordFailure($key) ?? ['status' => 'invalid_password',
                'errors' => ['current_password' => ['Incorrect current password.']]];
        }
        ProgressiveAuthThrottle::clear($key);
        return null;
    }

    private function withAccount(User $requester, callable $action): array
    {
        app(AuthenticationEmailPolicy::class)->assertSharedStore();

        return DB::transaction(function () use ($requester, $action): array {
            $user = User::whereKey($requester->getKey())->lockForUpdate()->firstOrFail();
            $sessions = app(AccountSessionService::class);
            abort_unless($user->role === 'customer' && $sessions->accountIsUsable($user), 403);
            $session = $requester->currentAccessToken();
            abort_unless($session instanceof PersonalAccessToken && $session->exists, 401);
            $token = $user->tokens()->whereKey($session->getKey())->lockForUpdate()->first();
            abort_unless($token && $sessions->isUsable($token, $user), 401, 'Please sign in again.');

            return $action($user, $token);
        }, 3);
    }

    private function findPending(User $user, PersonalAccessToken $token, string $kind, string $id): ?CustomerSecurityChange
    {
        $change = CustomerSecurityChange::whereKey($id)->where('user_id', $user->getKey())
            ->where('kind', $kind)->lockForUpdate()->first();

        return $change && $this->isPending($change, $user, $token) ? $change : null;
    }

    private function isPending(CustomerSecurityChange $change, User $user, PersonalAccessToken $token): bool
    {
        return $user->role === 'customer' && $change->code_hash && now()->lt($change->expires_at)
            && (int) $change->session_token_id === (int) $token->getKey()
            && hash_equals($change->account_fingerprint, $this->fingerprint($user));
    }

    private function fingerprint(User $user): string
    {
        return hash('sha256', $user->email.'|'.$user->password_hash);
    }

    private function sendLimit(CustomerSecurityChange $change, User $user): ?array
    {
        $wait = $this->resendAfter($change, $user);

        return $wait ? ['status' => 'cooldown', 'retry_after' => $wait] : null;
    }

    private function resendAfter(CustomerSecurityChange $change, User $user): int
    {
        return max(0, $change->last_sent_at->timestamp + SecurityCode::RESEND_COOLDOWN_SECONDS - now()->timestamp,
            app(AuthenticationEmailPolicy::class)->status($change->new_email ?? $user->email, 'customer_security_change')['resend_after']);
    }

    private function issueCode(CustomerSecurityChange $change, User $user, ?string $oldHash): array
    {
        EmailVerificationController::assertMailCanBeDelivered();
        do { $code = SecurityCode::generate(); } while ($oldHash && Hash::check($code, $oldHash));
        $change->fill([
            'code_hash' => Hash::make($code), 'last_sent_at' => now(), 'send_count' => $change->send_count + 1,
            'code_expires_at' => min(now()->addMinutes(SecurityCode::ttlMinutes()), $change->expires_at),
        ])->save();
        $recipient = Notification::route('mail', $change->new_email ?? $user->email);
        $receipt = AuthenticationMailDelivery::queue($recipient,
            new VerifyCustomerSecurityChangeNotification($code, $change->kind, $change->id),
            $change, 'code_hash', 'code_expires_at', purpose: 'customer_security_change');
        $change->update(['delivery_receipt' => $receipt]);

        return ['status' => 'pending', 'challenge' => $this->payload($change, $user)];
    }

    private function payload(CustomerSecurityChange $change, User $user): array
    {
        return [
            'id' => $change->id, 'kind' => $change->kind, 'email' => $change->new_email ?? $user->email,
            'expires_at' => $change->expires_at->toIso8601String(), 'code_expires_at' => $change->code_expires_at->toIso8601String(),
            'resend_after' => $this->resendAfter($change, $user),
            'attempts_remaining' => ProgressiveAuthThrottle::attemptsRemaining(ProgressiveAuthThrottle::key('customer-security-code', $user->getKey())),
            'retry_after' => ProgressiveAuthThrottle::blocked(ProgressiveAuthThrottle::key('customer-security-code', $user->getKey()))['retry_after'] ?? 0,
            'email_delivery_token' => $change->delivery_receipt,
        ];
    }
}
