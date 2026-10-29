<?php

namespace App\Http\Controllers;

use App\Models\LoginEmailChallenge;
use App\Models\PasswordResetRequest;
use App\Models\PrivilegedCredentialChange;
use App\Models\User;
use App\Services\PendingStaffAccountService;
use App\Services\PrivilegedCredentialChangeService;
use App\Support\AccountUsername;
use App\Support\AccountEmail;
use App\Support\StaffRole;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminSecurityController extends Controller
{
    public function activity(Request $request)
    {
        $data = $request->validate(['incident' => ['nullable', 'integer', 'min:1']]);
        return response()->json(['success' => true,
            'incidents' => app(\App\Services\AccountSecurityService::class)->recent($request->user(),
                isset($data['incident']) ? (string) $data['incident'] : null)])
            ->header('Cache-Control', 'no-store');
    }

    public function reviewActivity(Request $request, int $incident)
    {
        return DB::transaction(function () use ($request, $incident) {
            $record = \App\Models\AccountSecurityIncident::where('user_id', $request->user()->getKey())
                ->whereKey($incident)->lockForUpdate()->firstOrFail();
            if (! $record->reviewed_at) {
                $record->update(['reviewed_at' => now()]);
                app(\App\Services\ActivityRecorder::class)->record('security_incident_reviewed', 'system', 'account',
                    (string) $request->user()->getKey(), 'Security activity reviewed', [], false, $request->user(),
                    'security-reviewed:'.$record->getKey().':'.$record->last_occurred_at->timestamp.':'.$record->failure_count);
            }
            return response()->json(['success' => true, 'incident' => $record->payload()])->header('Cache-Control', 'no-store');
        });
    }

    public function accounts(Request $request)
    {
        $admin = $request->user();
        $staff = User::query()
            ->with(['passwordResetRequest', 'emailRecovery'])
            ->where('role', 'staff')
            ->where('is_archived', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $user): array => $this->accountPayload($user))
            ->values();

        return response()->json([
            'success' => true,
            'admin' => $this->accountPayload($admin),
            'staff' => $staff,
        ]);
    }

    public function ownAccount(Request $request)
    {
        return response()->json([
            'success' => true,
            'account' => $this->accountPayload($request->user()),
            'pending_email_change' => app(\App\Services\StaffEmailChangeService::class)->pending($request->user()),
        ]);
    }

    public function requestOwnChange(
        Request $request,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        $data = $this->validateChange($request, requireCurrentPassword: true);
        $admin = $request->user();

        if (! Hash::check($data['current_password'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        return $this->requestChange(
            $credentialChanges,
            $admin,
            $admin,
            $data,
        );
    }

    public function validateStaffProfile(Request $request)
    {
        $data = $this->staffProfileData($request);
        $this->assertStaffUsernameIsAvailable($data['username'], $request->user());

        return response()->json(['success' => true]);
    }

    public function updateStaffProfile(Request $request)
    {
        $data = $this->staffProfileData($request);
        try {
            $staff = DB::transaction(function () use ($request, $data): User {
                $staff = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
                abort_unless($staff->role === 'staff' && $staff->is_active && ! $staff->is_archived && ! $staff->requiresPasswordSetup(), 403);

                if ($data['username'] !== $staff->username) {
                    $this->assertStaffUsernameIsAvailable($data['username'], $staff, true);
                    if (! is_string($data['current_password'] ?? null) || $data['current_password'] === '') {
                        throw ValidationException::withMessages(['current_password' => 'Enter your current password.']);
                    }
                    if (! Hash::check($data['current_password'], $staff->password_hash)) {
                        throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
                    }
                }

                $staff->first_name = $data['first_name'];
                $staff->last_name = $data['last_name'];
                $staff->username = $data['username'];
                if ($staff->isDirty()) {
                    $staff->save();
                }

                return $staff;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // The unique index is authoritative when two requests claim the same username.
            throw ValidationException::withMessages(['username' => 'That username is already in use.']);
        }

        if ($staff->wasChanged()) {
            Log::info('Staff profile updated.', ['user_id' => $staff->getKey(), 'fields' => array_keys($staff->getChanges())]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'account' => $this->accountPayload($staff),
            'user' => array_merge($this->accountPayload($staff), [
                'clinic_permissions' => $staff->clinicPermissions(), 'staff_permissions' => $staff->staffPermissions(),
            ]),
            'sidebar_revision' => $staff->sidebarRevision(),
        ]);
    }

    private function staffProfileData(Request $request): array
    {
        $unexpected = array_diff(array_keys($request->all()), ['first_name', 'last_name', 'username', 'current_password']);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
        }
        foreach (['first_name', 'last_name', 'username'] as $field) {
            if (is_string($request->input($field))) {
                $value = $request->input($field);
                $request->merge([$field => $field === 'username' ? trim($value) : ($value === $request->user()->$field ? $value : User::normalizeName($value))]);
            }
        }

        return $request->validate([
            'first_name' => \App\Support\HumanName::rules(),
            'last_name' => \App\Support\HumanName::rules(),
            'username' => AccountUsername::rules(),
            'current_password' => ['nullable', 'string'],
        ], [
            'username.required' => 'Enter a username.',
            'username.regex' => 'Use letters, numbers, periods, underscores, or hyphens, beginning with a letter.',
        ]);
    }

    private function assertStaffUsernameIsAvailable(string $username, User $staff, bool $lockForUpdate = false): void
    {
        if (! AccountUsername::isAvailableToStaff($username, $staff, $lockForUpdate)) {
            throw ValidationException::withMessages(['username' => 'That username is already in use.']);
        }
    }

    public function requestStaffPasswordChange(
        Request $request,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['required', 'string'],
            'username' => ['prohibited'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'staff_type' => ['prohibited'],
            'staff_subrole' => ['prohibited'],
            'active' => ['prohibited'],
        ]);
        $staff = $request->user();

        if (! Hash::check($data['current_password'], $staff->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        return $this->requestChange(
            $credentialChanges,
            $staff,
            $staff,
            $data,
        );
    }

    public function requestStaffAccount(
        Request $request,
        PendingStaffAccountService $staffAccounts,
    ) {
        $firstName = $request->input('first_name');
        $lastName = $request->input('last_name');
        $staffSubrole = trim((string) $request->input('staff_subrole'));
        $request->merge([
            'first_name' => is_string($firstName) ? User::normalizeName($firstName) : $firstName,
            'last_name' => is_string($lastName) ? User::normalizeName($lastName) : $lastName,
            'email' => AccountEmail::normalize((string) $request->input('email')),
            'staff_subrole' => $staffSubrole === '' ? null : $staffSubrole,
        ]);
        $data = $request->validate([
            'staff_type' => ['required', Rule::in(['clinic', 'grooming'])],
            'staff_subrole' => [
                'required',
                'string',
                Rule::in(StaffRole::subrolesForType((string) $request->input('staff_type'))),
            ],
            'first_name' => \App\Support\HumanName::rules(),
            'last_name' => \App\Support\HumanName::rules(),
            'email' => PendingStaffAccountService::emailRules(),
        ]);

        try {
            $result = $staffAccounts->request(
                $request->user(),
                $data['staff_type'],
                $data['staff_subrole'],
                $data['first_name'],
                $data['last_name'],
                $data['email'],
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The password setup email could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Account setup email sent',
            'staff' => $this->accountPayload($result['staff']),
        ], 201);
    }

    public function updateStaffStatus(Request $request, User $staff)
    {
        if ($staff->role !== 'staff' || $staff->is_archived) {
            abort(404);
        }

        if ($staff->requiresPasswordSetup()) {
            return response()->json([
                'success' => false,
                'message' => 'Pending staff setup cannot be deactivated or reactivated.',
            ], 422);
        }

        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->verifyAdminPassword($request);
        $active = (bool) $data['active'];
        $staff->is_active = $active;
        $staff->save();

        if (! $active) {
            $staff->tokens()->delete();
            LoginEmailChallenge::query()->where('user_id', $staff->user_id)->delete();
            PrivilegedCredentialChange::query()
                ->where('target_user_id', $staff->user_id)
                ->whereNull('confirmed_at')
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $active
                ? 'Staff account reactivated.'
                : 'Staff account deactivated.',
            'staff' => $this->accountPayload($staff->fresh()->load('emailRecovery')),
        ]);
    }

    public function updateStaffRole(Request $request, User $staff)
    {
        if ($staff->role !== 'staff' || $staff->is_archived) {
            abort(404);
        }

        $data = $request->validate([
            'staff_role' => ['required', Rule::in(array_keys(StaffRole::TYPES))],
        ]);
        $this->verifyAdminPassword($request, 'Incorrect password. Try again.');
        $roleFields = StaffRole::fields($data['staff_role']);
        $staffType = $roleFields['staff_type'];
        $staffSubrole = $roleFields['staff_subrole'];

        $updated = DB::transaction(function () use ($staff, $staffType, $staffSubrole): User {
            $target = User::query()->lockForUpdate()->findOrFail($staff->getKey());
            if ($target->role !== 'staff' || $target->is_archived) {
                abort(404);
            }

            if ($target->staff_type !== $staffType || $target->staff_subrole !== $staffSubrole) {
                $target->staff_type = $staffType;
                $target->staff_subrole = $staffSubrole;
                $target->save();
                $target->tokens()->delete();
                LoginEmailChallenge::query()->where('user_id', $target->user_id)->delete();
            }

            return $target->load(['passwordResetRequest', 'emailRecovery']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully',
            'staff' => $this->accountPayload($updated),
        ]);
    }

    public function correctStaffSetupEmail(Request $request, User $staff, PendingStaffAccountService $staffAccounts)
    {
        $unexpected = array_diff(array_keys($request->all()), ['email', 'current_password']);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed.'));
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => AccountEmail::normalize($request->input('email'))]);
        }
        $data = $request->validate([
            'email' => PendingStaffAccountService::emailRules(),
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Enter your admin password.',
        ]);

        try {
            $updated = $staffAccounts->correctEmail($request->user(), $staff, $data['email'], $data['current_password']);
        } catch (ValidationException | \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['success' => false, 'message' => 'The setup email could not be queued. Please try again.'], 503);
        }

        return response()->json([
            'success' => true,
            'message' => "Setup email queued for {$updated->email}.",
            'staff' => $this->accountPayload($updated),
        ]);
    }

    public function resendStaffSetupEmail(User $staff, \App\Services\PasswordResetService $passwordResets)
    {
        if ($staff->role !== 'staff' || $staff->is_archived || ! $staff->requiresPasswordSetup()) {
            abort(404);
        }

        try {
            $result = $passwordResets->resendStaffSetupLink($staff);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The setup email could not be queued. Please try again.',
            ], 503);
        }

        if ($result['status'] !== 'sent') {
            return response()->json(['success' => false, 'message' => 'This setup invitation is no longer available.'], 422);
        }

        return response()->json(['success' => true, 'message' => 'A new setup email was sent. Previous setup links are no longer valid.']);
    }

    public function cancelStaffSetup(Request $request, User $staff)
    {
        if ($staff->role !== 'staff' || $staff->is_archived) {
            abort(404);
        }

        $this->verifyAdminPassword($request);

        $cancelled = DB::transaction(function () use ($staff): bool {
            $pending = User::query()->whereKey($staff->getKey())->lockForUpdate()->first();
            if (! $pending?->requiresPasswordSetup()) {
                return false;
            }

            PasswordResetRequest::query()->where('user_id', $pending->getKey())->delete();
            $pending->tokens()->delete();
            $pending->delete();

            return true;
        });

        if (! $cancelled) {
            return response()->json(['success' => false, 'message' => 'This setup invitation is no longer available.'], 422);
        }

        return response()->json(['success' => true, 'message' => 'Account setup cancelled.']);
    }

    public function confirm(
        Request $request,
        PrivilegedCredentialChange $change,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Enter the six-digit security code.',
        ]);
        $result = $credentialChanges->confirm(
            $request->user(),
            $change,
            $data['code'],
        );

        if ($result['status'] === 'expired') {
            return response()->json([
                'success' => false,
                'expired' => true,
                'message' => 'This security code has expired. Request a new code.',
            ], 422);
        }

        if ($result['status'] === 'invalid_code') {
            return response()->json([
                'success' => false,
                'message' => 'The security code is incorrect.',
                'attempts_remaining' => $result['attempts_remaining'],
            ], 422);
        }

        if ($result['status'] === 'locked') {
            return response()->json([
                'success' => false,
                'message' => 'Too many incorrect attempts. Request a new code.',
                'attempts_remaining' => 0,
            ], 422);
        }

        if ($result['status'] === 'username_unavailable') {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        if ($result['status'] !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'This security verification is invalid or has already been used.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "{$result['purpose']} confirmed.",
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'target_role' => $result['target_role'],
            'changes' => $result['changes'],
            'requires_reauthentication' => $result['requires_reauthentication'],
        ]);
    }

    public function resend(
        Request $request,
        PrivilegedCredentialChange $change,
        PrivilegedCredentialChangeService $credentialChanges,
    ) {
        try {
            $result = $credentialChanges->resend($request->user(), $change);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The security code email could not be queued. Please try again.',
            ], 503);
        }

        if ($result['status'] === 'cooldown') {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another code.',
                'retry_after' => $result['retry_after'],
            ], 429)->header('Retry-After', (string) $result['retry_after']);
        }

        if ($result['status'] !== 'resent') {
            return response()->json([
                'success' => false,
                'message' => 'This security verification is no longer available.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'New code requested. Check your email.',
            'change_id' => $result['change_id'],
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'confirmation_email' => $result['confirmation_email'],
            'expires_at' => $result['expires_at'],
            'resend_after' => $result['resend_after'],
            'email_delivery_token' => $result['email_delivery_token'],
        ]);
    }

    private function verifyAdminPassword(Request $request, string $incorrectMessage = 'The admin password is incorrect.'): void
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Enter your admin password to confirm.',
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => $incorrectMessage,
            ]);
        }
    }

    private function requestChange(
        PrivilegedCredentialChangeService $credentialChanges,
        User $requester,
        User $target,
        array $data,
    ) {
        try {
            $result = $credentialChanges->request(
                $requester,
                $target,
                $data['username'] ?? null,
                $data['password'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The security code email could not be queued. Please try again.',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'New code requested. Check your email.',
            'change_id' => $result['change_id'],
            'purpose' => $result['purpose'],
            'target_name' => $result['target_name'],
            'confirmation_email' => $result['confirmation_email'],
            'expires_at' => $result['expires_at'],
            'resend_after' => $result['resend_after'],
            'email_delivery_token' => $result['email_delivery_token'],
        ], 202);
    }

    private function validateChange(Request $request, bool $requireCurrentPassword = false): array
    {
        return $request->validate([
            'current_password' => $requireCurrentPassword
                ? ['required', 'string']
                : ['prohibited'],
            'username' => AccountUsername::rules('nullable'),
            'password' => [
                'nullable',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'password_confirmation' => ['nullable', 'string'],
        ], [
            'username.regex' => 'Use letters, numbers, periods, underscores, or hyphens, beginning with a letter.',
        ]);
    }

    public function accountPayload(User $user): array
    {
        return [
            'user_id' => $user->user_id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'staff_type' => $user->staff_type,
            'staff_subrole' => $user->staff_subrole,
            'is_active' => (bool) $user->is_active,
            'is_archived' => (bool) $user->is_archived,
            'email_recovery' => $user->relationLoaded('emailRecovery') && $user->emailRecovery
                ? app(\App\Services\StaffEmailRecoveryService::class)->payload($user->emailRecovery) : null,
            'password_setup_required' => $user->requiresPasswordSetup(),
            'setup_link_expired' => $user->requiresPasswordSetup()
                && $user->relationLoaded('passwordResetRequest')
                && ($user->passwordResetRequest === null || now()->isAfter($user->passwordResetRequest->expires_at)),
        ];
    }
}
