<?php

namespace App\Http\Controllers;

use App\Http\Middleware\UseAccountSessionCookie;
use App\Rules\AccountPassword;
use App\Services\CustomerSecurityChangeService;
use App\Services\RememberedDeviceService;
use App\Support\AccountEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerSecurityChangeController extends Controller
{
    public function state(Request $request, string $kind, CustomerSecurityChangeService $service): JsonResponse
    {
        return response()->json(['challenge' => $service->pending($request->user(), $kind)])->header('Cache-Control', 'no-store');
    }

    public function startEmail(Request $request, CustomerSecurityChangeService $service): JsonResponse
    {
        $this->allowOnly($request, ['email', 'current_password']);
        $data = $request->validate([
            'email' => AccountEmail::rules(),
            'current_password' => ['required', 'string', 'max:4096'],
        ], ['current_password.required' => 'Enter your current password.']);

        return $this->respond($request, $service->start(
            $request->user(), 'email', $data['current_password'], AccountEmail::normalize($data['email']),
        ), 202);
    }

    public function startPassword(Request $request, CustomerSecurityChangeService $service): JsonResponse
    {
        $this->allowOnly($request, ['current_password', 'password', 'password_confirmation']);
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => AccountPassword::rules('customer', screen: false),
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], AccountPassword::messages() + ['current_password.required' => 'Enter your current password.']);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'Incorrect current password.']);
        }
        if (Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['password' => 'Choose a different password.']);
        }
        // The external breach screening request holds no database locks.
        Validator::make($data, ['password' => AccountPassword::rules('customer', $user->toArray())])->validate();

        return $this->respond($request, $service->start($user, 'password', $data['current_password'], $data['password']), 202);
    }

    public function confirm(Request $request, string $kind, string $id, CustomerSecurityChangeService $service): JsonResponse
    {
        $this->allowOnly($request, ['code']);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']],
            ['code.required' => 'Enter the 6-digit code.', 'code.regex' => 'Enter the 6-digit code.']);

        return $this->respond($request, $service->confirm($request->user(), $kind, $id, $data['code']));
    }

    public function resend(Request $request, string $kind, string $id, CustomerSecurityChangeService $service): JsonResponse
    {
        $this->allowOnly($request, []);

        return $this->respond($request, $service->resend($request->user(), $kind, $id));
    }

    public function cancel(Request $request, string $kind, string $id, CustomerSecurityChangeService $service): JsonResponse
    {
        $this->allowOnly($request, []);

        return $this->respond($request, $service->cancel($request->user(), $kind, $id));
    }

    private function allowOnly(Request $request, array $fields): void
    {
        $unexpected = array_diff(array_keys($request->all()), $fields);
        if ($unexpected) throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
    }

    private function respond(Request $request, array $result, int $successStatus = 200): JsonResponse
    {
        $status = $result['status'];
        if (in_array($status, ['pending', 'cancelled', 'completed'], true)) {
            // The previous password client interprets success:true as a committed password change.
            $response = response()->json($status === 'pending' ? $result : ['success' => true, ...$result], $successStatus)
                ->header('Cache-Control', 'no-store');
            if ($status === 'completed') {
                foreach ([UseAccountSessionCookie::NAME, RememberedDeviceService::COOKIE] as $cookie) {
                    $response->withCookie(UseAccountSessionCookie::cookie($request, $cookie, '', now()->subYear()));
                }
            }

            return $response;
        }

        $message = match ($status) {
            'cooldown' => 'Please wait before requesting another code.',
            'send_limit' => 'Resend limit reached. Try again later.',
            'locked' => 'Too many incorrect codes. Try again later.',
            'expired' => 'The code has expired. Request a new code.',
            'invalid_code' => 'The code is incorrect.',
            'delivery_failed' => 'The code could not be delivered. Request a new code.',
            default => 'This request has expired or is no longer available.',
        };
        $response = response()->json(['message' => $message, 'code' => $status, ...$result],
            isset($result['retry_after']) ? 429 : 422)->header('Cache-Control', 'no-store');
        if (isset($result['retry_after'])) $response->header('Retry-After', (string) $result['retry_after']);

        return $response;
    }
}
