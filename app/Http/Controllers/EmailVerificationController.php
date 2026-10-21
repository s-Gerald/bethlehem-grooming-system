<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Models\User;
use App\Services\RegistrationEmailCodeService;
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
            return DB::transaction(function () use ($plainToken, $tokenHash, $codeMode, $data, $code) {
                $pendingRegistration = PendingCustomerRegistration::query()
                    ->where(function ($query) use ($plainToken, $tokenHash, $codeMode, $data) {
                        if ($codeMode) {
                            $query->where('email', $data['email']);
                        } else {
                            $query->where('email_verification_token', $tokenHash)
                                ->orWhere('email_verification_token', $plainToken);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($pendingRegistration) {
                    if ($codeMode && ($error = self::validateCode($pendingRegistration, $code))) {
                        return $error;
                    }
                    if ($pendingRegistration->email_verification_expires_at
                        && now()->isAfter($pendingRegistration->email_verification_expires_at)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This verification link has expired. Please request a new one.',
                            'expired' => true,
                            'email' => $pendingRegistration->email,
                        ], 422);
                    }

                    $conflictingUser = User::query()
                        ->where(function ($query) use ($pendingRegistration) {
                            $query->where('email', $pendingRegistration->email)
                                ->orWhere('phone', $pendingRegistration->phone);

                            if ($pendingRegistration->username) {
                                $query->orWhere('username', $pendingRegistration->username);
                            }
                        })
                        ->lockForUpdate()
                        ->exists();

                    if ($conflictingUser) {
                        $pendingRegistration->delete();

                        return response()->json([
                            'success' => false,
                            'message' => 'These registration details are no longer available. Please register again.',
                        ], 422);
                    }

                    $user = User::create([
                        'first_name' => $pendingRegistration->first_name,
                        'last_name' => $pendingRegistration->last_name,
                        'username' => $pendingRegistration->username,
                        'email' => $pendingRegistration->email,
                        'phone' => $pendingRegistration->phone,
                        'password_hash' => $pendingRegistration->password_hash,
                        'role' => 'customer',
                        'customer_tier' => 'new',
                        'is_active' => true,
                        'is_archived' => false,
                        'email_verified_at' => now(),
                    ]);
                    $pendingRegistration->delete();

                    $token = $user->createToken('auth_token')->plainTextToken;

                    return response()->json([
                        'success' => true,
                        'message' => 'Email verified and account registered successfully.',
                        'token' => $token,
                        'user' => self::customerPayload($user),
                    ]);
                }

                // Existing accounts may consume already-issued links. New sends
                // exclusively issue codes and clear the legacy token.
                $user = User::query()
                    ->whereNull('email_verified_at')
                    ->where(function ($query) use ($plainToken, $tokenHash, $codeMode, $data) {
                        if ($codeMode) {
                            $query->where('email', $data['email']);
                        } else {
                            $query->where('email_verification_token', $tokenHash)
                                ->orWhere('email_verification_token', $plainToken);
                        }
                    })
                    ->lockForUpdate()
                    ->first();

                if ($codeMode) {
                    if (! $user) return self::invalidCode();
                    if ($error = self::validateCode($user, $code)) return $error;
                }

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

                if ($user->email_verification_expires_at && now()->isAfter($user->email_verification_expires_at)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This verification link has expired. Please request a new one.',
                        'expired' => true,
                        'email' => $user->email,
                    ], 422);
                }

                $user->update([
                    'email_verified_at' => now(),
                    'email_verification_token' => null,
                    'email_verification_expires_at' => null,
                    'email_verification_code_hash' => null,
                    'email_verification_last_sent_at' => null,
                    'email_verification_attempts' => 0,
                ]);

                $response = [
                    'success' => true,
                    'message' => 'Email verified successfully.',
                ];

                if ($user->role === 'customer') {
                    $response['token'] = $user->createToken('auth_token')->plainTextToken;
                    $response['user'] = self::customerPayload($user);
                }

                return response()->json($response);
            });
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

        $registrant = PendingCustomerRegistration::query()
            ->where('email', $data['email'])
            ->first();

        $registrant ??= User::query()
            ->where('email', $data['email'])
            ->whereNull('email_verified_at')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->first();

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
                ? max(0, ($registrant->fresh()->email_verification_last_sent_at?->timestamp ?? 0)
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

    private static function invalidCode()
    {
        return response()->json(['success' => false, 'message' => 'Invalid or expired verification code.'], 422);
    }

    private static function validateCode(User|PendingCustomerRegistration $registrant, string $code)
    {
        if ($registrant->email_verification_attempts >= RegistrationEmailCodeService::MAX_ATTEMPTS) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts. Try again later.',
            ], 429);
        }
        if (! $registrant->email_verification_code_hash
            || ! $registrant->email_verification_expires_at
            || now()->greaterThanOrEqualTo($registrant->email_verification_expires_at)) {
            return self::invalidCode();
        }
        if (! Hash::check($code, $registrant->email_verification_code_hash)) {
            $registrant->increment('email_verification_attempts');
            return self::invalidCode();
        }
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

    private static function customerPayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
        ];
    }
}
