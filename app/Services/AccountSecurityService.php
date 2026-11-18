<?php

namespace App\Services;

use App\Models\AccountSecurityIncident;
use App\Models\LoginEmailChallenge;
use App\Models\User;
use App\Notifications\UnusualSignInActivityNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Carbon\CarbonInterface;

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
        $this->observeSafely(fn () => DB::transaction(function () use ($user): void {
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $failures = RateLimiter::attempts('login-code-account:'.$user->getKey());
            $accountRestricted = $failures >= max(1, (int) config('authentication.otp_account_attempts', 20));
            $type = $accountRestricted ? 'account_otp_restricted' : 'repeated_otp_failures';
            $incident = null;
            if ($failures < max(2, (int) config('authentication.security.failure_threshold', 3)) && ! $accountRestricted) {
                $incident = AccountSecurityIncident::where('user_id', $user->getKey())
                    ->where('incident_day', now()->utc()->toDateString())->unresolved()->first();
                if (! $incident && count($this->recentSources($user)) < max(2, (int) config('authentication.security.source_threshold', 3))) return;
                $type = $incident?->type ?? 'multiple_incomplete_verifications';
            }
            // Only password-confirmed issuance/resume records a validated source.
            // Later failures aggregate even after a legitimate success resets guessing counters.
            $this->record($user, $type, $failures, true, $incident);
        }));
    }

    private function recentSources(User $user): array
    {
        $window = max(60, (int) config('authentication.security.source_window', 1800));
        return array_filter(Cache::get('security-sources:'.$user->getKey(), []), fn ($at) => $at > now()->timestamp - $window);
    }

    private function record(User $user, string $type, int $failures, bool $newFailure, ?AccountSecurityIncident $incident = null): void
    {
        $incident ??= AccountSecurityIncident::where('user_id', $user->getKey())
            ->where('incident_day', now()->utc()->toDateString())->unresolved()->first()
            ?? new AccountSecurityIncident(['user_id' => $user->getKey(), 'incident_day' => now()->utc()->toDateString()]);
        $created = ! $incident->exists;
        $escalated = $type === 'account_otp_restricted' && $incident->type !== $type;
        if ($created) $incident->first_occurred_at = now();
        $incident->last_occurred_at = now();
        $incident->failure_count = $created ? $failures : max($failures, $incident->failure_count + ($newFailure ? 1 : 0));
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

    public function recognize(User $user, int $id): AccountSecurityIncident
    {
        return DB::transaction(function () use ($user, $id): AccountSecurityIncident {
            User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $record = AccountSecurityIncident::where('user_id', $user->getKey())->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $record->recognized_at && ! $record->resolved_at && ! $record->reviewed_at) {
                $record->update(['recognized_at' => now()]);
                app(ActivityRecorder::class)->record('security_incident_recognized', 'system', 'account',
                    (string) $user->getKey(), 'Security activity recognized', [], false, $user, 'security-recognized:'.$id);
            }
            return $record;
        }, 3);
    }

    /** Called inside password confirmation's transaction, with the account already locked. */
    public function resolveByPasswordChange(User $user, CarbonInterface $at, int $changeId, User $actor): void
    {
        $count = AccountSecurityIncident::where('user_id', $user->getKey())->unresolved()
            ->where('last_occurred_at', '<=', $at)->update(['resolved_at' => $at]);
        if ($count > 0) app(ActivityRecorder::class)->record('security_incidents_resolved', 'system', 'account',
            (string) $user->getKey(), $count.' security incidents resolved by password change', [], false,
            $actor, 'security-resolved:'.$changeId);
    }

    public function recent(User $user, ?string $focusId = null, ?string $cursor = null): array
    {
        $query = AccountSecurityIncident::where('user_id', $user->getKey())
            ->where('last_occurred_at', '>', now()->subDays(max(1, (int) config('authentication.security.retention_days', 90))));
        $page = clone $query;
        if ($cursor !== null) {
            $position = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true) ?: '', true);
            $valid = is_array($position) && Validator::make($position, [
                'at' => ['required', 'string', 'date_format:Y-m-d H:i:s'], 'id' => ['required', 'integer', 'min:1'],
            ])->passes();
            if (! $valid) throw \Illuminate\Validation\ValidationException::withMessages(['cursor' => 'Invalid security activity cursor.']);
            $page->where(function ($older) use ($position): void {
                $older->where('first_occurred_at', '<', $position['at'])->orWhere(function ($tied) use ($position): void {
                    $tied->where('first_occurred_at', $position['at'])->where('id', '<', $position['id']);
                });
            });
        }
        // First occurrence never changes as additional failures aggregate or history is recognized/resolved.
        $records = $page->orderByDesc('first_occurred_at')->orderByDesc('id')->limit(6)->get();
        $hasMore = $records->count() > 5;
        $records = $records->take(5);
        $last = $records->last();
        $focus = $focusId === null ? null : ($records->firstWhere('id', $focusId) ?? $query->whereKey($focusId)->first());
        return ['incidents' => $records->map->payload()->values()->all(),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? rtrim(strtr(base64_encode(json_encode([
                'at' => $last->getRawOriginal('first_occurred_at'), 'id' => $last->getKey(),
            ])), '+/', '-_'), '=') : null,
            'focused_incident' => $focus?->payload()];
    }

    public function unreviewedId(User $user): ?int
    {
        if (! in_array($user->role, ['admin', 'staff'], true)) return null;
        return AccountSecurityIncident::where('user_id', $user->getKey())->unresolved()
            ->where('last_occurred_at', '>', now()->subDays(max(1, (int) config('authentication.security.retention_days', 90))))
            ->latest('first_occurred_at')->latest('id')->value('id');
    }
}
