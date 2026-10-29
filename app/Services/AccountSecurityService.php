<?php

namespace App\Services;

use App\Models\AccountSecurityIncident;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\UnusualSignInActivityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class AccountSecurityService
{
    // Incident writes serialize on the account row; cache holds short-lived keyed
    // source hashes, never raw IPs, devices, tokens, submitted codes or passwords.
    public function passwordConfirmed(User $user): void
    {
        if (! in_array($user->role, ['admin', 'staff'], true)) return;
        $this->observeSafely(function () use ($user): void {
            DB::transaction(function () use ($user): void {
                User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $window = max(60, (int) config('authentication.security.source_window', 1800));
                $key = 'security-sources:'.$user->getKey();
                $sources = $this->recentSources($user);
                $sources[LoginEmailChallengeService::sourceHash()] = now()->timestamp;
                Cache::put($key, array_slice($sources, -30, null, true), $window);
                $threshold = max(2, (int) config('authentication.security.source_threshold', 3));
                if (count($sources) < $threshold) return;
                $failures = RateLimiter::attempts('login-code-account:'.$user->getKey());
                $incomplete = $failures > 0 || LoginEmailChallenge::where('user_id', $user->getKey())
                    ->whereNull('approved_at')->where('expires_at', '>', now())
                    ->where('created_at', '<=', now()->subSeconds(max(60, (int) config('authentication.security.incomplete_seconds', 120))))
                    ->whereNotNull('source_hash')->distinct()->count('source_hash') >= $threshold;
                if ($incomplete) $this->record($user, 'multiple_incomplete_verifications', $failures, false);
            });
        });
    }

    public function failed(User $user): void
    {
        if (! in_array($user->role, ['admin', 'staff'], true)) return;
        $this->observeSafely(function () use ($user): void {
            $failures = RateLimiter::attempts('login-code-account:'.$user->getKey());
            $accountRestricted = $failures >= max(1, (int) config('authentication.otp_account_attempts', 20));
            $type = $accountRestricted ? 'account_otp_restricted' : 'repeated_otp_failures';
            $incident = null;
            if ($failures < max(2, (int) config('authentication.security.failure_threshold', 3)) && ! $accountRestricted) {
                $incident = AccountSecurityIncident::where('user_id', $user->getKey())
                    ->where('incident_day', now()->utc()->toDateString())->first();
                if (! $incident && count($this->recentSources($user)) < max(2, (int) config('authentication.security.source_threshold', 3))) return;
                $type = $incident?->type ?? 'multiple_incomplete_verifications';
            }
            // Only password-confirmed issuance/resume records a validated source.
            // Later failures aggregate even after a legitimate success resets guessing counters.
            $this->record($user, $type, $failures, true, $incident);
        });
    }

    private function recentSources(User $user): array
    {
        $window = max(60, (int) config('authentication.security.source_window', 1800));
        return array_filter(Cache::get('security-sources:'.$user->getKey(), []), fn ($at) => $at > now()->timestamp - $window);
    }

    private function record(User $user, string $type, int $failures, bool $newFailure, ?AccountSecurityIncident $incident = null): void
    {
        $incident ??= AccountSecurityIncident::firstOrNew(['user_id' => $user->getKey(), 'incident_day' => now()->utc()->toDateString()]);
        $created = ! $incident->exists;
        $escalated = $type === 'account_otp_restricted' && $incident->type !== $type;
        if ($created) $incident->first_occurred_at = now();
        $incident->last_occurred_at = now();
        $incident->failure_count = $created ? $failures : max($failures, $incident->failure_count + ($newFailure ? 1 : 0));
        $incident->reviewed_at = null; // New evidence needs a new review, not a trust decision.
        if ($created || $escalated) $incident->type = $type;
        if ($type === 'account_otp_restricted') $incident->severity = 'high';
        $incident->save();
        $recorder = app(ActivityRecorder::class);
        if ($created) $recorder->record('suspicious_verification', 'system', 'account', (string) $user->getKey(),
            'Unusual sign-in activity', [], false, null, 'security-incident:'.$incident->getKey());
        if ($escalated) $recorder->record('account_otp_restricted', 'system', 'account', (string) $user->getKey(),
            'Temporary sign-in verification restriction', [], false, null, 'security-restriction:'.$incident->getKey());

        $recentAlert = AccountSecurityIncident::where('user_id', $user->getKey())
            ->where('alert_attempted_at', '>', now()->subDay())->exists();
        if ($recentAlert) return;
        $incident->alert_attempted_at = now();
        $incident->save();
        DB::afterCommit(function () use ($user): void {
            $this->observeSafely(fn () => app(AuthenticationEmailPolicy::class)->send($user->email,
                fn () => AuthenticationMailDelivery::queue($user, new UnusualSignInActivityNotification, allowImmediate: false),
                'security_alert'));
        });
    }

    private function observeSafely(callable $observe): void
    {
        try { $observe(); }
        catch (\Illuminate\Http\Exceptions\HttpResponseException) { /* Quota exhaustion never blocks authentication. */ }
        catch (\Throwable $exception) { report($exception); }
    }

    public function recent(User $user, ?string $focusId = null): array
    {
        $query = AccountSecurityIncident::where('user_id', $user->getKey())
            ->where('last_occurred_at', '>', now()->subDays(max(1, (int) config('authentication.security.retention_days', 90))));
        if ($focusId !== null) $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$focusId]);
        return $query->latest('last_occurred_at')->limit(20)->get()->map->payload()->all();
    }

    public function unreviewedId(User $user): ?int
    {
        if (! in_array($user->role, ['admin', 'staff'], true)) return null;
        return AccountSecurityIncident::where('user_id', $user->getKey())->whereNull('reviewed_at')
            ->where('last_occurred_at', '>', now()->subDays(max(1, (int) config('authentication.security.retention_days', 90))))
            ->latest('last_occurred_at')->value('id');
    }
}
