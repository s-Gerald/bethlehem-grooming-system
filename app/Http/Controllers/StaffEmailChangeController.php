<?php

namespace App\Http\Controllers;

use App\Services\StaffEmailChangeService;
use App\Support\AccountEmail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StaffEmailChangeController extends Controller
{
    public function start(Request $request, StaffEmailChangeService $service)
    {
        $this->only($request, ['email', 'current_password']);
        if (is_string($request->input('email'))) $request->merge(['email' => AccountEmail::normalize($request->input('email'))]);
        $data = $request->validate(['email' => AccountEmail::rules(), 'current_password' => ['required', 'string']], [
            'current_password.required' => 'Enter your current password.',
        ]);
        return $this->respond(fn () => $service->start($request->user(), $data['email'], $data['current_password']), 202);
    }

    public function confirm(Request $request, string $change, StaffEmailChangeService $service)
    {
        $this->only($request, ['stage', 'code']);
        $data = $request->validate(['stage' => ['required', Rule::in(['current', 'new'])], 'code' => ['required', 'string', 'regex:/^\d{6}$/']],
            ['code.required' => 'Enter the 6-digit code.', 'code.regex' => 'Enter the 6-digit code.']);
        return $this->respond(fn () => $service->confirm($request->user(), $change, $data['stage'], $data['code']));
    }

    public function resend(Request $request, string $change, StaffEmailChangeService $service)
    {
        $this->only($request, ['stage']);
        $data = $request->validate(['stage' => ['required', Rule::in(['current', 'new'])]]);
        return $this->respond(fn () => $service->resend($request->user(), $change, $data['stage']));
    }

    public function cancel(Request $request, string $change, StaffEmailChangeService $service)
    {
        $this->only($request, []);
        return $this->respond(fn () => $service->cancel($request->user(), $change));
    }

    private function only(Request $request, array $fields): void
    {
        $unexpected = array_diff(array_keys($request->all()), $fields);
        if ($unexpected) throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
    }

    private function respond(callable $operation, int $successStatus = 200)
    {
        try { $result = $operation(); }
        catch (ValidationException | HttpExceptionInterface | \Illuminate\Http\Exceptions\HttpResponseException $exception) { throw $exception; }
        catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'The verification email could not be queued. Please try again.'], 503);
        }
        $status = $result['status'];
        if (in_array($status, ['pending', 'completed', 'cancelled'], true)) {
            return response()->json(['success' => true, ...$result])->header('Cache-Control', 'no-store')->setStatusCode($successStatus);
        }
        $message = match ($status) {
            'cooldown' => 'Please wait before requesting another code.',
            'locked' => 'Too many attempts. Try again later.',
            'expired' => 'The code has expired. Request a new code.',
            'invalid_code' => 'The code is incorrect.',
            'stale_stage' => 'This verification step has changed. Resume the email change from Your Account.',
            default => 'This email change has expired or is no longer available. Start a new request.',
        };
        $response = response()->json(['message' => $message, ...$result], $status === 'cooldown' ? 429 : ($status === 'stale_stage' ? 409 : 422));
        if (isset($result['retry_after'])) $response->header('Retry-After', (string) $result['retry_after']);
        return $response;
    }
}
