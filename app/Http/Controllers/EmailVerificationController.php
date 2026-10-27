<?php

namespace App\Http\Controllers;

use App\Support\AccountEmail;
use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Services\RegistrationEmailCodeService;
use App\Services\CustomerRegistrationService;
use App\Services\AuthenticationMailDelivery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EmailVerificationController extends Controller
{
    // New registrations use { email, code }. Tokens only consume already-issued links.
    public function verify(Request $request)
    {
        $codeMode = ! $request->has('token');
        if ($codeMode) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
            $data = $request->validate(['email' => 'required|email|max:150']);
            $code = (string) $request->input('code');
            if (! preg_match('/^[0-9]{6}$/', $code)) {
                return self::invalidCode();
            }
            $plainToken = null;
        } else {
            $data = $request->validate(['token' => 'required|string|size:64']);
            $plainToken = $data['token'];
            $code = null;
        }
        $tokenHash = $plainToken ? hash('sha256', $plainToken) : null;

        try {
            return DB::transaction(function () use ($request, $plainToken, $tokenHash, $codeMode, $data, $code) {
                // A legacy user takes precedence over any inconsistent pending row.
                // Never apply the pending password to an existing account.
                if ($codeMode) {
                    $existingUser = User::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']])->lockForUpdate()->first();
                    if ($existingUser) {
                        if (! CustomerRegistrationService::recoverableUser($existingUser)) return self::invalidCode();
                        if ($error = self::validateCode($existingUser, $code)) return $error;
                        return app(CustomerRegistrationService::class)->authorizeReview($existingUser);
                    }
                }
                $pendingRegistration = PendingCustomerRegistration::query()
                    ->where(function ($query) use ($plainToken, $tokenHash, $codeMode, $data) {
                        if ($codeMode) {
                            $query->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']]);
                        } else {
                            $query->where('email_verification_token', $tokenHash)
                                ->orWhere('email_verification_token', $plainToken);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($pendingRegistration) {
                    if ($pendingRegistration->retentionExpired()) return self::invalidCode();
                    if ($codeMode && ($error = self::validateCode($pendingRegistration, $code))) {
                        return $error;
                    }
                    if (! $pendingRegistration->email_verification_expires_at
                        || now()->greaterThanOrEqualTo($pendingRegistration->email_verification_expires_at)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This verification link has expired. Please request a new one.',
                            'expired' => true,
                            'email' => $pendingRegistration->email,
                        ], 422);
                    }

                    if ($codeMode) {
                        $credential = $request->input('registration_token');
                        if (! is_string($credential) || ! $pendingRegistration->registration_edit_token_hash
                            || ! hash_equals($pendingRegistration->registration_edit_token_hash, hash('sha256', $credential))) {
                            return app(CustomerRegistrationService::class)->authorizeReview($pendingRegistration);
                        }
                    }
                    return app(CustomerRegistrationService::class)->complete($pendingRegistration);
                }

                if ($codeMode) return self::invalidCode();

                // Consume only links issued before the OTP rollout.
                $user = User::query()->whereNull('email_verified_at')
                    ->where(function ($query) use ($plainToken, $tokenHash) {
                        $query->where('email_verification_token', $tokenHash)
                            ->orWhere('email_verification_token', $plainToken);
                    })->lockForUpdate()->first();

                if (! $user) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or already-used verification link.',
                    ], 422);
                }

                if (! $user->is_active || $user->is_archived) {
                    return response()->json([
                        'success' => false,
                        'code' => 'account_disabled',
                        'message' => 'This account is disabled and cannot be verified. Please contact the clinic.',
                    ], 403);
                }

                if (! $user->email_verification_expires_at || now()->greaterThanOrEqualTo($user->email_verification_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This verification link has expired. Please request a new one.',
                        'expired' => true,
                        'email' => $user->email,
                    ], 422);
                }

                if ($user->role === 'customer') {
                    return app(CustomerRegistrationService::class)->complete($user);
                }

                $user->update([...CustomerRegistrationService::clearedVerification(), 'email_verified_at' => now()]);

                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully.',
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'These registration details are no longer available. Please register again.',
            ], 422);
        }
    }

    // POST /api/email/resend  { email: "..." }
    public function resend(Request $request)
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);
        $data = $request->validate(['email' => 'required|email']);

        // Existing users always win collisions, including verified/disabled users.
        $user = User::query()->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']])->first();
        $registrant = $user
            ? (CustomerRegistrationService::recoverableUser($user) ? $user : null)
            : PendingCustomerRegistration::query()->unexpired()->whereRaw(AccountEmail::lookupColumn().' = ?', [$data['email']])->first();

        $canInspectDelivery = $registrant instanceof PendingCustomerRegistration
            && is_string($request->input('registration_token'))
            && $registrant->registration_edit_token_hash
            && hash_equals($registrant->registration_edit_token_hash, hash('sha256', $request->input('registration_token')));

        if ($registrant) {
            try {
                $receipt = self::sendVerificationEmail($registrant);
            } catch (HttpResponseException $exception) {
                if ($canInspectDelivery) throw $exception;
            } catch (Throwable $exception) {
                report($exception);

                if ($canInspectDelivery) return response()->json([
                    'success' => false,
                    'message' => 'Verification email could not be queued right now. Please try again later.',
                ], 503);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If that email is registered and unverified, a new verification email is queued.',
            // Only the private signup tab may inspect delivery. Public recovery
            // responses must not reveal whether an address is registered.
            'email_delivery_token' => $canInspectDelivery ? ($receipt ?? null) : null,
            'resend_after' => $canInspectDelivery && isset($receipt)
                ? max(0, ($registrant->fresh()?->email_verification_last_sent_at?->timestamp ?? 0)
                    + RegistrationEmailCodeService::COOLDOWN_SECONDS - now()->timestamp)
                : RegistrationEmailCodeService::COOLDOWN_SECONDS,
        ]);
    }

    public function deliveryStatus(Request $request)
    {
        $data = $request->validate(['delivery_token' => 'required|string|size:64']);

        return response()->json([
            'success' => true,
            'status' => AuthenticationMailDelivery::status($data['delivery_token']),
        ])->header('Cache-Control', 'no-store');
    }

    public static function sendVerificationEmail(User|PendingCustomerRegistration $registrant): string
    {
        return app(RegistrationEmailCodeService::class)->send($registrant);
    }

    private static function invalidCode(bool $expired = false)
    {
        return response()->json(['success' => false, 'expired' => $expired,
            'message' => $expired ? 'This code has expired. Request a new one.' : 'Incorrect code. Try again.'], 422);
    }

    private static function validateCode(User|PendingCustomerRegistration $registrant, string $code)
    {
        if ($registrant->email_verification_attempts >= RegistrationEmailCodeService::MAX_ATTEMPTS) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Try again later.',
            ], 429);
        }
        if (! $registrant->email_verification_code_hash) return self::invalidCode();
        $expired = RegistrationEmailCodeService::codeExpired($registrant);
        if (! Hash::check($code, $registrant->email_verification_code_hash)) {
            if (! $expired) $registrant->increment('email_verification_attempts');
            return self::invalidCode();
        }
        if ($expired) return self::invalidCode(true);
        return null;
    }

    public static function assertMailCanBeDelivered(): void
    {
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        RegistrationEmailCodeService::assertMailerDoesNotLogCodes();

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
