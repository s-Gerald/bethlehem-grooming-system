<?php

namespace App\Services;

use App\Http\Controllers\EmailVerificationController;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetCodeNotification;
use App\Notifications\SetUpStaffPasswordNotification;
use App\Support\AccountUsername;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetService
{
    public const MAX_ATTEMPTS = 5;

    public function sendCode(string $email): void
    {
        app(AuthenticationEmailPolicy::class)->send($email, fn () => $this->sendEligibleCode($email));
    }

    private function sendEligibleCode(string $email): void
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $user || ! $this->userIsEligible($user) || $user->requiresPasswordSetup()) {
            return;
        }

        EmailVerificationController::assertMailCanBeDelivered();

        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $lockedUser || ! $this->userIsEligible($lockedUser) || $lockedUser->requiresPasswordSetup()) {
                return;
            }

            $existing = PasswordResetRequest::query()
                ->where('user_id', $lockedUser->getKey())
                ->lockForUpdate()
                ->first();
            if ($existing && $existing->last_sent_at->timestamp + (int) config('authentication.email.cooldown', 60) > now()->timestamp) {
                return;
            }
            $attempts = $existing && now()->isBefore($existing->expires_at) ? $existing->verification_attempts : 0;
            if ($attempts >= self::MAX_ATTEMPTS || ($existing?->verified_token_hash && now()->isBefore($existing->expires_at))) {
                return;
            }
            do {
                $code = (string) random_int(100000, 999999);
            } while ($existing && password_get_info($existing->token_hash)['algoName'] !== 'unknown'
                && Hash::check($code, $existing->token_hash));
            $issuance = $existing ?? new PasswordResetRequest(['user_id' => $lockedUser->getKey()]);
            $issuance->fill([
                'token_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes($this->ttlMinutes()),
                'last_sent_at' => now(),
                'verified_token_hash' => null,
                'verification_attempts' => $attempts,
            ])->save();

            AuthenticationMailDelivery::queue($lockedUser, new PasswordResetCodeNotification($code), $issuance);
        });
    }

    public function verifyCode(string $email, string $code): array
    {
        return DB::transaction(function () use ($email, $code): array {
            $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower($email)])->first();
            if (! $user || ! $this->userIsEligible($user) || $user->requiresPasswordSetup()) {
                return ['status' => 'invalid'];
            }

            $request = PasswordResetRequest::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            if (! $request
                || now()->greaterThanOrEqualTo($request->expires_at)
                || $request->verification_attempts >= self::MAX_ATTEMPTS
                || $request->verified_token_hash !== null
                || password_get_info($request->token_hash)['algoName'] === 'unknown') {
                return ['status' => 'invalid'];
            }

            if (! Hash::check($code, $request->token_hash)) {
                $request->increment('verification_attempts');
                return ['status' => 'invalid'];
            }

            $grant = Str::random(64);
            $request->update([
                'token_hash' => Hash::make(Str::random(64)),
                'verified_token_hash' => hash('sha256', $grant),
            ]);
            return [
                'status' => 'valid',
                'token' => $grant,
                'expires_in' => max(0, $request->expires_at->timestamp - now()->timestamp),
            ];
        });
    }

    public function reset(string $plainToken, string $newPassword): array
    {
        return DB::transaction(function () use ($plainToken, $newPassword): array {
            // Match authenticated identity changes: account before recovery grant.
            $userId = PasswordResetRequest::where('verified_token_hash', hash('sha256', $plainToken))->value('user_id');
            if ($userId) User::whereKey($userId)->lockForUpdate()->first();
            $request = PasswordResetRequest::query()
                ->where('verified_token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $request) {
                return ['status' => 'invalid'];
            }

            $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();
            if ($user?->requiresPasswordSetup()) {
                return ['status' => 'invalid'];
            }

            if (now()->greaterThanOrEqualTo($request->expires_at)) {
                $request->delete();

                return ['status' => 'expired'];
            }

            if (! $user || ! $this->userIsEligible($user)) {
                $request->delete();

                return ['status' => 'invalid'];
            }

            if (Hash::check($newPassword, $user->password_hash)) {
                return ['status' => 'same_password'];
            }

            $user->password_hash = Hash::make($newPassword);
            $user->save();
            $user->tokens()->delete();
            $request->delete();

            DB::afterCommit(function () use ($user): void {
                try {
                    $user->notify(new PasswordChangedNotification);
                } catch (Throwable $exception) {
                    // The password change is committed even if queueing fails.
                    report($exception);
                }
            });

            return [
                'status' => 'reset',
            ];
        });
    }

    public function inspectStaffSetup(string $plainToken): array
    {
        $request = PasswordResetRequest::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
        $user = $request?->user;

        if (! $request || ! $user || ! $this->staffSetupIsEligible($user)) {
            return ['status' => 'invalid'];
        }

        if (now()->isAfter($request->expires_at)) {
            return ['status' => 'expired'];
        }

        return [
            'status' => 'valid',
            'email' => $this->maskEmail($user->email),
        ];
    }

    public function completeStaffSetup(string $plainToken, string $newPassword, ?string $username = null): array
    {
        $username = trim($username ?? '');
        while (true) {
            $selectedUsername = null;
            $staff = null;
            try {
                return DB::transaction(function () use ($plainToken, $newPassword, $username, &$selectedUsername, &$staff): array {
                    $request = PasswordResetRequest::query()
                        ->where('token_hash', hash('sha256', $plainToken))
                        ->lockForUpdate()
                        ->first();
                    if (! $request) {
                        return ['status' => 'invalid'];
                    }

                    $user = User::query()->whereKey($request->user_id)->lockForUpdate()->first();
                    if (! $user || ! $this->staffSetupIsEligible($user)) {
                        return ['status' => 'invalid'];
                    }

                    if (now()->isAfter($request->expires_at)) {
                        return ['status' => 'expired'];
                    }

                    $staff = $user;
                    if ($username !== '') {
                        $this->validateStaffUsername($username);
                        if (! $this->staffUsernameIsAvailable($username, $user)) {
                            throw ValidationException::withMessages([
                                'username' => 'That username is already in use.',
                            ]);
                        }
                        $selectedUsername = $username;
                    } else {
                        $selectedUsername = $this->generateStaffUsername($user);
                    }

                    $user->username = $selectedUsername;
                    $user->password_hash = Hash::make($newPassword);
                    $user->is_active = true;
                    $user->email_verified_at = now();
                    $user->save();
                    $user->tokens()->delete();
                    $request->delete();

                    return [
                        'status' => 'completed',
                        'user' => $user,
                    ];
                }, 3);
            } catch (UniqueConstraintViolationException $exception) {
                // Retry the whole transaction so a concurrent username claim is visible.
                if (! $selectedUsername || ! $staff || $this->staffUsernameIsAvailable($selectedUsername, $staff)) {
                    throw $exception;
                }
                if ($username !== '') {
                    throw ValidationException::withMessages([
                        'username' => 'That username is already in use.',
                    ]);
                }
            }
        }
    }

    private function validateStaffUsername(string $username): void
    {
        Validator::make(['username' => $username], [
            'username' => AccountUsername::rules(),
        ], [
            'username.regex' => 'Use letters, numbers, periods, underscores, or hyphens, beginning with a letter.',
        ])->validate();
    }

    private function staffUsernameIsAvailable(string $username, User $staff): bool
    {
        return AccountUsername::isAvailableToStaff($username, $staff, true);
    }

    private function generateStaffUsername(User $staff): string
    {
        $asciiName = Str::ascii(mb_convert_case(mb_strtolower($staff->first_name), MB_CASE_TITLE, 'UTF-8').mb_convert_case(mb_strtolower($staff->last_name), MB_CASE_TITLE, 'UTF-8'));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '', $asciiName) ?? '';
        $base = preg_replace('/^[^A-Za-z]+/', '', $base) ?? '';
        $base = substr(str_pad($base === '' ? 'Staff' : $base, 3, '0'), 0, 50);
        $username = $base;
        for ($suffix = 2; ! $this->staffUsernameIsAvailable($username, $staff); $suffix++) {
            $username = substr($base, 0, 50 - strlen((string) $suffix)).$suffix;
        }
        $this->validateStaffUsername($username);

        return $username;
    }

    public function resendExpiredStaffSetupLink(string $expiredPlainToken): array
    {
        return $this->issueStaffSetupLink(null, $expiredPlainToken);
    }

    public function resendStaffSetupLink(User $staff): array
    {
        return $this->issueStaffSetupLink($staff, null);
    }

    private function issueStaffSetupLink(?User $staff, ?string $expiredPlainToken): array
    {
        EmailVerificationController::assertMailCanBeDelivered();

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        return DB::transaction(function () use ($staff, $expiredPlainToken, $plainToken, $tokenHash): array {
            $request = PasswordResetRequest::query()
                ->when($staff, fn ($query) => $query->where('user_id', $staff->getKey()))
                ->when($expiredPlainToken, fn ($query) => $query->where('token_hash', hash('sha256', $expiredPlainToken)))
                ->lockForUpdate()
                ->first();
            if (! $request && ! $staff) {
                return ['status' => 'invalid'];
            }

            $user = User::query()->whereKey($staff?->getKey() ?? $request->user_id)->lockForUpdate()->first();
            if (! $user || ! $this->staffSetupIsEligible($user)) {
                return ['status' => 'invalid'];
            }

            if ($expiredPlainToken && ! now()->isAfter($request->expires_at)) {
                return ['status' => 'not_expired'];
            }

            $request ??= new PasswordResetRequest(['user_id' => $user->getKey()]);
            $request->fill([
                'token_hash' => $tokenHash,
                'expires_at' => now()->addHours(24),
                'last_sent_at' => now(),
            ])->save();

            $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');
            $setupUrl = $frontendUrl.'/pages/client/set-up-password.html#token='.rawurlencode($plainToken);
            // Durable queue insertion and token rotation commit or roll back together.
            AuthenticationMailDelivery::queue($user, new SetUpStaffPasswordNotification($setupUrl), $request);

            return ['status' => 'sent'];
        }, 3);
    }

    private function userIsEligible(User $user): bool
    {
        return (bool) $user->is_active
            && ! $user->hasEmailRecoveryHold()
            && ! (bool) $user->is_archived
            && $user->account_deleted_at === null
            && password_get_info((string) $user->password_hash)['algoName'] !== 'unknown'
            && ($user->role !== 'customer' || $user->email_verified_at !== null)
            && in_array($user->role, ['customer', 'staff', 'admin'], true);
    }

    private function staffSetupIsEligible(User $user): bool
    {
        return $user->role === 'staff'
            && ! $user->is_archived
            && $user->account_deleted_at === null
            && $user->requiresPasswordSetup();
    }

    public function ttlMinutes(): int
    {
        return max(1, (int) config('auth.passwords.users.expire', 15));
    }

    public function maskEmail(string $email): string
    {
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return 'your email address';
        }

        $visible = mb_substr($localPart, 0, 1);

        return $visible.'***@'.$domain;
    }
}
