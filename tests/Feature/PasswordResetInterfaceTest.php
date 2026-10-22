<?php

namespace Tests\Feature;

use Tests\TestCase;

class PasswordResetInterfaceTest extends TestCase
{
    public function test_forgot_password_keeps_request_confirmation_and_reset_steps_on_one_page(): void
    {
        $signIn = file_get_contents(base_path('pages/client/sign-in.html'));
        $forgot = file_get_contents(base_path('pages/client/forgot-password.html'));
        $forgotScript = file_get_contents(base_path('scripts/auth/forgot-password.js'));
        $api = file_get_contents(base_path('scripts/api.js'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertStringContainsString('href="./forgot-password.html"', $signIn);
        $this->assertStringNotContainsString('Forgot password?" has no password reset flow', $signIn);
        $this->assertStringContainsString('id="forgotPasswordForm"', $forgot);
        $this->assertStringContainsString('type="email"', $forgot);
        $this->assertStringContainsString('API.requestPasswordReset(requestedEmail)', $forgotScript);
        $this->assertStringNotContainsString('min-height: 36rem', $forgot);
        $this->assertSame(6, substr_count($forgot, 'class="verification-code-cell"'));
        // Recovery must inherit the same OTP geometry and states as email/login verification.
        $verification = file_get_contents(base_path('pages/client/verify-email.html'));
        foreach (['verification-page', 'verification-layout', 'verification-card', 'verification-logo',
            'verification-heading', 'verification-primary', 'verification-resend', 'verification-link'] as $sharedClass) {
            $this->assertMatchesRegularExpression('/class="[^"]*\b'.$sharedClass.'\b/', $forgot);
            $this->assertMatchesRegularExpression('/class="[^"]*\b'.$sharedClass.'\b/', $verification);
        }
        $this->assertStringContainsString('verification-code.css?', $forgot);
        $this->assertStringContainsString('verification-code.css?', $verification);
        $this->assertStringContainsString('id="verifyEmail"', $forgot);
        $this->assertStringNotContainsString('verifyEmail.textContent = requestedEmail', $forgotScript);
        $this->assertStringContainsString('verifyEmail.textContent = maskedEmail', $forgotScript);
        $this->assertStringContainsString('id="resendCode"', $forgot);
        $this->assertStringContainsString('id="passwordRequirements"', $forgot);
        $this->assertStringContainsString('id="newPasswordMessage"', $forgot);
        $this->assertStringContainsString('id="confirmPasswordMessage"', $forgot);
        $this->assertStringContainsString('overflow-wrap: anywhere', file_get_contents(base_path('css/components/verification-code.css')));

        foreach ([
            'id="resetPasswordForm"',
            'id="newPassword"',
            'id="confirmNewPassword"',
            'id="verifyCodeForm"',
            'id="verificationCode"',
            'inputmode="numeric"',
            'maxlength="6"',
            'id="forgotBack"',
            'id="backToSignIn"',
            'autocomplete="off"',
            'data-lpignore="true"',
            'data-1p-ignore="true"',
            'phosphor.svg#eye-slash',
            'phosphor.svg#eye',
        ] as $control) {
            $this->assertStringContainsString($control, $forgot);
        }

        $this->assertStringContainsString('showStep("code")', $forgotScript);
        $this->assertStringContainsString('showStep("password")', $forgotScript);
        $this->assertStringContainsString('"Check Your Email"', $forgotScript);
        $this->assertStringContainsString('"Create New Password"', $forgotScript);
        $this->assertStringContainsString('API.verifyPasswordResetCode(requestedEmail, codeInput.value.trim())', $forgotScript);
        $this->assertStringContainsString('await API.resetPassword(', $forgotScript);
        $this->assertStringContainsString('"./sign-in.html?password_reset=1"', $forgotScript);
        $this->assertStringContainsString('window.VerificationCode.bind', $forgotScript);
        $this->assertStringContainsString('codeInput.addEventListener("paste"', file_get_contents(base_path('scripts/auth/verification-code.js')));
        $this->assertStringContainsString('setFieldError(confirmation, confirmPasswordMessage', $forgotScript);
        $this->assertFileDoesNotExist(base_path('pages/client/reset-password.html'));
        $this->assertFileDoesNotExist(base_path('scripts/auth/reset-password.js'));
        $this->assertMatchesRegularExpression("~/password/code/verify'.*?throttle:10,1~s", $routes);
        $this->assertMatchesRegularExpression("~/password/reset'.*?throttle:10,1~s", $routes);

        foreach ([
            'requestPasswordReset',
            'verifyPasswordResetCode',
            'resetPassword',
            '"/password/forgot"',
            '"/password/code/verify"',
            '"/password/reset"',
        ] as $apiContract) {
            $this->assertStringContainsString($apiContract, $api);
        }
    }

    public function test_staff_password_setup_page_uses_the_login_design_without_login_extras(): void
    {
        $page = file_get_contents(base_path('pages/client/set-up-password.html'));
        $script = file_get_contents(base_path('scripts/auth/set-up-password.js'));
        $api = file_get_contents(base_path('scripts/api.js'));

        foreach ([
            'Bethlehem Animal Clinic Logo',
            '>Set Up Your Password</h1>',
            'id="setupAccountDescription" class="hidden mt-4 text-sm leading-6 text-slate-500">Create a password to secure the account linked to <strong id="setupAccountEmail" class="break-all"></strong>.</p>',
            '>Username (optional)</label>',
            'placeholder="Enter a username"',
            'aria-describedby="setupUsernameMessage"',
            '>New password</label>',
            '>Confirm new password</label>',
            'autocomplete="new-password"',
            'phosphor.svg#eye-slash',
            'phosphor.svg#eye',
            '>Create Account</span>',
            'id="setupExpiredLink"',
            'This setup link has expired. Request a new link to finish setting up your account.',
            '>Request New Setup Link</button>',
        ] as $content) {
            $this->assertStringContainsString($content, $page);
        }

        foreach ([
            'Remember me',
            'Forgot password?',
            "Don't have an account?",
            '>Home</a>',
        ] as $excluded) {
            $this->assertStringNotContainsString($excluded, $page);
        }

        $this->assertSame(2, substr_count($page, 'autocomplete="new-password"'));
        $this->assertLessThan(strpos($page, 'for="setupNewPassword"'), strpos($page, 'for="setupUsername"'));
        $this->assertMatchesRegularExpression('/id="setupUsername"(?:(?!required)[\s\S])*?\/>/', $page);
        $this->assertStringContainsString('username.value.trim() || null', $script);
        $this->assertStringContainsString('setUsernameError(error.errors.username[0])', $script);
        $this->assertStringNotContainsString('name="username"', file_get_contents(base_path('pages/client/forgot-password.html')));
        $this->assertStringContainsString('await API.verifyStaffPasswordSetupToken(setupToken)', $script);
        $this->assertStringContainsString('accountEmail.textContent = response.email', $script);
        $this->assertStringContainsString('accountDescription.classList.remove("hidden")', $script);
        $this->assertStringContainsString('await API.completeStaffPasswordSetup(', $script);
        $this->assertStringContainsString('API.requestNewStaffPasswordSetupLink(setupToken)', $script);
        $this->assertStringContainsString('password.disabled = true', $script);
        $this->assertStringContainsString('confirmation.disabled = true', $script);
        $this->assertStringContainsString('window.location.replace("../admin/dashboard.html")', $script);
        $this->assertStringContainsString('submit.disabled = busy || validationError() !== ""', $script);
        $this->assertStringContainsString('"set-up-password.html"', $api);
        $this->assertStringContainsString('"/staff/password-setup/verify"', $api);
        $this->assertStringContainsString('"/staff/password-setup/complete"', $api);
        $this->assertStringContainsString('"/staff/password-setup/request-new-link"', $api);
    }
}
