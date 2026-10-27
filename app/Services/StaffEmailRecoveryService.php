<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\StaffEmailRecovery;
use App\Models\User;
use App\Notifications\StaffEmailRecoveryLinkNotification;
use App\Notifications\StaffEmailRecoveryNotice;
use App\Notifications\VerifyAdminStaffRecoveryNotification;
use App\Support\AccountEmail;
use App\Support\SecurityCode;
use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class StaffEmailRecoveryService
{
    public function start(User $admin, int $targetId, array $data): array
    {
        $email = AccountEmail::normalize($data['email']);
        Validator::make(['email' => $email], ['email' => AccountEmail::rules()])->validate();
        return $this->withAdmin($admin, $targetId, function (User $actor, User $target, PersonalAccessToken $session) use ($data, $email): array {
            $this->password($actor, $data['current_password']);
            abort_unless($this->eligible($target), 422, 'This staff account is unavailable for recovery.');
            if (empty($data['identity_verified']) || ($data['identity_method'] ?? null) !== 'in_person_employment_record') {
                throw ValidationException::withMessages(['identity_verified' => 'Verify the staff member’s identity in person against trusted employment records.']);
            }
            if (! in_array($data['reason'], ['inaccessible', 'compromised'], true)) {
                throw ValidationException::withMessages(['reason' => 'Select a recovery reason.']);
            }
            if (strcasecmp($email, $target->email) === 0) {
                throw ValidationException::withMessages(['email' => 'Enter a different email address.']);
            }
            AccountEmail::assertAvailable($email, $target, true);
            $previous = StaffEmailRecovery::where('active_user_id', $target->getKey())->lockForUpdate()->first();
            abort_if($target->hasEmailRecoveryHold() || $previous?->status === 'pending', 409, 'Resolve the existing recovery first.');
            $previous ??= StaffEmailRecovery::where('user_id', $target->getKey())->whereNull('authorized_at')
                ->where('code_expires_at', '>', now())->orderByDesc('created_at')->lockForUpdate()->first();
            $retained = $previous && now()->lt($previous->code_expires_at);
            if ($retained && $previous->admin_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if ($previous && ($retry = $this->resendAfter($previous))) return ['status' => 'cooldown', 'retry_after' => $retry];
            if ($previous?->active_user_id) {
                $this->recordExpiration($previous);
                $previous->update(['status' => 'superseded', 'active_user_id' => null, 'code_hash' => null, 'resolved_at' => now()]);
                $this->audit($previous, 'superseded', $actor->getKey());
            }
            $code = SecurityCode::generate();
            $expires = $retained ? $previous->code_expires_at : now()->addMinutes(SecurityCode::ttlMinutes());
            $recovery = StaffEmailRecovery::create([
                'id' => (string) Str::uuid(), 'user_id' => $target->getKey(), 'active_user_id' => $target->getKey(),
                'admin_user_id' => $actor->getKey(), 'session_token_id' => $session->getKey(),
                'current_email' => $target->email, 'new_email' => $email, 'admin_email' => $actor->email,
                'reason' => $data['reason'], 'identity_method' => $data['identity_method'], 'identity_verified_at' => now(),
                'account_fingerprint' => StaffEmailChangeService::fingerprint($target), 'admin_fingerprint' => $this->adminFingerprint($actor),
                'status' => 'authorizing', 'code_hash' => Hash::make('staff-recovery|'.$code),
                'admin_attempts' => $retained ? $previous->admin_attempts : 0,
                'code_expires_at' => $expires, 'expires_at' => $expires, 'last_sent_at' => now(),
            ]);
            $this->audit($recovery, 'initiated', $actor->getKey());
            $receipt = $this->sendCode($recovery, $code);
            return ['status' => 'authorizing', 'recovery' => $this->payload($recovery), 'email_delivery_token' => $receipt];
        });
    }

    public function confirm(User $admin, int $targetId, string $id, string $code): array
    {
        return $this->withAdmin($admin, $targetId, function (User $actor, User $target, PersonalAccessToken $session) use ($id, $code): array {
            $recovery = $this->locked($target, $id);
            if (! $this->validAuthorization($recovery, $actor, $target, $session)) return ['status' => 'invalid'];
            if (now()->gte($recovery->code_expires_at)) {
                $this->recordExpiration($recovery);
                return ['status' => 'expired'];
            }
            if ($recovery->admin_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if (! Hash::check('staff-recovery|'.$code, $recovery->code_hash)) {
                $recovery->increment('admin_attempts');
                $this->audit($recovery, 'admin_code_rejected', $actor->getKey());
                return ['status' => $recovery->admin_attempts >= SecurityCode::MAX_ATTEMPTS ? 'locked' : 'invalid_code'];
            }
            AccountEmail::assertAvailable($recovery->new_email, $target, true);
            $recovery->update(['status' => 'pending', 'authorized_at' => now(), 'code_hash' => null]);
            $target->forceFill(['email_recovery_id' => $recovery->id])->save();
            app(AccountEmailChangeService::class)->revoke($target);
            $this->audit($recovery, 'authorized', $actor->getKey());
            $receipt = $this->sendLink($recovery, $actor->getKey());
            $this->notice($recovery, false);
            return ['status' => 'pending', 'recovery' => $this->payload($recovery), 'email_delivery_token' => $receipt];
        });
    }

    public function resendCode(User $admin, int $targetId, string $id): array
    {
        return $this->withAdmin($admin, $targetId, function (User $actor, User $target, PersonalAccessToken $session) use ($id): array {
            $recovery = $this->locked($target, $id);
            if (! $this->validAuthorization($recovery, $actor, $target, $session)) return ['status' => 'invalid'];
            if (now()->gte($recovery->code_expires_at)) { $this->recordExpiration($recovery); return ['status' => 'expired']; }
            if ($recovery->admin_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if ($retry = $this->resendAfter($recovery)) return ['status' => 'cooldown', 'retry_after' => $retry];
            do { $code = SecurityCode::generate(); } while (Hash::check('staff-recovery|'.$code, $recovery->code_hash));
            $recovery->update(['code_hash' => Hash::make('staff-recovery|'.$code), 'last_sent_at' => now()]);
            $this->audit($recovery, 'admin_code_resent', $actor->getKey());
            return ['status' => 'authorizing', 'recovery' => $this->payload($recovery), 'email_delivery_token' => $this->sendCode($recovery, $code)];
        });
    }

    public function resendLink(User $admin, int $targetId, string $id, string $password): array
    {
        return $this->withAdmin($admin, $targetId, function (User $actor, User $target) use ($id, $password): array {
            $this->password($actor, $password);
            $recovery = $this->locked($target, $id);
            if (! $this->validRecovery($recovery, $target)) return ['status' => 'invalid'];
            if ($recovery->failed_attempts >= SecurityCode::MAX_ATTEMPTS) return ['status' => 'locked'];
            if ($retry = $this->resendAfter($recovery)) return ['status' => 'cooldown', 'retry_after' => $retry];
            $this->recordExpiration($recovery);
            AccountEmail::assertAvailable($recovery->new_email, $target, true);
            return ['status' => 'pending', 'email_delivery_token' => $this->sendLink($recovery, $actor->getKey()), 'recovery' => $this->payload($recovery)];
        });
    }

    public function cancel(User $admin, int $targetId, string $id, ?string $password, bool $safe): array
    {
        return $this->withAdmin($admin, $targetId, function (User $actor, User $target, PersonalAccessToken $session) use ($id, $password, $safe): array {
            $recovery = $this->locked($target, $id);
            abort_unless($recovery->active_user_id && in_array($recovery->status, ['authorizing', 'pending'], true), 409);
            // A lost approval response may leave the client on its code view.
            // Restore authoritative state without cancelling an approved hold.
            if ($recovery->status === 'pending' && ! $password && ! $safe) {
                return ['status' => 'pending', 'recovery' => $this->payload($recovery)];
            }
            if ($recovery->status === 'authorizing') {
                abort_unless((int) $recovery->admin_user_id === (int) $actor->getKey() && (int) $recovery->session_token_id === (int) $session->getKey(), 403);
            } else {
                $this->password($actor, $password ?? '');
            }
            if ($recovery->status === 'pending' && ! $safe) {
                throw ValidationException::withMessages(['old_email_safe' => 'Confirm that access through the current email is safe to restore.']);
            }
            if ($recovery->status === 'pending') $this->audit($recovery, 'old_email_safe_confirmed', $actor->getKey());
            $this->recordExpiration($recovery);
            // Retain an unapproved challenge's budget until its absolute expiry.
            $recovery->update(['status' => 'cancelled', 'active_user_id' => null, 'code_hash' => null, 'token_hash' => null, 'resolved_at' => now()]);
            if ($target->email_recovery_id === $recovery->id) $target->forceFill(['email_recovery_id' => null])->save();
            $this->audit($recovery, 'cancelled', $actor->getKey());
            return ['status' => 'cancelled', 'recovery' => null];
        });
    }

    public function verify(string $token): array
    {
        return $this->withToken($token, function (StaffEmailRecovery $recovery, User $target): array {
            if (! $this->usableLink($recovery, $target)) return ['status' => 'invalid'];
            return ['status' => 'verified', 'email' => $recovery->new_email, 'expires_at' => $recovery->expires_at->toIso8601String()];
        });
    }

    public function complete(string $token, string $password): array
    {
        return $this->withToken($token, function (StaffEmailRecovery $recovery, User $target) use ($password): array {
            if (! $this->usableLink($recovery, $target)) return ['status' => 'invalid'];
            if (! Hash::check($password, $target->password_hash)) {
                $recovery->increment('failed_attempts');
                $this->audit($recovery, 'staff_password_rejected', null);
                return ['status' => $recovery->failed_attempts >= SecurityCode::MAX_ATTEMPTS ? 'locked' : 'invalid_password'];
            }
            app(AccountEmailChangeService::class)->complete($target, $recovery->new_email);
            $target->forceFill(['email_recovery_id' => null])->save();
            $recovery->update(['status' => 'completed', 'active_user_id' => null, 'token_hash' => null, 'resolved_at' => now()]);
            $this->audit($recovery, 'completed', $target->getKey());
            app(ActivityRecorder::class)->record('recovery_completed', 'system', 'account', (string) $target->getKey(),
                trim($target->first_name.' '.$target->last_name), [], false, $target, 'recovery:'.$recovery->id);
            $this->notice($recovery, true);
            return ['status' => 'completed'];
        });
    }

    public function recordExpirations(): int
    {
        $count = 0;
        StaffEmailRecovery::whereIn('status', ['authorizing', 'pending'])->whereNull('expiration_recorded_at')
            ->where('expires_at', '<=', now())->chunkById(100, function ($recoveries) use (&$count): void {
                foreach ($recoveries as $candidate) {
                    $count += DB::transaction(function () use ($candidate): int {
                        $recovery = StaffEmailRecovery::whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $recovery || $recovery->expiration_recorded_at) return 0;
                        $this->recordExpiration($recovery);
                        return $recovery->expiration_recorded_at ? 1 : 0;
                    }, 3);
                }
            });
        return $count;
    }

    public function history(User $admin, int $targetId): array
    {
        abort_unless($admin->role === 'admin', 403);
        $recoveries = StaffEmailRecovery::where('user_id', $targetId)->latest()->limit(30)->get();
        $events = DB::table('staff_email_recovery_events')->whereIn('recovery_id', $recoveries->modelKeys())->orderBy('id')->get()->groupBy('recovery_id');
        return $recoveries->map(function ($recovery) use ($events) {
            return [...$this->payload($recovery), 'admin_user_id' => $recovery->admin_user_id, 'current_email' => $recovery->current_email,
                'reason' => $recovery->reason, 'identity_method' => $recovery->identity_method,
                'identity_verified_at' => $recovery->identity_verified_at->toIso8601String(),
                'events' => ($events->get($recovery->id) ?? collect())->all()];
        })->all();
    }

    public function payload(StaffEmailRecovery $recovery): array
    {
        $expiry = $recovery->status === 'authorizing' ? $recovery->code_expires_at : $recovery->expires_at;
        return ['id' => $recovery->id, 'status' => in_array($recovery->status, ['authorizing', 'pending'], true) && now()->gte($expiry) ? 'expired' : $recovery->status,
            'new_email' => $recovery->new_email, 'expires_at' => $expiry->toIso8601String(), 'resend_after' => $this->resendAfter($recovery),
            'confirmation_email' => app(PasswordResetService::class)->maskEmail($recovery->admin_email),
            'attempts_remaining' => max(0, SecurityCode::MAX_ATTEMPTS - ($recovery->status === 'authorizing' ? $recovery->admin_attempts : $recovery->failed_attempts))];
    }

    public function canDeliver(StaffEmailRecovery $recovery, string $kind): bool
    {
        $target = User::find($recovery->user_id);
        if (! $target) return false;
        if ($kind === 'link') return $this->validRecovery($recovery, $target) && now()->lt($recovery->expires_at) && $recovery->failed_attempts < SecurityCode::MAX_ATTEMPTS;
        $admin = User::find($recovery->admin_user_id);
        $session = PersonalAccessToken::find($recovery->session_token_id);
        return $admin && $session && $this->validAuthorization($recovery, $admin, $target, $session)
            && app(AccountSessionService::class)->isUsable($session, $admin) && now()->lt($recovery->code_expires_at) && $recovery->admin_attempts < SecurityCode::MAX_ATTEMPTS;
    }

    private function withAdmin(User $requester, int $targetId, callable $action): array
    {
        return DB::transaction(function () use ($requester, $targetId, $action): array {
            $accounts = User::whereIn('user_id', [$requester->getKey(), $targetId])->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
            $admin = $accounts->get($requester->getKey());
            $target = $accounts->get($targetId);
            abort_unless($admin && $this->validAdmin($admin), 403);
            abort_unless($target && $target->role === 'staff', 404);
            $session = $requester->currentAccessToken();
            abort_unless($session instanceof Model && $session->exists, 401);
            $session = PersonalAccessToken::whereKey($session->getKey())->lockForUpdate()->first();
            abort_unless($session && app(AccountSessionService::class)->isUsable($session, $admin), 401);
            return $action($admin, $target, $session);
        }, 3);
    }

    private function withToken(string $token, callable $action): array
    {
        // Preliminary lookup contains no authoritative validation. Recheck after account locks.
        $hash = hash('sha256', $token);
        $candidate = StaffEmailRecovery::where('token_hash', $hash)->first(['id', 'user_id', 'admin_user_id']);
        if (! $candidate) return ['status' => 'invalid'];
        return DB::transaction(function () use ($candidate, $hash, $action): array {
            $accounts = User::whereIn('user_id', [$candidate->user_id, $candidate->admin_user_id])->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
            $target = $accounts->get($candidate->user_id);
            $recovery = StaffEmailRecovery::whereKey($candidate->id)->lockForUpdate()->first();
            if (! $target || ! $recovery || ! $recovery->token_hash || ! hash_equals($recovery->token_hash, $hash)) return ['status' => 'invalid'];
            return $action($recovery, $target);
        }, 3);
    }

    private function locked(User $target, string $id): StaffEmailRecovery
    {
        return StaffEmailRecovery::whereKey($id)->where('user_id', $target->getKey())->lockForUpdate()->firstOrFail();
    }

    private function validAuthorization(StaffEmailRecovery $recovery, User $admin, User $target, PersonalAccessToken $session): bool
    {
        return $recovery->status === 'authorizing' && (int) $recovery->active_user_id === (int) $target->getKey()
            && (int) $recovery->admin_user_id === (int) $admin->getKey() && (int) $recovery->session_token_id === (int) $session->getKey()
            && $this->validAdmin($admin) && ! $target->hasEmailRecoveryHold() && $this->snapshotMatches($recovery, $target)
            && hash_equals($recovery->admin_fingerprint, $this->adminFingerprint($admin));
    }

    private function validRecovery(StaffEmailRecovery $recovery, User $target): bool
    {
        $admin = User::find($recovery->admin_user_id);
        return $recovery->status === 'pending' && $target->email_recovery_id === $recovery->id
            && $this->snapshotMatches($recovery, $target) && $admin && $this->validAdmin($admin)
            && hash_equals($recovery->admin_fingerprint, $this->adminFingerprint($admin));
    }

    private function snapshotMatches(StaffEmailRecovery $recovery, User $target): bool
    {
        return $this->eligible($target) && $target->email === $recovery->current_email
            && hash_equals($recovery->account_fingerprint, StaffEmailChangeService::fingerprint($target));
    }

    private function usableLink(StaffEmailRecovery $recovery, User $target): bool
    {
        $this->recordExpiration($recovery);
        return $this->validRecovery($recovery, $target) && now()->lt($recovery->expires_at) && $recovery->failed_attempts < SecurityCode::MAX_ATTEMPTS;
    }

    private function eligible(User $user): bool
    {
        return $user->role === 'staff' && $user->is_active && ! $user->is_archived && ! $user->account_deleted_at && ! $user->requiresPasswordSetup()
            && (StaffRole::TYPES[$user->staff_subrole] ?? null) === $user->staff_type && $user->staff_type !== null;
    }

    private function validAdmin(User $user): bool { return $user->role === 'admin' && $user->is_active && ! $user->is_archived && ! $user->account_deleted_at; }
    private function adminFingerprint(User $user): string { return hash('sha256', $user->email.'|'.$user->password_hash); }
    private function password(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password_hash)) throw ValidationException::withMessages(['current_password' => 'The admin password is incorrect.']);
    }
    private function resendAfter(StaffEmailRecovery $recovery): int { return max(0, $recovery->last_sent_at->timestamp + SecurityCode::RESEND_COOLDOWN_SECONDS - now()->timestamp); }

    private function sendCode(StaffEmailRecovery $recovery, string $code): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        return AuthenticationMailDelivery::queue(Notification::route('mail', $recovery->admin_email),
            new VerifyAdminStaffRecoveryNotification($code, $recovery->id, $recovery->code_expires_at->toIso8601String()), $recovery, 'code_hash', 'code_expires_at');
    }

    private function sendLink(StaffEmailRecovery $recovery, int $actorId): string
    {
        EmailVerificationController::assertMailCanBeDelivered();
        $plain = bin2hex(random_bytes(32));
        $recovery->update(['token_hash' => hash('sha256', $plain), 'expires_at' => now()->addMinutes(30), 'last_sent_at' => now(), 'expiration_recorded_at' => null]);
        $frontend = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $receipt = AuthenticationMailDelivery::queue(Notification::route('mail', $recovery->new_email),
            new StaffEmailRecoveryLinkNotification($frontend.'/pages/client/confirm-email-recovery.html#'.$plain, $recovery->id, $recovery->expires_at->toIso8601String()), $recovery);
        $this->audit($recovery, 'link_issued', $actorId);
        return $receipt;
    }

    private function recordExpiration(StaffEmailRecovery $recovery): void
    {
        $expiry = $recovery->status === 'authorizing' ? $recovery->code_expires_at : $recovery->expires_at;
        if (in_array($recovery->status, ['authorizing', 'pending'], true) && now()->gte($expiry) && ! $recovery->expiration_recorded_at) {
            $recovery->update(['expiration_recorded_at' => now()]);
            $this->audit($recovery, 'expired', null);
        }
    }

    private function audit(StaffEmailRecovery $recovery, string $event, ?int $actorId): void
    {
        DB::table('staff_email_recovery_events')->insert(['recovery_id' => $recovery->id, 'actor_user_id' => $actorId, 'event' => $event, 'created_at' => now()]);
    }

    private function notice(StaffEmailRecovery $recovery, bool $completed): void
    {
        DB::afterCommit(function () use ($recovery, $completed): void {
            try { Notification::route('mail', $recovery->current_email)->notify(new StaffEmailRecoveryNotice($completed)); }
            catch (Throwable) { Log::warning('Staff recovery notice could not be queued.', ['recovery_id' => $recovery->id]); }
        });
    }
}
