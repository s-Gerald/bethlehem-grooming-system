# Email entry validation

The shared policy is `resources/data/account-email-policy.json`. It lists approved provider domains, explicit operational exception domains, typo corrections, and entry limits. The exception list defaults to empty; add only deliberately approved domains there. There is no customer-facing bypass.

`config/account_email.php`, `app/Support/AccountEmail.php`, and `app/Rules/ValidAccountEmail.php` enforce the backend policy. `scripts/build-account-email-policy.mjs` embeds the same configuration into `scripts/utils/account-email.js`; the browser does not fetch policy data or call a validation API.

New or changed emails require an ASCII local part of 3–64 octets, valid mailbox syntax, and an approved domain. Plus aliases and numeric usernames are allowed. Normalization trims surrounding whitespace and lowercases the domain while preserving the local part. Account/contact entry keeps the 150-character maximum. Walk-in entry and its existing owner-validation endpoint support the walk-in snapshot column's 255-character maximum.

The existing signup, customer creation, shared grooming/clinic owner step, staff creation, setup correction, staff email change, and admin-assisted staff recovery fields use the shared frontend utility. It validates on blur/submission, clears errors and red styling on input, provides explicit typo correction, and ignores stale email errors. Forms retain their current design and field-level messages.

Existing sign-in, recovery, password reset and verification/resend rules remain compatible with legacy emails. Unchanged pending registration emails and selected existing walk-in owners are not subjected to the new provider/length entry policy. Read-only email fields remain read-only. Signup and staff-account verification/security requirements remain intact; customer contact entry sends no OTP. A typed clinic contact email cannot claim an existing registered account; staff must use the existing customer selection flow.

## Deployment

- Use the existing GitHub-to-Hostinger process. No migration, new service, dependency, environment variable or API key is required.
- Run `npm run build`; this now also runs `npm run build:email`. Deploy the generated `scripts/utils/account-email.js`, current entry scripts/pages, CSS, JSON policy, and Laravel files together.
- Refresh Laravel's config cache through the existing deployment process (`php artisan config:cache`) so the new configuration is available. The application configuration cache must not remain from the old release.
- Updated HTML asset query versions invalidate the changed entry scripts and stylesheet. If the policy or shared utility changes in a later release, rebuild it and update its asset query version as usual.
- Configure any approved operational exception domains before rebuilding and deploying; changing Laravel configuration alone does not regenerate the browser policy.

## Verification

- PHP 8.4: 592 relevant PHPUnit tests, 6,787 assertions; 591 passed and one MySQL opt-in test skipped. Suites: AccountEmailTest, EmailVerificationTest, AdminUnregisteredCustomerTest, AdminStaffAccountManagementTest, AdminCredentialChangeTest, PasswordResetTest, PhoneVerificationTest, ClinicAdministrationAuthorizationTest.
- Full frontend run: `npm test` passed 81/84 regression scripts. The three failures are existing sidebar fixture issues: `admin-universal-loading-regression.cjs` lacks `location.origin`; `clinic-subrole-workflow-regression.cjs` and `veterinary-workspace-regression.cjs` lack `URL`. All three failures were reproduced using original HEAD script sources; unrelated files were left unchanged.
- Focused shared-email, signup, staff-settings and walk-in-owner regressions pass, including suggestion application, error dismissal/revalidation and stale email responses. Existing loading and grooming-settings checks also pass.
- Production build passes. Its existing Browserslist database warning is non-blocking.
- Browser inspection used only the already signed-in admin dashboard. Manual navigation, data entry, OTP submissions and password resets are reserved for the user.

No changes were committed, pushed or deployed.
