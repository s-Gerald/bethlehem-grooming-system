<?php

namespace App\Http\Controllers;

use App\Support\AccountEmail;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Services\RegistrationMagicLinkService;
use App\Services\CustomerRegistrationService;
use App\Services\AuthenticationMailDelivery;
use App\Services\AuthenticationEmailPolicy;
use App\Services\AccountSessionService;
use App\Http\Middleware\UseAccountSessionCookie;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class EmailVerificationController extends Controller
{
    // GET only serves inert HTML. A deliberate button submits this POST.
    public function verify(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $hash = hash('sha256', $data['token']);
        try {
            return DB::transaction(function () use ($request, $data, $hash) {
                $legacyUntil = config('authentication.legacy_links_until');
                $allowLegacy = $legacyUntil && now()->isBefore($legacyUntil);
                $find = function ($query) use ($data, $hash, $allowLegacy) {
                    $query->where('email_verification_token', $hash);
                    if ($allowLegacy) $query->orWhere('email_verification_token', $data['token']);
                };
                $pending = PendingCustomerRegistration::query()->where($find)->lockForUpdate()->first();
                $user = User::query()->where(function ($query) use ($find, $pending) {
                    $query->where($find);
                    if ($pending) $query->orWhereRaw(AccountEmail::lookupColumn().' = ?', [$pending->email]);
                })->lockForUpdate()->first();
                // A real account always wins collisions; pending credentials may
                // never replace a legitimate customer's identity or password.
                if ($user && ($pending || ! CustomerRegistrationService::recoverableUser($user))) return $this->invalidLink();
                $registrant = $user ?? $pending;
                if (! $registrant || ($pending && $pending->retentionExpired())) return $this->invalidLink();
                if (! $registrant->email_verification_last_sent_at && ! $allowLegacy) return $this->invalidLink();
                $expiry = $registrant->email_verification_expires_at;
                if (! $expiry || now()->greaterThanOrEqualTo($expiry)
                    || ($registrant->email_verification_last_sent_at && now()->greaterThanOrEqualTo(
                        $registrant->email_verification_last_sent_at->copy()->addMinutes(max(1, (int) config('authentication.magic_link_minutes', 30)))))) {
                    return $this->invalidLink(true);
                }
                if ($pending && CustomerRegistrationService::trustsBrowser($pending, $request)) {
                    return app(CustomerRegistrationService::class)->complete($pending);
                }
                // Foreign browsers and legacy accounts must discard the pending
                // password and establish their own through the email-proven grant.
                return app(CustomerRegistrationService::class)->authorizeReview($registrant);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return $this->invalidLink();
        }
    }

    private function invalidLink(bool $expired = false)
    {
        return response()->json(['success' => false, 'expired' => $expired,
            'code' => $expired ? 'registration_link_expired' : 'registration_link_invalid',
            'message' => $expired ? 'This link has expired. Request a new one.'
                : 'This link is invalid or has already been used.'], 422)->header('Cache-Control', 'no-store');
    }

    public function resend(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email|max:150']);
        $credential = $request->input('registration_token');
        $owned = is_string($credential) && strlen($credential) === 64
            ? PendingCustomerRegistration::query()->unexpired()->where('registration_edit_token_hash', hash('sha256', $credential))->first() : null;
        if (! $owned || strcasecmp($owned->email, $data['email']) !== 0 || ! CustomerRegistrationService::canEdit($owned, $request)) {
            return app(CustomerRegistrationService::class)->requestPublic($data['email'], null, '');
        }
        if (User::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']])->exists()) {
            return app(CustomerRegistrationService::class)->requestPublic($data['email'], null, '');
        }
        try {
            $receipt = self::sendVerificationEmail($owned);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['success' => false,
                'message' => "Couldn't send the verification email. Try resending."], 503)->header('Cache-Control', 'no-store');
        }
        return response()->json([
            'success' => true,
            'message' => 'Verification email queued.',
            'email_delivery_token' => $receipt,
            'email_delivery_queued' => true,
            ...app(AuthenticationEmailPolicy::class)->status($data['email']),
        ])->header('Cache-Control', 'no-store');
    }

    // A same-origin tab may restore only an existing usable HttpOnly session.
    // This endpoint never issues a new session from a pending/edit credential.
    public function registrationSession(Request $request)
    {
        $cookie = $request->cookie(UseAccountSessionCookie::NAME);
        $token = is_string($cookie) ? PersonalAccessToken::findToken($cookie) : null;
        $user = $token?->tokenable;
        if (! $token || ! $user instanceof User || $user->role !== 'customer' || ! $user->email_verified_at
            || ! $user->is_active || $user->is_archived || ! app(AccountSessionService::class)->isUsable($token, $user)) {
            return response()->json(['success' => false, 'message' => 'Please sign in again.'], 401)->header('Cache-Control', 'no-store');
        }
        $key = 'registration-handoff:'.$token->getKey();
        if (! Cache::has($key) || ($request->filled('email') && strcasecmp($request->input('email'), $user->email) !== 0)) {
            return response()->json(['success' => false, 'message' => 'Please sign in again.'], 401)->header('Cache-Control', 'no-store');
        }
        if ($request->boolean('acknowledge') && Cache::has($key)) Cache::put($key, true, 600);
        return response()->json(['success' => true, 'session_token' => $token->getKey().'|cookie',
            'original_tab_confirmed' => Cache::get($key) === true,
            'user' => $user->only(['user_id', 'first_name', 'last_name', 'email', 'phone', 'role']),
        ])->header('Cache-Control', 'no-store');
    }

    public function deliveryStatus(Request $request)
    {
        $data = $request->validate(['delivery_token' => 'required|string|size:64']);
        return response()->json(['success' => true, 'status' => AuthenticationMailDelivery::status($data['delivery_token'])])
            ->header('Cache-Control', 'no-store');
    }

    public static function sendVerificationEmail(User|PendingCustomerRegistration $registrant): string
    {
        return app(RegistrationMagicLinkService::class)->send($registrant);
    }

    public static function assertMailCanBeDelivered(): void
    {
        RegistrationMagicLinkService::assertMailerDoesNotLogSecrets();
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        $mailer = (string) config('mail.default');
        $frontendUrl = (string) config('app.frontend_url');
        $fromAddress = Str::lower((string) config('mail.from.address'));
        $smtpHost = Str::lower((string) config('mail.mailers.smtp.host'));

        $invalidMailer = in_array($mailer, ['log', 'array'], true)
            || in_array(config('queue.default'), ['null'], true);
        $invalidFrontendUrl = parse_url($frontendUrl, PHP_URL_SCHEME) !== 'https';
        $placeholderSender = $fromAddress === ''
            || str_ends_with($fromAddress, '@example.com');
        $localSmtp = $mailer === 'smtp'
            && in_array($smtpHost, ['', '127.0.0.1', 'localhost'], true);

        if ($invalidMailer || $invalidFrontendUrl || $placeholderSender || $localSmtp) {
            throw new \RuntimeException(
                'Deployed email verification requires a deliverable mailer, HTTPS FRONTEND_URL, and verified sender.',
            );
        }
    }

}
