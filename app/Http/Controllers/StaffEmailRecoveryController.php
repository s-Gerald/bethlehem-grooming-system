<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StaffEmailRecoveryService;
use App\Support\AccountEmail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class StaffEmailRecoveryController extends Controller
{
    public function start(Request $request, int $staff, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['email', 'current_password', 'reason', 'identity_verified', 'identity_method']);
        if (is_string($request->input('email'))) $request->merge(['email' => AccountEmail::normalize($request->input('email'))]);
        $data = $request->validate(['email' => AccountEmail::rules(), 'current_password' => ['required', 'string'],
            'reason' => ['required', Rule::in(['inaccessible', 'compromised'])], 'identity_verified' => ['required', 'accepted'],
            'identity_method' => ['required', Rule::in(['in_person_employment_record'])]]);
        return $this->respond(fn () => $service->start($request->user(), $staff, $data), $staff, 202);
    }
    public function confirm(Request $request, int $staff, string $recovery, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['code']);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        return $this->respond(fn () => $service->confirm($request->user(), $staff, $recovery, $data['code']), $staff);
    }
    public function resendCode(Request $request, int $staff, string $recovery, StaffEmailRecoveryService $service)
    {
        $this->only($request, []);
        return $this->respond(fn () => $service->resendCode($request->user(), $staff, $recovery), $staff);
    }
    public function resendLink(Request $request, int $staff, string $recovery, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['current_password']);
        $data = $request->validate(['current_password' => ['required', 'string']]);
        return $this->respond(fn () => $service->resendLink($request->user(), $staff, $recovery, $data['current_password']), $staff);
    }
    public function cancel(Request $request, int $staff, string $recovery, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['current_password', 'old_email_safe']);
        $data = $request->validate(['current_password' => ['nullable', 'string'], 'old_email_safe' => ['required', 'boolean']]);
        return $this->respond(fn () => $service->cancel($request->user(), $staff, $recovery, $data['current_password'] ?? null, $data['old_email_safe']), $staff);
    }
    public function history(Request $request, int $staff, StaffEmailRecoveryService $service)
    {
        return response()->json(['history' => $service->history($request->user(), $staff)])->header('Cache-Control', 'no-store');
    }
    public function verify(Request $request, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['token']);
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']]);
        return $this->respond(fn () => $service->verify($data['token']));
    }
    public function complete(Request $request, StaffEmailRecoveryService $service)
    {
        $this->only($request, ['token', 'current_password']);
        $data = $request->validate(['token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'current_password' => ['required', 'string']]);
        return $this->respond(fn () => $service->complete($data['token'], $data['current_password']));
    }
    private function only(Request $request, array $fields): void
    {
        $unexpected = array_diff(array_keys($request->all()), $fields);
        if ($unexpected) throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
    }
    private function respond(callable $operation, ?int $targetId = null, int $successStatus = 200)
    {
        try { $result = $operation(); }
        catch (UniqueConstraintViolationException) { throw ValidationException::withMessages(['email' => 'This email is already in use.']); }
        catch (ValidationException | \Illuminate\Database\Eloquent\ModelNotFoundException | HttpExceptionInterface $exception) { throw $exception; }
        catch (Throwable $exception) {
            // Never report request credentials or queue payloads.
            \Illuminate\Support\Facades\Log::warning('Staff email recovery could not be processed.', ['exception_type' => $exception::class]);
            return response()->json(['message' => 'Recovery could not be processed. Please try again.'], 503);
        }
        if (in_array($result['status'], ['authorizing', 'pending', 'verified', 'completed', 'cancelled'], true)) {
            if ($targetId) $result['staff'] = app(AdminSecurityController::class)->accountPayload(User::findOrFail($targetId)->load('emailRecovery'));
            return response()->json(['success' => true, ...$result], $successStatus)->header('Cache-Control', 'no-store');
        }
        $message = match ($result['status']) {
            'invalid_code' => 'The code is incorrect.', 'expired' => 'This code has expired. Start a new request.',
            'invalid_password' => 'The current account password is incorrect.', 'locked' => 'Too many attempts. Contact the clinic administrator.',
            'cooldown' => 'Please wait before requesting another email.',
            default => 'This recovery is expired or unavailable. Contact the clinic administrator.',
        };
        $response = response()->json(['message' => $message, ...$result], $result['status'] === 'cooldown' ? 429 : 422)->header('Cache-Control', 'no-store');
        if (isset($result['retry_after'])) $response->header('Retry-After', (string) $result['retry_after']);
        return $response;
    }
}
