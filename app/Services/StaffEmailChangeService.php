<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\StaffEmailChange;
use App\Models\User;
use App\Notifications\StaffEmailChangedNotification;
use App\Notifications\VerifyStaffEmailChangeNotification;
use App\Support\AccountEmail;
use App\Support\SecurityCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class StaffEmailChangeService
{
    public function pending(User $user): ?array
    {
        $token = $user->currentAccessToken();
        if (! $token instanceof Model || ! $token->exists || ! self::eligible($user)) return null;
        $change = StaffEmailChange::where('user_id', $user->getKey())->first();
        return $change && $this->valid($change, $user, $token) ? $this->payload($change) : null;
    }

    public function start(User $requester, string $email, string $password): array
    {
        $email = AccountEmail::normalize($email);
        Validator::make(['email' => $email], ['email' => AccountEmail::rules()])->validate();
        return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($email, $password): array {
            if (! Hash::check($password, $user->password_hash)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            if (strcasecmp($email, $user->email) === 0) {
                throw ValidationException::withMessages(['email' => 'Enter a different email address.']);
            }
            AccountEmail::assertAvailable($email, $user, true);
            $previous = StaffEmailChange::where('user_id', $user->getKey())->lockForUpdate()->first();
            $retained = $previous && now()->lt($previous->expires_at);
            if ($retained && $previous->failed_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if ($retained && ! $previous->cancelled_at && $previous->new_email === $email && $this->valid($previous, $user, $token)) {
                return ['status' => 'pending', 'pending_email_change' => $this->payload($previous)];
            }
            if ($retained && ($remaining = $this->resendAfter($previous))) return ['status' => 'cooldown', 'retry_after' => $remaining];
            $code = SecurityCode::generate();
            $expires = $retained ? $previous->expires_at : now()->addMinutes(2 * SecurityCode::ttlMinutes());
            $attempts = $retained ? $previous->failed_attempts : 0;
            $previous?->delete();
            $change = StaffEmailChange::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->getKey(), 'session_token_id' => $token->getKey(),
                'current_email' => $user->email, 'new_email' => $email, 'account_fingerprint' => self::fingerprint($user),
                'stage' => 'current', 'code_hash' => Hash::make('current|'.$code), 'failed_attempts' => $attempts,
                'code_expires_at' => min(now()->addMinutes(SecurityCode::ttlMinutes()), $expires),
                'expires_at' => $expires, 'last_sent_at' => now(),
            ]);
            $receipt = $this->send($change, $code);
            return ['status' => 'pending', 'pending_email_change' => $this->payload($change), 'email_delivery_token' => $receipt];
        });
    }

    public function confirm(User $requester, string $id, string $stage, string $code): array
    {
        try {
            return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($id, $stage, $code): array {
                $change = $this->lockedChange($user, $id);
                if (! $this->valid($change, $user, $token)) return ['status' => 'invalid'];
                if ($change->stage !== $stage) return ['status' => 'stale_stage'];
                if (now()->gte($change->code_expires_at)) return ['status' => 'expired'];
                if ($change->failed_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
                if (! Hash::check($stage.'|'.$code, $change->code_hash)) {
                    $change->increment('failed_attempts');
                    return ['status' => $change->failed_attempts >= SecurityCode::MAX_ATTEMPTS ? 'locked' : 'invalid_code'];
                }
                if ($stage === 'current') {
                    do { $newCode = SecurityCode::generate(); } while ($newCode === $code);
                    $change->update([
                        'stage' => 'new', 'code_hash' => Hash::make('new|'.$newCode),
                        'code_expires_at' => min(now()->addMinutes(SecurityCode::ttlMinutes()), $change->expires_at),
                        'last_sent_at' => now(),
                    ]);
                    $receipt = $this->send($change, $newCode);
                    return ['status' => 'pending', 'pending_email_change' => $this->payload($change), 'email_delivery_token' => $receipt];
                }

                $oldEmail = $user->email;
                app(AccountEmailChangeService::class)->complete($user, $change->new_email);
                DB::afterCommit(function () use ($oldEmail, $user): void {
                    try { AuthenticationMailDelivery::queue(Notification::route('mail', $oldEmail), new StaffEmailChangedNotification,
                        allowImmediate: false, purpose: 'security_notice'); }
                    catch (Throwable) { Log::warning('Staff email change notification could not be queued.', ['user_id' => $user->getKey()]); }
                });
                return ['status' => 'completed', 'completed' => true];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email is already in use.']);
        }
    }

    public function resend(User $requester, string $id, string $stage): array
    {
        return $this->withAccount($requester, function (User $user, PersonalAccessToken $token) use ($id, $stage): array {
            $change = $this->lockedChange($user, $id);
            if (! $this->valid($change, $user, $token)) return ['status' => 'invalid'];
            if ($stage !== $change->stage) return ['status' => 'stale_stage'];
            if ($change->failed_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if ($remaining = $this->resendAfter($change)) return ['status' => 'cooldown', 'retry_after' => $remaining];
            do { $code = SecurityCode::generate(); } while (Hash::check($stage.'|'.$code, $change->code_hash));
            $change->update([
                'code_hash' => Hash::make($stage.'|'.$code),
                'code_expires_at' => min(now()->addMinutes(SecurityCode::ttlMinutes()), $change->expires_at),
                'last_sent_at' => now(),
            ]);
            $receipt = $this->send($change, $code);
            return ['status' => 'pending', 'pending_email_change' => $this->payload($change), 'email_delivery_token' => $receipt];
        });
    }

    public function cancel(User $requester, string $id): array
    {
        return $this->withAccount($requester, function (User $user) use ($id): array {
            $change = $this->lockedChange($user, $id);
            // Retain the budget and cooldown until expiry, even after cancellation.
            $change->update(['cancelled_at' => now(), 'code_hash' => null]);
            return ['status' => 'cancelled', 'pending_email_change' => null];
        });
    }

    public function canDeliver(StaffEmailChange $change): bool
    {
        $user = User::find($change->user_id);
        $token = PersonalAccessToken::find($change->session_token_id);
        return $user && $token && self::eligible($user) && app(AccountSessionService::class)->isUsable($token, $user)
            && $this->valid($change, $user, $token);
    }

    private function withAccount(User $requester, callable $action): array
    {
        return DB::transaction(function () use ($requester, $action): array {
            // Match protected mutations and login completion: account → token → issuance.
            $user = User::whereKey($requester->getKey())->lockForUpdate()->first();
            abort_unless($user && self::eligible($user), 403);
            $session = $requester->currentAccessToken();
            abort_unless($session instanceof Model && $session->exists, 401);
            $token = PersonalAccessToken::whereKey($session->getKey())->lockForUpdate()->first();
            abort_unless($token && app(AccountSessionService::class)->isUsable($token, $user), 401);
            return $action($user, $token);
        }, 3);
    }

    private function lockedChange(User $user, string $id): StaffEmailChange
    {
        $change = StaffEmailChange::whereKey($id)->where('user_id', $user->getKey())->lockForUpdate()->first();
        abort_unless($change, 404);
        return $change;
    }

    private function valid(StaffEmailChange $change, User $user, Model $token): bool
    {
        return ! $change->cancelled_at && now()->lt($change->expires_at)
            && $change->session_token_id === (int) $token->getKey()
            && $change->current_email === $user->email
            && hash_equals($change->account_fingerprint, self::fingerprint($user));
    }

    private static function eligible(User $user): bool
    {
        return $user->role === 'staff' && ! $user->hasEmailRecoveryHold() && $user->is_active && ! $user->is_archived
            && $user->account_deleted_at === null && ! $user->requiresPasswordSetup();
    }

    public static function fingerprint(User $user): string
    {
        return hash('sha256', json_encode([$user->role, $user->staff_type, $user->staff_subrole, $user->password_hash], JSON_THROW_ON_ERROR));
    }

    private function resendAfter(StaffEmailChange $change): int
    {
        return max(0, $change->last_sent_at->timestamp + SecurityCode::RESEND_COOLDOWN_SECONDS - now()->timestamp);
    }

    private function payload(StaffEmailChange $change): array
    {
        return ['id' => $change->id, 'stage' => $change->stage, 'current_email' => $change->current_email,
            'new_email' => $change->new_email, 'expires_at' => $change->expires_at->toIso8601String(),
            'code_expires_at' => $change->code_expires_at->toIso8601String(), 'resend_after' => $this->resendAfter($change),
            'attempts_remaining' => max(0, SecurityCode::MAX_ATTEMPTS - $change->failed_attempts)];
    }

    private function send(StaffEmailChange $change, string $code): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        $email = $change->stage === 'current' ? $change->current_email : $change->new_email;
        return AuthenticationMailDelivery::queue(Notification::route('mail', $email),
            new VerifyStaffEmailChangeNotification($code, $change->stage, $change->id, $change->code_expires_at->toIso8601String()), $change, 'code_hash', 'code_expires_at', purpose: 'privileged_change');
    }
}
