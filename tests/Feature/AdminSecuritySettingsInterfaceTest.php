<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSecuritySettingsInterfaceTest extends TestCase
{
    public function test_security_tab_exposes_admin_controls_and_staff_account_details(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $security = $this->sourceBetween(
            $page,
            'x-show="activeSettingsTab === \'security\'"',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );

        foreach ([
            '>Admin account</h3>',
            '>Staff accounts</h3>',
            '>Add staff account</span>',
            '>Username</span>',
            '@click="openStaffDetails(staff)"',
            '>Staff account details</h3>',
            '>Staff name</dt>',
            '>Username</dt>',
            '>Email</dt>',
            'for="staffDetailsRole">Role</label>',
            '>Status</dt>',
            'x-text="staff.statusLabel"',
            "staff.active ? 'Deactivate' : 'Reactivate'",
            "chooseStaffType('clinic')",
            "chooseStaffType('grooming')",
            '@click="handleAddStaffClose()"',
            "? 'Back to account roles' : 'Close add staff account form'",
            "chooseClinicSubrole('veterinarian')",
            "chooseClinicSubrole('clinic_receptionist')",
            "addStaffModal.staffSubrole === 'veterinarian' ? 'Veterinarian' : 'Clinic Receptionist'",
            'x-model="addStaffModal.firstName" required',
            'x-model="addStaffModal.lastName" required',
            '>Veterinarian</span>',
            '>Clinic Receptionist</span>',
            '>Grooming Receptionist</span>',
            'x-model="addStaffModal.email" required',
            '>Account setup email sent</h3>',
            "A Set Up Your Password link was sent to the staff member's email address. Their account will become active after they complete setup.",
            'Update Changes',
            'x-for="staff in filteredStaffAccounts"',
            "staffAccounts.length === 0 ? 'No staff accounts yet' : 'No staff accounts in this category'",
            '>Security verification</p>',
            "x-text=\"isStaff ? 'Verify your identity' : securityVerification.purpose\"",
            '>6-digit security code</legend>',
            'x-for="(digit, index) in securityVerification.digits"',
            '@submit.prevent="confirmSecurityCredentialChange()"',
            'resendSecurityCredentialChangeCode()',
        ] as $control) {
            $this->assertStringContainsString($control, $security);
        }

        $this->assertStringNotContainsString('Admin Account Change Password', $security);
        $this->assertStringNotContainsString('Staff Account Change Password', $security);
        $adminSecurity = $this->sourceBetween(
            $security,
            'x-show="isAdmin" class="space-y-6"',
            'x-show="isStaff" class="space-y-6"',
        );

        $this->assertStringNotContainsString('Use at least 12 characters', $adminSecurity);
        $this->assertStringNotContainsString('type="search"', $security);
        $this->assertStringNotContainsString('staffSearch', $security);
        $this->assertStringNotContainsString('id="newStaffSubrole"', $security);
        $this->assertStringNotContainsString('>Sub-role</span>', $security);
        $this->assertStringNotContainsString('x-model="addStaffModal.password"', $security);
        $this->assertStringNotContainsString('x-model="addStaffModal.confirmation"', $security);
        $this->assertStringNotContainsString('newStaffUsername', $security);
        $this->assertStringNotContainsString('addStaffModal.createdUsername', $security);
        $this->assertStringNotContainsString('No username', $security);
        $this->assertStringContainsString('x-show="!staff.setupRequired && staff.username"', $security);
        $this->assertStringContainsString('x-show="!staffDetailsModal.staff?.setupRequired && staffDetailsModal.staff?.username"', $security);
        $this->assertStringNotContainsString('Change credentials', $security);
        $this->assertStringNotContainsString('resetStaffModal', $security);
        $this->assertStringNotContainsString('6-digit email verification code', $security);
        foreach ([
            'adminPassword.showCurrent',
            'adminPassword.showPassword',
            'adminPassword.showConfirmation',
            'staffStatusModal.showPassword',
            'staffDetailsModal.showPassword',
            'staffSetupModal.showPassword',
        ] as $visibility) {
            $this->assertStringContainsString(':type="'.$visibility.' ? \'text\' : \'password\'"', $adminSecurity);
            $this->assertSame(1, preg_match(
                '/<button\b[^>]*@click="'.preg_quote("{$visibility} = !{$visibility}", '/').'"[^>]*>(.*?)<\/button>/s',
                $adminSecurity,
                $toggle,
            ));
            $this->assertMatchesRegularExpression('/:aria-label="'.preg_quote($visibility, '/').' \? \'Hide[^\']*\' : \'Show[^\']*\'"/', $toggle[0]);
            foreach (['!'.$visibility => 'eye-slash', $visibility => 'eye'] as $condition => $icon) {
                $this->assertMatchesRegularExpression(
                    '/<svg\b[^>]*x-show="'.preg_quote($condition, '/').'"[^>]*>\s*<use\b[^>]*href="[^"#]*assets\/icons\/phosphor\.svg(?:\?[^"#]*)?#'.$icon.'"/',
                    $toggle[1],
                );
            }
        }
        preg_match('/src="([^\"]*admin-settings\.js\?[^\"]+)"/', $page, $script);
        $this->assertNotEmpty($script[1] ?? null);
        parse_str(parse_url($script[1], PHP_URL_QUERY), $scriptVersion);
        $this->assertSame('staff-settings-20260928', $scriptVersion['v']);
        $this->assertSame('clinic-merge-20261007', $scriptVersion['release']);
    }

    public function test_role_change_controls_stay_inside_the_staff_details_dialog(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $details = $this->sourceBetween($page, '<!-- Staff account details modal -->', 'x-show="staffSetupModal.open"');
        foreach ([
            'x-model="staffDetailsModal.selectedRole"',
            '@change="changeStaffRole()"',
            '<option value="veterinarian">Veterinarian</option>',
            '<option value="clinic_receptionist">Clinic Receptionist</option>',
            '<option value="grooming_receptionist">Grooming Receptionist</option>',
            'x-show="staffRoleChangePending"',
            'This will change this staff member’s system access.',
            'placeholder="Enter your password"',
            'Required to save this role change.',
            'role="alert"',
            '@click="cancelStaffRoleChange()"',
            '@click="saveStaffRoleChange()"',
            ':disabled="staffDetailsModal.busy || !staffDetailsModal.password"',
            'x-show="!staffRoleChangePending"',
        ] as $control) {
            $this->assertStringContainsString($control, $details);
        }
        $this->assertStringNotContainsString('>Clinic Staff</option>', $details);
        $this->assertStringNotContainsString('Change Role', $details);
        $this->assertSame(1, substr_count($details, 'role="dialog"'));
        $this->assertStringContainsString('#success-toast { z-index: 10002; }', $page);
    }

    public function test_security_component_calls_admin_credential_and_staff_account_apis(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $component = file_get_contents(base_path('scripts/components/admin-settings.js'));

        foreach ([
            'x-model="adminPassword.username"',
            '@click="submitAdminPassword()"',
            '@click="requestNewStaffAccount()"',
            '@click="openStaffDetails(staff)"',
            'x-show="staffDetailsModal.open"',
            'x-teleport="body"',
            'z-index: 10000',
            'role="dialog"',
            'aria-modal="true"',
        ] as $modalControl) {
            $this->assertStringContainsString($modalControl, $page);
        }

        foreach ([
            'staffAccounts: []',
            'get filteredStaffAccounts()',
            'loadSecurityAccounts()',
            'submitAdminPassword()',
            'openStaffDetails(staff)',
            'closeStaffDetails()',
            'handleAddStaffClose()',
            'API.getAdminSecurityAccounts()',
            'API.requestAdminCredentialChange({',
            'API.requestStaffAccount({',
            'staff_subrole: staffSubrole',
            'this.addStaffModal.step = "success"',
            'API.updateStaffAccountStatus(staff.id, targetActive, this.staffStatusModal.password)',
            '? API.confirmStaffPasswordChange',
            ': API.confirmSecurityCredentialChange',
            '? API.resendStaffPasswordChangeCode',
            ': API.resendSecurityCredentialChangeCode',
            'openSecurityVerification(response)',
            'password_setup_required',
            'statusLabel:',
            'password.length < 12',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $component);
        }

        $this->assertStringNotContainsString('staffSearch', $component);
        $this->assertStringNotContainsString('resetStaffModal', $component);
        $this->assertStringNotContainsString('requestStaffCredentialChange', $component);
        $this->assertStringNotContainsString('addStaffModal.username', $component);
        $this->assertStringNotContainsString('createdUsername', $component);
    }

    public function test_security_password_controls_are_not_presented_as_login_forms(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $signIn = file_get_contents(base_path('pages/client/sign-in.html'));
        $security = $this->sourceBetween(
            $page,
            'x-show="activeSettingsTab === \'security\'"',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );
        $adminSecurity = $this->sourceBetween(
            $security,
            'x-show="isAdmin" class="space-y-6"',
            'x-show="isStaff" class="space-y-6"',
        );

        $this->assertStringNotContainsString('autocomplete="current-password"', $adminSecurity);
        $this->assertStringNotContainsString('autocomplete="new-password"', $adminSecurity);
        $this->assertSame(10, substr_count($adminSecurity, 'data-lpignore="true"'));
        $this->assertSame(10, substr_count($adminSecurity, 'data-1p-ignore'));
        $this->assertSame(10, substr_count($adminSecurity, 'data-bwignore'));

        $this->assertStringContainsString('autocomplete="username"', $signIn);
        $this->assertStringContainsString('autocomplete="current-password"', $signIn);
    }

    public function test_staff_security_is_self_service_only_and_uses_verified_password_change(): void
    {
        $page = file_get_contents(base_path('pages/admin/settings.html'));
        $component = file_get_contents(base_path('scripts/components/admin-settings.js'));
        $securityTab = $this->sourceBetween(
            $page,
            'x-show="activeSettingsTab === \'security\'"',
            '<script defer src="https://cdn.jsdelivr.net/npm/alpinejs',
        );
        $security = $this->sourceBetween(
            $securityTab,
            'x-show="isStaff" class="space-y-6"',
            '<!-- Security code verification modal -->',
        );

        foreach ([
            '>Your Account</h3>',
            '>Full name</dt>',
            '>Staff role</dt>',
            '>Username</dt>',
            '>Email address</dt>',
            '>Account status</dt>',
            '>Change Password</h3>',
            '>Current Password</span>',
            '>New Password</span>',
            '>Confirm New Password</span>',
            '@submit.prevent="submitStaffPassword()"',
            'autocomplete="current-password"',
            'autocomplete="new-password"',
        ] as $staffControl) {
            $this->assertStringContainsString($staffControl, $security);
        }

        foreach ([
            'Admin account',
            'Staff accounts',
            'Add staff account',
            'openStaffStatusModal',
        ] as $adminControl) {
            $this->assertStringNotContainsString($adminControl, $security);
        }

        foreach ([
            'API.getSettingsSecurityAccount()',
            'API.requestStaffPasswordChange({',
            'API.confirmStaffPasswordChange',
            'API.resendStaffPasswordChangeCode',
            'Password updated successfully.',
        ] as $behavior) {
            $this->assertStringContainsString($behavior, $component);
        }

        $this->assertStringContainsString("'Verify your identity'", $page);
        $this->assertStringContainsString('We sent a 6-digit verification code to your registered email address.', $page);
        $resendControl = $this->sourceBetween(
            $securityTab,
            '@click="resendSecurityCredentialChangeCode()"',
            '</button>',
        );
        $this->assertStringContainsString('Resend code', $resendControl);
        $this->assertStringContainsString(':disabled="securityVerification.resending || securityVerification.submitting || securityVerification.resendRemaining > 0"', $resendControl);
    }

    public function test_old_email_link_confirmation_interface_has_been_removed(): void
    {
        $api = file_get_contents(base_path('scripts/api.js'));
        $routes = file_get_contents(base_path('routes/api.php'));

        $this->assertFileDoesNotExist(base_path('pages/security/confirm-credential-change.html'));
        $this->assertFileDoesNotExist(base_path('scripts/auth/confirm-credential-change.js'));
        $this->assertStringNotContainsString('previewCredentialChange', $api);
        $this->assertStringNotContainsString("'token' =>", $routes);
        $this->assertStringNotContainsString('credential-changes/preview', $routes);
        $this->assertStringNotContainsString('/admin/security/staff/{staff}/credential-change', $routes);
        $this->assertStringNotContainsString('requestStaffCredentialChange', $api);
    }

    private function sourceBetween(string $source, string $start, string $end): string
    {
        $startPosition = strpos($source, $start);
        $endPosition = strpos($source, $end, $startPosition ?: 0);

        $this->assertNotFalse($startPosition, "Missing source marker: {$start}");
        $this->assertNotFalse($endPosition, "Missing source marker: {$end}");

        return substr($source, $startPosition, $endPosition - $startPosition);
    }
}
