<?php

namespace App\Http\Controllers;

use App\Models\PendingCustomerRegistration;
use App\Services\RegistrationMagicLinkService;
use App\Services\CustomerRegistrationService;
use App\Services\AuthenticationMailDelivery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EmailVerificationController extends Controller
{
    // Initial GET is inert; only this deliberate POST approves an attempt.
    public function verify(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        return DB::transaction(function () use ($data) {
            $pending = PendingCustomerRegistration::where('email_verification_token', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $pending) return $this->invalidLink();
            if ($pending->retentionExpired()) return $this->invalidLink('registration_expired', 'Registration expired. Start again.');
            if ($pending->registration_approved_at) return $this->invalidLink('registration_link_consumed', 'This email link has already been used. Return to the device where you started creating your account.');
            if (! RegistrationMagicLinkService::expiresAt($pending)?->isFuture()) return $this->invalidLink('registration_link_expired', 'This link has expired. Request a new one on your signup page.');
            // Retain consumed hash only to identify replay. Never issue a session here.
            $pending->update(['registration_approved_at' => now()]);
            return response()->json(['success' => true, 'registration_approved' => true])->header('Cache-Control', 'no-store');
        }, 3);
    }

    private function invalidLink(string $code = 'registration_link_invalid', string $message = 'This link is invalid or has been replaced. Return to your signup page to request a fresh link.')
    {
        return response()->json(['success' => false, 'code' => $code, 'message' => $message], 422)->header('Cache-Control', 'no-store');
    }

    public function resend(Request $request)
    {
        try {
            return app(CustomerRegistrationService::class)->resend($request);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['success' => false,
                'message' => "Couldn't send the verification email. Try resending."], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function deliveryStatus(Request $request)
    {
        $data = $request->validate(['delivery_token' => 'required|string|size:64']);
        return response()->json(['success' => true, 'status' => AuthenticationMailDelivery::status($data['delivery_token'])])
            ->header('Cache-Control', 'no-store');
    }

    public function registrationStatus(Request $request)
    {
        return app(CustomerRegistrationService::class)->status($request);
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
